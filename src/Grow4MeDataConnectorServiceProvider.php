<?php

namespace ParsDesign\Grow4MeDataConnector;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use ParsDesign\Grow4MeDataConnector\Console\Commands\DeliverOutboxCommand;
use ParsDesign\Grow4MeDataConnector\Console\Commands\DoctorCommand;
use ParsDesign\Grow4MeDataConnector\Console\Commands\ExportResourceCommand;
use ParsDesign\Grow4MeDataConnector\Console\Commands\InstallCommand;
use ParsDesign\Grow4MeDataConnector\Console\Commands\MakeResourceCommand;
use ParsDesign\Grow4MeDataConnector\Console\Commands\RedriveOutboxCommand;
use ParsDesign\Grow4MeDataConnector\Observers\ResourceOutboxObserver;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;
use ParsDesign\Grow4MeDataConnector\Support\SignedCursor;

class Grow4MeDataConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/grow4me-connector.php',
            'grow4me-connector',
        );

        $this->app->singleton(ResourceRegistry::class);
        $this->app->singleton(SignedCursor::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->booted(function (): void {
            $observer = $this->app->make(ResourceOutboxObserver::class);
            $modelClasses = collect($this->app->make(ResourceRegistry::class)->all())
                ->map(fn ($resource): string => $resource->modelClass())
                ->merge(collect($this->app->make(ResourceRegistry::class)->dependencies())
                    ->map(fn ($resolver): string => $resolver->modelClass()))
                ->unique();

            foreach ($modelClasses as $modelClass) {
                $modelClass::observe($observer);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                DeliverOutboxCommand::class,
                DoctorCommand::class,
                ExportResourceCommand::class,
                InstallCommand::class,
                MakeResourceCommand::class,
                RedriveOutboxCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/grow4me-connector.php' => config_path('grow4me-connector.php'),
            ], 'grow4me-connector-config');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('grow4me-connector.outbox.schedule_delivery', true)) {
                return;
            }

            $schedule->command('grow4me:deliver-outbox')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }
}
