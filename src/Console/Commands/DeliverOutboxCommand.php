<?php

namespace ParsDesign\Grow4MeDataConnector\Console\Commands;

use Illuminate\Console\Command;
use ParsDesign\Grow4MeDataConnector\Services\OutboxDeliveryService;

class DeliverOutboxCommand extends Command
{
    protected $signature = 'grow4me:deliver-outbox {--limit=100 : Maximum events to claim}';

    protected $description = 'Deliver pending Grow4Me webhook events';

    public function handle(OutboxDeliveryService $delivery): int
    {
        $delivered = $delivery->deliverPending((int) $this->option('limit'));

        $this->info("{$delivered} event delivered.");

        return self::SUCCESS;
    }
}
