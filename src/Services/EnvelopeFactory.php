<?php

namespace ParsDesign\Grow4MeDataConnector\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;

class EnvelopeFactory
{
    public function __construct(private readonly PayloadValidator $validator) {}

    /**
     * @param  EloquentCollection<int, Model>  $models
     * @return list<array{contract_version: string, external_id: string, active: bool, source_updated_at: string, payload: array<string, mixed>}>
     */
    public function forModels(ResourceAdapter $adapter, EloquentCollection $models): array
    {
        $externalIds = $models->map(fn (Model $model): string => $adapter->externalId($model));
        $eventTimes = OutboxEvent::query()
            ->where('resource', $adapter->key())
            ->whereIn('external_id', $externalIds)
            ->selectRaw('external_id, MAX(occurred_at) as last_event_at')
            ->groupBy('external_id')
            ->pluck('last_event_at', 'external_id');

        return $models
            ->map(fn (Model $model): array => $this->forModel(
                $adapter,
                $model,
                $eventTimes->get($adapter->externalId($model)),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{contract_version: string, external_id: string, active: bool, source_updated_at: string, payload: array<string, mixed>}
     */
    public function forModel(ResourceAdapter $adapter, Model $model, mixed $lastEventAt = null): array
    {
        $active = $adapter->isActive($model);
        $payload = $adapter->payload($model);

        if ($active) {
            $this->validator->validate($adapter->key(), $adapter->contractVersion(), $payload);
        }

        $updatedAt = $adapter->sourceUpdatedAt($model)->toImmutable()->utc();

        if (is_string($lastEventAt) && $lastEventAt !== '') {
            $eventAt = CarbonImmutable::parse($lastEventAt, 'UTC')->utc();

            if ($eventAt->greaterThan($updatedAt)) {
                $updatedAt = $eventAt;
            }
        }

        return [
            'contract_version' => $adapter->contractVersion(),
            'external_id' => $adapter->externalId($model),
            'active' => $active,
            'source_updated_at' => $updatedAt->toIso8601String(),
            'payload' => $payload,
        ];
    }
}
