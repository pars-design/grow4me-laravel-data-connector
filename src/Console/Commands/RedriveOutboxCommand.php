<?php

namespace ParsDesign\Grow4MeDataConnector\Console\Commands;

use Illuminate\Console\Command;
use ParsDesign\Grow4MeDataConnector\Models\OutboxEvent;

class RedriveOutboxCommand extends Command
{
    protected $signature = 'grow4me:redrive-outbox
        {event_id? : One dead-letter event UUID}
        {--all : Redrive every dead-letter event}';

    protected $description = 'Return dead-letter Grow4Me events to the pending outbox';

    public function handle(): int
    {
        $eventId = $this->argument('event_id');

        if (! is_string($eventId) && ! $this->option('all')) {
            $this->error('Provide an event_id or pass --all.');

            return self::INVALID;
        }

        $query = OutboxEvent::query()->whereNotNull('dead_lettered_at');

        if (is_string($eventId)) {
            $query->where('event_id', $eventId);
        }

        $count = $query->update([
            'attempts' => 0,
            'available_at' => now('UTC'),
            'claimed_at' => null,
            'claim_token' => null,
            'dead_lettered_at' => null,
            'last_error' => null,
            'updated_at' => now('UTC'),
        ]);

        $this->info("{$count} event returned to the pending outbox.");

        return self::SUCCESS;
    }
}
