<?php

namespace ParsDesign\Grow4MeDataConnector\Models;

use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Casts\UtcImmutableDateTime;

class OutboxEvent extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('grow4me-connector.outbox.table', 'grow4me_connector_outbox_events');
    }

    public function getConnectionName(): ?string
    {
        return config('grow4me-connector.outbox.connection') ?: parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => UtcImmutableDateTime::class,
            'available_at' => UtcImmutableDateTime::class,
            'claimed_at' => UtcImmutableDateTime::class,
            'delivered_at' => UtcImmutableDateTime::class,
            'dead_lettered_at' => UtcImmutableDateTime::class,
            'attempts' => 'integer',
        ];
    }
}
