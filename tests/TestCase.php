<?php

namespace ParsDesign\Grow4MeDataConnector\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ParsDesign\Grow4MeDataConnector\Grow4MeDataConnectorServiceProvider;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\CategoryDependencyResolver;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\ProductResourceAdapter;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [Grow4MeDataConnectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('grow4me-connector.token', 'test-token');
        $app['config']->set('grow4me-connector.cursor_secret', str_repeat('c', 64));
        $app['config']->set('grow4me-connector.resources', [ProductResourceAdapter::class]);
        $app['config']->set('grow4me-connector.dependencies', [CategoryDependencyResolver::class]);
        $app['config']->set('grow4me-connector.webhook.url', 'https://grow4me.example/api/v1/sync/sources/source-id/events');
        $app['config']->set('grow4me-connector.webhook.secret', str_repeat('w', 64));
    }

    protected function defineDatabaseMigrations(): void
    {
        (require __DIR__.'/../database/migrations/2026_08_14_000000_create_grow4me_connector_outbox_events_table.php')->up();

        Schema::create('connector_test_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('external_code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('connector_test_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
