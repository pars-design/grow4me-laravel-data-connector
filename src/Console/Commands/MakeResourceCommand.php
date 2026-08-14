<?php

namespace ParsDesign\Grow4MeDataConnector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MakeResourceCommand extends Command
{
    protected $signature = 'make:grow4me-resource
        {name : Adapter class name}
        {--model= : Fully qualified Eloquent model class}
        {--key= : Public sync resource key}
        {--contract=catalog.product.v1 : Payload contract version}
        {--external-id=id : Model path used as the public external ID}
        {--active=active : Model path used to determine active state}
        {--updated-at=updated_at : Model path used as source_updated_at}
        {--field=* : Payload mapping in target=source format}
        {--export=* : Export column in source=label format}
        {--with=* : Eloquent relationship to eager load}
        {--namespace=App\\Grow4Me\\Resources : Generated class namespace}
        {--path= : Output directory; defaults to app/Grow4Me/Resources}
        {--force : Overwrite an existing adapter}';

    protected $description = 'Create a Grow4Me Eloquent resource adapter with explicit payload and export mappings';

    public function handle(Filesystem $files): int
    {
        $model = $this->option('model');
        $key = $this->option('key');

        if (! is_string($model) || $model === '' || ! is_string($key) || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $key) !== 1) {
            $this->error('Both --model and a lowercase --key are required.');

            return self::INVALID;
        }

        try {
            $fields = $this->mappings((array) $this->option('field'), ['name' => 'name']);
            $exports = $this->mappings((array) $this->option('export'), ['name' => 'Name']);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $class = Str::studly((string) $this->argument('name'));
        $namespace = trim((string) $this->option('namespace'), '\\');
        $directory = $this->option('path');
        $directory = is_string($directory) && $directory !== ''
            ? $directory
            : app_path('Grow4Me/Resources');
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$class.'.php';

        if ($files->exists($path) && ! $this->option('force')) {
            $this->error("Adapter already exists at [{$path}].");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($directory);
        $files->put($path, $this->contents(
            namespace: $namespace,
            class: $class,
            model: trim($model, '\\'),
            key: $key,
            contract: (string) $this->option('contract'),
            externalId: (string) $this->option('external-id'),
            active: (string) $this->option('active'),
            updatedAt: (string) $this->option('updated-at'),
            fields: $fields,
            exports: $exports,
            relationships: array_values(array_filter(
                (array) $this->option('with'),
                fn (mixed $relationship): bool => is_string($relationship) && $relationship !== '',
            )),
        ));

        $this->info("Grow4Me resource adapter created at [{$path}].");
        $this->line('Add the generated class to grow4me-connector.resources.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    private function mappings(array $values, array $defaults): array
    {
        if ($values === []) {
            return $defaults;
        }

        $mappings = [];

        foreach ($values as $value) {
            if (! is_string($value) || ! str_contains($value, '=')) {
                throw new InvalidArgumentException('Mappings must use target=source or source=label format.');
            }

            [$left, $right] = array_map('trim', explode('=', $value, 2));

            if ($left === '' || $right === '') {
                throw new InvalidArgumentException('Mapping names and values cannot be empty.');
            }

            $mappings[$left] = $right;
        }

        return $mappings;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $exports
     * @param  array<int, string>  $relationships
     */
    private function contents(
        string $namespace,
        string $class,
        string $model,
        string $key,
        string $contract,
        string $externalId,
        string $active,
        string $updatedAt,
        array $fields,
        array $exports,
        array $relationships,
    ): string {
        $modelClass = class_basename($model);
        $fieldStatements = collect($fields)
            ->map(fn (string $source, string $target): string => sprintf(
                '        data_set($payload, %s, data_get($model, %s));',
                var_export($target, true),
                var_export($source, true),
            ))
            ->implode("\n");
        $exportColumns = $this->arrayLines($exports);
        $exportRows = collect($exports)
            ->mapWithKeys(fn (string $label, string $source): array => [$source => $source])
            ->map(fn (string $source, string $key): string => sprintf(
                '            %s => data_get($model, %s),',
                var_export($key, true),
                var_export($source, true),
            ))
            ->implode("\n");
        $with = $this->arrayLines(array_combine($relationships, $relationships) ?: []);

        return <<<PHP
<?php

namespace {$namespace};

use Carbon\CarbonInterface;
use {$model};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ExportableResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Resources\EloquentResourceAdapter;
use RuntimeException;

class {$class} extends EloquentResourceAdapter implements ExportableResourceAdapter
{
    public function key(): string
    {
        return {$this->quote($key)};
    }

    public function contractVersion(): string
    {
        return {$this->quote($contract)};
    }

    public function modelClass(): string
    {
        return {$modelClass}::class;
    }

    public function query(\Carbon\CarbonImmutable \$snapshotAt): Builder
    {
        return parent::query(\$snapshotAt)->with([
{$with}
        ]);
    }

    public function find(string \$externalId): ?Model
    {
        return \$this->newModel()->newQuery()
            ->with([
{$with}
            ])
            ->where({$this->quote($externalId)}, \$externalId)
            ->first();
    }

    public function externalId(Model \$model): string
    {
        return (string) data_get(\$model, {$this->quote($externalId)});
    }

    public function sourceUpdatedAt(Model \$model): CarbonInterface
    {
        \$updatedAt = data_get(\$model, {$this->quote($updatedAt)});

        if (! \$updatedAt instanceof CarbonInterface) {
            throw new RuntimeException('The configured source timestamp must resolve to a Carbon value.');
        }

        return \$updatedAt;
    }

    public function isActive(Model \$model): bool
    {
        return (bool) data_get(\$model, {$this->quote($active)});
    }

    public function payload(Model \$model): array
    {
        \$payload = [];

{$fieldStatements}

        return \$payload;
    }

    public function exportColumns(): array
    {
        return [
{$exportColumns}
        ];
    }

    public function exportRow(Model \$model): array
    {
        return [
{$exportRows}
        ];
    }
}
PHP;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function arrayLines(array $values): string
    {
        return collect($values)
            ->map(fn (string $value, string $key): string => sprintf(
                '            %s => %s,',
                var_export($key, true),
                var_export($value, true),
            ))
            ->implode("\n");
    }

    private function quote(string $value): string
    {
        return var_export($value, true);
    }
}
