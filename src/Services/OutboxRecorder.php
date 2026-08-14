<?php

namespace ParsDesign\Grow4MeDataConnector\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ParsDesign\Grow4MeDataConnector\Data\ResourceChange;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;

class OutboxRecorder
{
    public function __construct(private readonly ResourceRegistry $resources) {}

    public function record(Model $model, string $operation): void
    {
        $changes = collect();

        foreach ($this->resources->all() as $resource) {
            if (! $model instanceof ($resource->modelClass())) {
                continue;
            }

            $changes->push(new ResourceChange(
                $resource->key(),
                $resource->externalId($model),
                $operation,
            ));
        }

        foreach ($this->resources->dependencies() as $resolver) {
            if (! $model instanceof ($resolver->modelClass())) {
                continue;
            }

            foreach ($resolver->changes($model, $operation) as $change) {
                $changes->push($change);
            }
        }

        foreach ($changes->unique(fn (ResourceChange $change): string => implode('|', [
            $change->resource,
            $change->externalId,
            $change->operation,
        ])) as $change) {
            OutboxEvent::query()->create([
                'event_id' => (string) Str::uuid(),
                'resource' => $change->resource,
                'operation' => $change->operation,
                'external_id' => $change->externalId,
                'occurred_at' => now('UTC'),
                'available_at' => now('UTC'),
            ]);
        }
    }
}
