# Grow4Me Laravel Data Connector

Expose Eloquent-backed products and custom records through Grow4Me's canonical,
versioned pull-feed contract. The package provides bearer-protected feeds,
signed cursors, after-commit outbox events, HMAC webhook delivery, retries, and
dead-letter redrive without knowing the host application's product schema.

## Requirements

- PHP 8.2–8.5
- Laravel 12 or 13
- A queue/scheduler-capable Laravel application

## Installation

```bash
composer require pars-design/grow4me-laravel-data-connector
php artisan grow4me:install
php artisan migrate
```

Configure secrets in the host application's environment. Generate and manage
these values in your secret manager or deployment platform; the package does
not create or print them.

```dotenv
GROW4ME_CONNECTOR_TOKEN=
GROW4ME_CONNECTOR_CURSOR_SECRET=
GROW4ME_CONNECTOR_WEBHOOK_URL=https://grow4me.example/api/v1/sync/sources/{public-id}/events
GROW4ME_CONNECTOR_WEBHOOK_SECRET=
```

The feed token and cursor/webhook secrets should be independent values. Cursor
and webhook secrets must contain at least 32 characters.

## Create an adapter

```bash
php artisan make:grow4me-resource ProductResourceAdapter \
  --model='App\Models\Product' \
  --key=products \
  --contract=catalog.product.v1 \
  --field=name=name \
  --field=description=description \
  --field=category=category.name \
  --export=sku=SKU \
  --export=name=Name \
  --with=category
```

An adapter translates a host model into the stable Grow4Me envelope. It may
eager-load any application-specific relationships and can use soft-deleted rows
to expose tombstones.

```php
<?php

namespace App\Grow4Me\Resources;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ExportableResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Resources\EloquentResourceAdapter;

final class ProductResourceAdapter extends EloquentResourceAdapter implements ExportableResourceAdapter
{
    public function key(): string
    {
        return 'products';
    }

    public function contractVersion(): string
    {
        return 'catalog.product.v1';
    }

    public function modelClass(): string
    {
        return Product::class;
    }

    public function isActive(Model $model): bool
    {
        return $model->is_active;
    }

    public function payload(Model $model): array
    {
        return [
            'name' => $model->name,
            'description' => $model->description,
            'price' => $model->price,
            'currency' => $model->currency,
            'images' => $model->images ?? [],
            'detail_url' => $model->url,
            'brand' => $model->brand,
            'category' => $model->category,
            'sku' => $model->sku,
            'attributes' => $model->public_attributes ?? [],
        ];
    }

    public function exportColumns(): array
    {
        return [
            'sku' => 'SKU',
            'name' => 'Name',
        ];
    }

    public function exportRow(Model $model): array
    {
        return [
            'sku' => $model->sku,
            'name' => $model->name,
        ];
    }
}
```

Register adapters in `config/grow4me-connector.php`:

```php
'resources' => [
    App\Grow4Me\Resources\ProductResourceAdapter::class,
    App\Grow4Me\Resources\SupplierResourceAdapter::class,
],
```

Multiple resource adapters may map completely different host schemas. Resource
keys must be lowercase and URL-safe. A model mutation is recorded for every
registered adapter that owns that model.

For simple schemas, register a serializable declarative mapping instead of a
PHP adapter. For transformers, custom queries, dependent models, and complete
examples, see [Resource mapping](docs/resource-mapping.md).

## CSV and XML export

Resources that explicitly implement `ExportableResourceAdapter` can be
exported without application-specific console commands:

```bash
php artisan grow4me:export products storage/app/exports/products.csv --format=csv
php artisan grow4me:export products storage/app/exports/products.xml --format=xml
```

Only adapter-defined, human-readable columns are exported. Feed payload JSON is
never inserted as a catch-all column. CSV cells beginning with spreadsheet
formula characters are escaped by the package.

## Feed contract

The package exposes:

```text
GET /api/v1/sync/{resource}
GET /api/v1/sync/{resource}/{external_id}
```

Pages contain at most 100 records:

```json
{
  "snapshot_at": "2026-08-14T10:00:00+00:00",
  "cursor": null,
  "records": [
    {
      "contract_version": "catalog.product.v1",
      "external_id": "42",
      "active": true,
      "source_updated_at": "2026-08-14T09:58:00+00:00",
      "payload": {}
    }
  ]
}
```

`external_id` must remain stable. `source_updated_at` must advance when the
public payload or active state changes. An inactive record remains addressable
with `active: false`; a physically deleted record is returned as a tombstone
when its committed delete event exists in the outbox.

## Webhook delivery

Registered model saves, deletes, and restores create outbox events after the
surrounding database transaction commits. Laravel's scheduler runs
`grow4me:deliver-outbox` every minute by default.

Each request body contains only routing data:

```json
{
  "event_id": "uuid",
  "binding": "products",
  "operation": "upsert",
  "external_id": "42",
  "occurred_at": "2026-08-14T10:00:00+00:00"
}
```

The package signs `{timestamp}.{raw_body}` with HMAC-SHA256 and sends:

```text
X-Grow4Me-Timestamp: 1786690800
X-Grow4Me-Signature: sha256={hex-digest}
```

HTTP 2xx and `409 Conflict` are idempotent success responses. Other responses
use exponential retry and eventually move the event to dead letter.

```bash
php artisan grow4me:deliver-outbox --limit=100
php artisan grow4me:redrive-outbox {event-uuid}
php artisan grow4me:redrive-outbox --all
```

Disable the built-in scheduler only if the host dispatches the delivery command
itself:

```dotenv
GROW4ME_CONNECTOR_SCHEDULE_DELIVERY=false
```

## Operations

Validate configuration without displaying secret values:

```bash
php artisan grow4me:doctor
```

Recommended production sequence:

1. Install the tagged package release.
2. Add environment secrets through the deployment platform.
3. Run `php artisan migrate --force`.
4. Run `php artisan grow4me:doctor`.
5. Keep Laravel's scheduler active and monitor failed jobs/outbox dead letters.
6. Configure the Grow4Me data source and bindings in the Grow4Me panel.

The package never provisions a tenant, writes a customer token, or deploys a
Grow4Me environment.

## Development

```bash
composer install
composer validate --strict
composer check-platform-reqs
composer test
vendor/bin/pint --test
composer audit --locked
```

The repository ignores `composer.lock`. On a fresh checkout, `composer install`
resolves dependencies for your PHP environment and creates a local lock file.
`composer audit --locked` checks the versions recorded in that file.

The [CI workflow](.github/workflows/tests.yml) resolves and tests dependencies
separately for Laravel 12 on PHP 8.2, 8.3 and 8.4, and Laravel 13 on PHP 8.4 and
8.5. See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
