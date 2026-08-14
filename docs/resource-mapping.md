# Resource mapping

Each resource exposes one host data set through a stable Grow4Me resource key.
Choose a declarative definition for direct field mappings and a PHP adapter
when the host schema needs custom business logic.

## Declarative mapping

Definitions live in `config/grow4me-connector.php` and contain only serializable
configuration. Dot notation reads relations and builds nested payloads.

```php
use App\Models\Product;
use App\Grow4Me\Transformers\PublicPriceTransformer;

'resources' => [
    [
        'key' => 'products',
        'contract_version' => 'catalog.product.v1',
        'model' => Product::class,
        'external_id' => 'external_code',
        'cursor' => 'id',
        'source_updated_at' => 'updated_at',
        'active' => 'is_active',
        'with' => ['brand', 'category'],
        'fields' => [
            'name' => 'title',
            'brand' => 'brand.name',
            'category' => 'category.name',
            'price' => [
                'source' => 'price',
                'transformer' => PublicPriceTransformer::class,
                'options' => ['currency' => 'TRY'],
            ],
        ],
        'export' => [
            'external_code' => ['label' => 'External code', 'source' => 'external_code'],
            'name' => ['label' => 'Product name', 'source' => 'title'],
            'category' => ['label' => 'Category', 'source' => 'category.name'],
        ],
    ],
],
```

`cursor` must be unique, stable, and sortable. It does not have to match
`external_id`. Do not use a mutable SKU or slug as the cursor when an immutable
primary key is available.

A transformer is resolved from Laravel's container:

```php
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\FieldTransformer;

final class PublicPriceTransformer implements FieldTransformer
{
    public function transform(mixed $value, Model $model, array $options = []): mixed
    {
        return [
            'amount' => (float) $value,
            'currency' => $options['currency'] ?? 'TRY',
        ];
    }
}
```

Use a `ResourceQuery` or `ActiveResolver` class in the definition when query or
active-state logic cannot be represented by a model path. Both are deterministic
application code; the package never evaluates PHP supplied at runtime.

## Generated PHP adapter

The generator accepts repeatable field, export, and relationship options:

```bash
php artisan make:grow4me-resource SupplierResourceAdapter \
  --model='App\Models\Supplier' \
  --key=suppliers \
  --contract=dynamic.entry.v1 \
  --external-id=external_code \
  --active=is_active \
  --field=title=name \
  --field=slug=slug \
  --field=status=sync_status \
  --field=fields.city=city.name \
  --export=external_code='External code' \
  --export=name='Supplier name' \
  --with=city
```

The result is normal application code implementing
`ExportableResourceAdapter`. Change it freely for complex relation, media, or
normalization rules, then register its class in `resources`.

## Dependent models

If a related model changes exported content, its committed mutation must also
advance affected resource records. Implement `ResourceDependencyResolver` and
register it under `dependencies`:

```php
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceDependencyResolver;
use ParsDesign\Grow4MeDataConnector\Data\ResourceChange;

final class CategoryDependencyResolver implements ResourceDependencyResolver
{
    public function modelClass(): string
    {
        return Category::class;
    }

    public function changes(Model $model, string $operation): iterable
    {
        return $model->products()
            ->pluck('id')
            ->map(fn (int $id): ResourceChange => new ResourceChange('products', (string) $id));
    }
}
```

```php
'dependencies' => [
    App\Grow4Me\Dependencies\CategoryDependencyResolver::class,
],
```

Resolvers run after commit through the same outbox path. They must be
idempotent and should select only identifiers needed to create resource change
events.

## Normalizers

Custom adapters can reuse package-safe normalization helpers:

```php
use ParsDesign\Grow4MeDataConnector\Support\CsvCellSanitizer;
use ParsDesign\Grow4MeDataConnector\Support\MediaUrlNormalizer;
use ParsDesign\Grow4MeDataConnector\Support\TextNormalizer;
```

`TextNormalizer` strips executable markup and normalizes whitespace.
`MediaUrlNormalizer` accepts HTTPS URLs; local HTTP URLs are converted only for
the configured local/testing host. `CsvCellSanitizer` prevents spreadsheet
formula injection and is already applied by `grow4me:export`.
