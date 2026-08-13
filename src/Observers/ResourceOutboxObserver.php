<?php

namespace ParsDesign\Grow4MeDataConnector\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Services\OutboxRecorder;

class ResourceOutboxObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly OutboxRecorder $recorder) {}

    public function saved(Model $model): void
    {
        $this->recorder->record($model, 'upsert');
    }

    public function deleted(Model $model): void
    {
        $this->recorder->record($model, 'delete');
    }

    public function restored(Model $model): void
    {
        $this->recorder->record($model, 'upsert');
    }
}
