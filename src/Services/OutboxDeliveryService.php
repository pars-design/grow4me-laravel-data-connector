<?php

namespace ParsDesign\Grow4MeDataConnector\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;
use RuntimeException;
use Throwable;

class OutboxDeliveryService
{
    public function deliverPending(int $limit = 100): int
    {
        $delivered = 0;

        foreach ($this->claim(min(max($limit, 1), 500)) as $event) {
            if ($this->deliver($event)) {
                $delivered++;
            }
        }

        return $delivered;
    }

    /**
     * @return Collection<int, OutboxEvent>
     */
    private function claim(int $limit): Collection
    {
        $connection = config('grow4me-connector.outbox.connection');

        return DB::connection($connection)->transaction(function () use ($limit): Collection {
            $staleBefore = now('UTC')->subSeconds(
                (int) config('grow4me-connector.outbox.claim_ttl_seconds', 300),
            );

            OutboxEvent::query()
                ->whereNull('delivered_at')
                ->whereNull('dead_lettered_at')
                ->where('claimed_at', '<', $staleBefore)
                ->update([
                    'claimed_at' => null,
                    'claim_token' => null,
                ]);

            $eventIds = OutboxEvent::query()
                ->whereNull('delivered_at')
                ->whereNull('dead_lettered_at')
                ->whereNull('claimed_at')
                ->where(function ($query): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', now('UTC'));
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($eventIds->isEmpty()) {
                return collect();
            }

            $claimToken = (string) Str::uuid();

            OutboxEvent::query()
                ->whereIn('id', $eventIds)
                ->whereNull('claimed_at')
                ->update([
                    'claimed_at' => now('UTC'),
                    'claim_token' => $claimToken,
                ]);

            return OutboxEvent::query()
                ->where('claim_token', $claimToken)
                ->orderBy('id')
                ->get();
        });
    }

    private function deliver(OutboxEvent $event): bool
    {
        $event->forceFill(['attempts' => $event->attempts + 1])->saveQuietly();

        try {
            $response = $this->send($event);

            if ($response->successful() || $response->status() === 409) {
                $event->forceFill([
                    'delivered_at' => now('UTC'),
                    'claimed_at' => null,
                    'claim_token' => null,
                    'last_error' => null,
                ])->saveQuietly();

                return true;
            }

            $this->markFailed($event, sprintf(
                'Webhook returned HTTP %d: %s',
                $response->status(),
                Str::limit($response->body(), 1000),
            ));
        } catch (Throwable $exception) {
            $this->markFailed($event, Str::limit($exception->getMessage(), 1000));
        }

        return false;
    }

    /**
     * @throws ConnectionException
     * @throws JsonException
     */
    private function send(OutboxEvent $event): Response
    {
        $url = config('grow4me-connector.webhook.url');
        $secret = config('grow4me-connector.webhook.secret');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('GROW4ME_CONNECTOR_WEBHOOK_URL must be a valid HTTPS URL.');
        }

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('GROW4ME_CONNECTOR_WEBHOOK_SECRET must contain at least 32 characters.');
        }

        $body = json_encode([
            'event_id' => $event->event_id,
            'binding' => $event->resource,
            'operation' => $event->operation,
            'external_id' => $event->external_id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now('UTC')->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return Http::acceptJson()
            ->contentType('application/json')
            ->connectTimeout((int) config('grow4me-connector.webhook.connect_timeout', 5))
            ->timeout((int) config('grow4me-connector.webhook.timeout', 10))
            ->withoutRedirecting()
            ->withHeaders([
                'X-Grow4Me-Timestamp' => $timestamp,
                'X-Grow4Me-Signature' => 'sha256='.$signature,
            ])
            ->withBody($body, 'application/json')
            ->post($url);
    }

    private function markFailed(OutboxEvent $event, string $error): void
    {
        $maxAttempts = max((int) config('grow4me-connector.webhook.max_attempts', 8), 1);
        $deadLetteredAt = $event->attempts >= $maxAttempts ? now('UTC') : null;
        $delaySeconds = min(60 * (2 ** max($event->attempts - 1, 0)), 86400);

        $event->forceFill([
            'available_at' => $deadLetteredAt === null ? now('UTC')->addSeconds($delaySeconds) : null,
            'claimed_at' => null,
            'claim_token' => null,
            'dead_lettered_at' => $deadLetteredAt,
            'last_error' => $error,
        ])->saveQuietly();
    }
}
