<?php

namespace ParsDesign\Grow4MeDataConnector\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;

class OutboxRecorder
{
    public function __construct(private readonly ResourceRegistry $resources) {}

    public function record(Model $model, string $operation): void
    {
        foreach ($this->resources->all() as $resource) {
            if (! $model instanceof ($resource->modelClass())) {
                continue;
            }

            OutboxEvent::query()->create([
                'event_id' => (string) Str::uuid(),
                'resource' => $resource->key(),
                'operation' => $operation,
                'external_id' => $resource->externalId($model),
                'occurred_at' => now('UTC'),
                'available_at' => now('UTC'),
            ]);
        }
    }
}
