<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\Product;

use function Pest\Laravel\artisan;

it('runs a secret-safe connector health check', function (): void {
    artisan('grow4me:doctor')
        ->expectsOutputToContain('Connector token')
        ->expectsOutputToContain('Outbox table')
        ->doesntExpectOutput(config('grow4me-connector.token'))
        ->doesntExpectOutput(config('grow4me-connector.webhook.secret'))
        ->assertSuccessful();

    config()->set('grow4me-connector.cursor_secret', null);

    artisan('grow4me:doctor')
        ->expectsOutputToContain('Cursor secret')
        ->assertFailed();
});

it('delivers pending events from the artisan command', function (): void {
    Http::fake(['*' => Http::response(['status' => 'accepted'], 202)]);

    Product::query()->create([
        'name' => 'Command Product',
        'active' => true,
    ]);

    artisan('grow4me:deliver-outbox', ['--limit' => 10])
        ->expectsOutputToContain('1 event delivered')
        ->assertSuccessful();

    expect(OutboxEvent::query()->sole()->delivered_at)->not->toBeNull();
});

it('redrives one dead letter event without changing another one', function (): void {
    $first = OutboxEvent::query()->create([
        'event_id' => fake()->uuid(),
        'resource' => 'products',
        'operation' => 'upsert',
        'external_id' => 'first',
        'occurred_at' => now(),
        'attempts' => 8,
        'dead_lettered_at' => now(),
        'last_error' => 'failed',
    ]);
    $second = OutboxEvent::query()->create([
        'event_id' => fake()->uuid(),
        'resource' => 'products',
        'operation' => 'upsert',
        'external_id' => 'second',
        'occurred_at' => now(),
        'attempts' => 8,
        'dead_lettered_at' => now(),
        'last_error' => 'failed',
    ]);

    artisan('grow4me:redrive-outbox', ['event_id' => $first->event_id])
        ->expectsOutputToContain('1 event returned')
        ->assertSuccessful();

    expect($first->refresh()->dead_lettered_at)->toBeNull()
        ->and($first->attempts)->toBe(0)
        ->and($second->refresh()->dead_lettered_at)->not->toBeNull();
});

it('registers the outbox delivery schedule every minute', function (): void {
    $events = app(Schedule::class)->events();
    $delivery = collect($events)->first(
        fn ($event): bool => str_contains($event->command ?? '', 'grow4me:deliver-outbox'),
    );

    expect($delivery)->not->toBeNull()
        ->and($delivery->expression)->toBe('* * * * *');
});

it('scaffolds a resource adapter without overwriting it by default', function (): void {
    $directory = sys_get_temp_dir().'/grow4me-resource-'.fake()->uuid();
    $path = $directory.'/CatalogProductAdapter.php';

    try {
        artisan('make:grow4me-resource', [
            'name' => 'CatalogProductAdapter',
            '--model' => Product::class,
            '--key' => 'products',
            '--namespace' => 'App\\Grow4Me\\Resources',
            '--path' => $directory,
        ])->assertSuccessful();

        expect(File::get($path))->toContain(
            'namespace App\\Grow4Me\\Resources;',
            "return 'products';",
            'return Product::class;',
        );

        artisan('make:grow4me-resource', [
            'name' => 'CatalogProductAdapter',
            '--model' => Product::class,
            '--key' => 'products',
            '--path' => $directory,
        ])->assertFailed();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects unsafe scaffold resource keys', function (): void {
    artisan('make:grow4me-resource', [
        'name' => 'UnsafeAdapter',
        '--model' => Product::class,
        '--key' => '../products',
    ])->assertExitCode(2);
});
