<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;
use ParsDesign\Grow4MeDataConnector\Services\OutboxDeliveryService;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\Product;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;

it('records model changes only after their database transaction commits', function (): void {
    DB::beginTransaction();

    $product = Product::query()->create([
        'name' => 'Committed Product',
        'active' => true,
    ]);

    assertDatabaseCount('grow4me_connector_outbox_events', 0);

    DB::commit();

    assertDatabaseHas('grow4me_connector_outbox_events', [
        'resource' => 'products',
        'operation' => 'upsert',
        'external_id' => (string) $product->getKey(),
        'attempts' => 0,
    ]);
});

it('does not record changes from rolled back transactions', function (): void {
    DB::beginTransaction();

    Product::query()->create([
        'name' => 'Rolled Back Product',
        'active' => true,
    ]);

    DB::rollBack();

    assertDatabaseCount('grow4me_connector_outbox_events', 0);
});

it('delivers signed idempotent events and marks duplicate responses as delivered', function (): void {
    Http::fake([
        '*' => Http::response(['status' => 'duplicate'], 409),
    ]);

    $product = Product::query()->create([
        'name' => 'Webhook Product',
        'active' => true,
    ]);

    $delivered = app(OutboxDeliveryService::class)->deliverPending();

    expect($delivered)->toBe(1);

    $event = OutboxEvent::query()->sole();

    expect($event->delivered_at)->not->toBeNull()
        ->and($event->attempts)->toBe(1)
        ->and($event->dead_lettered_at)->toBeNull();

    Http::assertSent(function ($request) use ($event, $product): bool {
        $timestamp = $request->header('X-Grow4Me-Timestamp')[0] ?? '';
        $signature = $request->header('X-Grow4Me-Signature')[0] ?? '';
        $body = $request->body();

        return $request->url() === config('grow4me-connector.webhook.url')
            && $request->method() === 'POST'
            && $signature === 'sha256='.hash_hmac(
                'sha256',
                $timestamp.'.'.$body,
                config('grow4me-connector.webhook.secret'),
            )
            && json_decode($body, true) === [
                'event_id' => $event->event_id,
                'binding' => 'products',
                'operation' => 'upsert',
                'external_id' => (string) $product->getKey(),
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ];
    });
});

it('retries failed events and moves them to dead letter at the configured limit', function (): void {
    config()->set('grow4me-connector.webhook.max_attempts', 2);
    Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

    Product::query()->create([
        'name' => 'Retry Product',
        'active' => true,
    ]);

    $delivery = app(OutboxDeliveryService::class);
    $delivery->deliverPending();

    $event = OutboxEvent::query()->sole();

    expect($event->attempts)->toBe(1)
        ->and($event->available_at->isAfter(now()))->toBeTrue()
        ->and($event->dead_lettered_at)->toBeNull();

    $event->forceFill(['available_at' => now()->subSecond()])->saveQuietly();
    $delivery->deliverPending();

    expect($event->refresh()->attempts)->toBe(2)
        ->and($event->dead_lettered_at)->not->toBeNull()
        ->and($event->last_error)->toContain('503');
});

it('returns a tombstone for a record that was physically deleted', function (): void {
    $product = Product::query()->create([
        'name' => 'Deleted Product',
        'active' => true,
    ]);
    $externalId = (string) $product->getKey();

    $product->delete();

    getJson("/api/v1/sync/products/{$externalId}", [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()
        ->assertExactJson([
            'contract_version' => 'v1',
            'external_id' => $externalId,
            'active' => false,
            'source_updated_at' => OutboxEvent::query()
                ->where('operation', 'delete')
                ->sole()
                ->occurred_at
                ->toIso8601String(),
            'payload' => [],
        ]);
});

it('reads outbox timestamps as utc when the host php timezone differs', function (): void {
    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Istanbul');

    try {
        $occurredAt = CarbonImmutable::parse('2026-08-14T10:15:30Z');
        $event = OutboxEvent::query()->create([
            'event_id' => 'fdbbd962-bc97-4d04-9f91-77efdc8ac0f0',
            'resource' => 'products',
            'operation' => 'upsert',
            'external_id' => 'utc-test',
            'occurred_at' => $occurredAt,
            'available_at' => $occurredAt->addMinute(),
        ])->refresh();

        expect($event->occurred_at->toIso8601String())->toBe('2026-08-14T10:15:30+00:00')
            ->and($event->available_at->toIso8601String())->toBe('2026-08-14T10:16:30+00:00');
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});
