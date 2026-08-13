<?php

use Illuminate\Support\Carbon;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\Product;

use function Pest\Laravel\getJson;

it('protects sync resources with a dedicated bearer token', function (): void {
    getJson('/api/v1/sync/products')->assertUnauthorized();

    getJson('/api/v1/sync/products', [
        'Authorization' => 'Bearer wrong-token',
    ])->assertUnauthorized();
});

it('returns versioned envelopes with a signed cursor and at most one hundred records', function (): void {
    Product::query()->insert(collect(range(1, 101))->map(fn (int $number): array => [
        'name' => "Product {$number}",
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ])->all());

    $firstPage = getJson('/api/v1/sync/products?limit=500', [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertJsonStructure([
            'snapshot_at',
            'cursor',
            'records' => [[
                'contract_version',
                'external_id',
                'active',
                'source_updated_at',
                'payload',
            ]],
        ])
        ->assertJsonCount(100, 'records');

    expect(array_keys($firstPage->json('records.0')))->toBe([
        'contract_version',
        'external_id',
        'active',
        'source_updated_at',
        'payload',
    ]);

    $cursor = $firstPage->json('cursor');

    getJson('/api/v1/sync/products?cursor='.urlencode($cursor), [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertJsonCount(1, 'records')
        ->assertJsonPath('cursor', null);
});

it('returns a single current record or an inactive tombstone envelope', function (): void {
    Carbon::setTestNow('2026-08-14 10:00:00');

    $product = Product::query()->create([
        'name' => 'Leasing Product',
        'active' => true,
    ]);

    getJson("/api/v1/sync/products/{$product->getKey()}", [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertExactJson([
            'contract_version' => 'v1',
            'external_id' => (string) $product->getKey(),
            'active' => true,
            'source_updated_at' => '2026-08-14T10:00:00+00:00',
            'payload' => ['name' => 'Leasing Product'],
        ]);

    $product->update(['active' => false]);

    getJson("/api/v1/sync/products/{$product->getKey()}", [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertJsonPath('active', false)
        ->assertJsonPath('payload.name', 'Leasing Product');
});

it('rejects cursors that were modified or belong to another resource', function (): void {
    Product::query()->insert(collect(range(1, 101))->map(fn (int $number): array => [
        'name' => "Product {$number}",
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ])->all());

    $cursor = getJson('/api/v1/sync/products', [
        'Authorization' => 'Bearer test-token',
    ])->json('cursor');

    getJson('/api/v1/sync/products?cursor='.urlencode($cursor.'tampered'), [
        'Authorization' => 'Bearer test-token',
    ])->assertUnprocessable();
});
