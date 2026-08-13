<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('grow4me-connector.outbox.connection'))
            ->create(config('grow4me-connector.outbox.table'), function (Blueprint $table): void {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->string('resource');
                $table->string('operation', 16);
                $table->string('external_id');
                $table->timestampTz('occurred_at');
                $table->timestampTz('available_at')->nullable();
                $table->timestampTz('claimed_at')->nullable();
                $table->uuid('claim_token')->nullable()->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestampTz('delivered_at')->nullable();
                $table->timestampTz('dead_lettered_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestampsTz();

                $table->index(
                    ['delivered_at', 'dead_lettered_at', 'available_at'],
                    'grow4me_outbox_pending_index',
                );
                $table->index(
                    ['resource', 'external_id', 'id'],
                    'grow4me_outbox_record_index',
                );
            });
    }

    public function down(): void
    {
        Schema::connection(config('grow4me-connector.outbox.connection'))
            ->dropIfExists(config('grow4me-connector.outbox.table'));
    }
};
