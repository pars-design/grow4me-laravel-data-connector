<?php

use Illuminate\Support\Facades\Route;
use ParsDesign\Grow4MeDataConnector\Http\Controllers\SyncFeedController;
use ParsDesign\Grow4MeDataConnector\Http\Middleware\AuthenticateConnector;

if (config('grow4me-connector.enabled')) {
    Route::prefix(config('grow4me-connector.route_prefix', 'api/v1/sync'))
        ->middleware(AuthenticateConnector::class)
        ->group(function (): void {
            Route::get('{resource}', [SyncFeedController::class, 'index'])
                ->name('grow4me-connector.feed.index');
            Route::get('{resource}/{externalId}', [SyncFeedController::class, 'show'])
                ->where('externalId', '.*')
                ->name('grow4me-connector.feed.show');
        });
}
