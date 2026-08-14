<?php

namespace ParsDesign\Grow4MeDataConnector\Resources;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use ParsDesign\Grow4MeDataConnector\Contracts\ActiveResolver;
use ParsDesign\Grow4MeDataConnector\Contracts\ExportableResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Contracts\FieldTransformer;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceQuery;
use RuntimeException;

class ConfiguredEloquentResourceAdapter extends EloquentResourceAdapter implements ExportableResourceAdapter
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        private readonly array $definition,
        private readonly Container $container,
    ) {
        foreach (['key', 'model', 'fields'] as $required) {
            if (! array_key_exists($required, $definition)) {
                throw new InvalidArgumentException("Configured resource [{$required}] is required.");
            }
        }
    }

    public function key(): string
    {
        return (string) $this->definition['key'];
    }

    public function contractVersion(): string
    {
        return (string) ($this->definition['contract_version'] ?? 'v1');
    }

    public function modelClass(): string
    {
        $modelClass = $this->definition['model'];

        if (! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException('Configured resource model must be an Eloquent model class.');
        }

        return $modelClass;
    }

    public function query(CarbonImmutable $snapshotAt): Builder
    {
        $queryClass = $this->definition['query'] ?? null;

        if (is_string($queryClass)) {
            $query = $this->container->make($queryClass);

            if (! $query instanceof ResourceQuery) {
                throw new InvalidArgumentException("Configured query [{$queryClass}] must implement ResourceQuery.");
            }

            return $query->build($this->modelClass(), $snapshotAt, $this->definition);
        }

        $model = $this->newModel();
        $query = $model->newQuery();
        $with = $this->definition['with'] ?? [];

        if (is_array($with) && $with !== []) {
            $query->with($with);
        }

        if (($this->definition['include_trashed'] ?? false) === true
            && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        if ($model->usesTimestamps()) {
            $query->where($model->getCreatedAtColumn(), '<=', $snapshotAt);
        }

        return $query->orderBy((string) ($this->definition['cursor'] ?? $model->getKeyName()));
    }

    public function applyCursor(Builder $query, int|string|null $after): Builder
    {
        if ($after === null) {
            return $query;
        }

        return $query->where((string) ($this->definition['cursor'] ?? $this->newModel()->getKeyName()), '>', $after);
    }

    public function find(string $externalId): ?Model
    {
        $model = $this->newModel();
        $query = $model->newQuery();
        $with = $this->definition['with'] ?? [];

        if (is_array($with) && $with !== []) {
            $query->with($with);
        }

        if (($this->definition['include_trashed'] ?? false) === true
            && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        return $query->where((string) ($this->definition['external_id'] ?? $model->getKeyName()), $externalId)->first();
    }

    public function externalId(Model $model): string
    {
        return (string) data_get($model, (string) ($this->definition['external_id'] ?? $model->getKeyName()));
    }

    public function cursorValue(Model $model): int|string
    {
        $value = data_get($model, (string) ($this->definition['cursor'] ?? $model->getKeyName()));

        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException('Configured cursor values must be integers or strings.');
        }

        return $value;
    }

    public function sourceUpdatedAt(Model $model): CarbonInterface
    {
        $value = data_get($model, (string) ($this->definition['source_updated_at'] ?? $model->getUpdatedAtColumn()));

        if (! $value instanceof CarbonInterface) {
            throw new RuntimeException('Configured source_updated_at must resolve to a Carbon value.');
        }

        return $value;
    }

    public function isActive(Model $model): bool
    {
        $resolverClass = $this->definition['active_resolver'] ?? null;

        if (is_string($resolverClass)) {
            $resolver = $this->container->make($resolverClass);

            if (! $resolver instanceof ActiveResolver) {
                throw new InvalidArgumentException("Configured active resolver [{$resolverClass}] must implement ActiveResolver.");
            }

            return $resolver->isActive($model);
        }

        return (bool) data_get($model, (string) ($this->definition['active'] ?? 'active'));
    }

    public function payload(Model $model): array
    {
        $payload = [];

        foreach ($this->definition['fields'] as $target => $mapping) {
            data_set($payload, (string) $target, $this->mappedValue($model, $mapping));
        }

        return $payload;
    }

    public function exportColumns(): array
    {
        $columns = [];

        foreach (($this->definition['export'] ?? []) as $key => $mapping) {
            $columns[(string) $key] = is_array($mapping)
                ? (string) ($mapping['label'] ?? $key)
                : (string) $key;
        }

        return $columns;
    }

    public function exportRow(Model $model): array
    {
        $row = [];

        foreach (($this->definition['export'] ?? []) as $key => $mapping) {
            $value = $this->mappedValue($model, $mapping);

            if (! is_bool($value) && ! is_float($value) && ! is_int($value) && ! is_string($value) && $value !== null) {
                throw new RuntimeException("Export column [{$key}] must resolve to a scalar or null value.");
            }

            $row[(string) $key] = $value;
        }

        return $row;
    }

    private function mappedValue(Model $model, mixed $mapping): mixed
    {
        if (is_string($mapping)) {
            return data_get($model, $mapping);
        }

        if (! is_array($mapping)) {
            throw new InvalidArgumentException('Field mappings must be source paths or mapping arrays.');
        }

        $value = data_get($model, (string) ($mapping['source'] ?? ''));
        $transformerClass = $mapping['transformer'] ?? null;

        if (! is_string($transformerClass)) {
            return $value;
        }

        $transformer = $this->container->make($transformerClass);

        if (! $transformer instanceof FieldTransformer) {
            throw new InvalidArgumentException("Configured transformer [{$transformerClass}] must implement FieldTransformer.");
        }

        $options = $mapping['options'] ?? [];

        return $transformer->transform($value, $model, is_array($options) ? $options : []);
    }
}
