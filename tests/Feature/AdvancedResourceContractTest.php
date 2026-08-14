<?php

use Illuminate\Support\Carbon;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\Category;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\ExternalCodeProductResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\Product;

use function Pest\Laravel\getJson;

it('keeps cursor ordering independent from a custom external id', function (): void {
    Product::query()->insert(collect(range(1, 101))->map(fn (int $number): array => [
        'name' => "Product {$number}",
        'external_code' => sprintf('SKU-%03d', 102 - $number),
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ])->all());
    config()->set('grow4me-connector.resources', [ExternalCodeProductResourceAdapter::class]);
    app()->forgetInstance(ResourceRegistry::class);

    $first = getJson('/api/v1/sync/products', [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()->assertJsonCount(100, 'records');

    getJson('/api/v1/sync/products?cursor='.urlencode($first->json('cursor')), [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertJsonCount(1, 'records')
        ->assertJsonPath('records.0.external_id', 'SKU-001');
});

it('builds an eloquent resource from a serializable declarative definition', function (): void {
    $product = Product::query()->create([
        'name' => 'Configured Product',
        'description' => '<p>Portable mapping</p>',
        'active' => true,
    ]);
    config()->set('grow4me-connector.resources', [[
        'key' => 'configured-products',
        'contract_version' => 'catalog.product.v1',
        'model' => Product::class,
        'external_id' => 'id',
        'cursor' => 'id',
        'source_updated_at' => 'updated_at',
        'active' => 'active',
        'fields' => [
            'name' => 'name',
            'description' => 'description',
            'stock' => 'active',
        ],
        'export' => [
            'name' => ['label' => 'Name', 'source' => 'name'],
        ],
    ]]);
    app()->forgetInstance(ResourceRegistry::class);

    getJson("/api/v1/sync/configured-products/{$product->getKey()}", [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertJsonPath('contract_version', 'catalog.product.v1')
        ->assertJsonPath('payload.name', 'Configured Product')
        ->assertJsonPath('payload.description', '<p>Portable mapping</p>')
        ->assertJsonPath('payload.stock', true);
});

it('advances a resource timestamp when a configured dependency changes', function (): void {
    Carbon::setTestNow('2026-08-14 10:00:00');
    $category = Category::query()->create(['name' => 'Initial']);
    $product = Product::query()->create([
        'name' => 'Dependent Product',
        'category_id' => $category->getKey(),
        'active' => true,
    ]);
    OutboxEvent::query()->delete();

    Carbon::setTestNow('2026-08-14 11:00:00');
    $category->update(['name' => 'Updated']);

    expect(OutboxEvent::query()->sole()->only(['resource', 'operation', 'external_id']))->toBe([
        'resource' => 'products',
        'operation' => 'upsert',
        'external_id' => (string) $product->getKey(),
    ]);

    getJson("/api/v1/sync/products/{$product->getKey()}", [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertJsonPath('source_updated_at', '2026-08-14T11:00:00+00:00');
});
