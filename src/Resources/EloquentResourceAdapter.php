<?php

namespace ParsDesign\Grow4MeDataConnector\Resources;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceAdapter;
use RuntimeException;

abstract class EloquentResourceAdapter implements ResourceAdapter
{
    public function contractVersion(): string
    {
        return 'v1';
    }

    public function query(CarbonImmutable $snapshotAt): Builder
    {
        $model = $this->newModel();
        $query = $model->newQuery();

        if ($model->usesTimestamps() && $model->getCreatedAtColumn() !== null) {
            $query->where($model->getCreatedAtColumn(), '<=', $snapshotAt);
        }

        return $query->orderBy($model->getKeyName());
    }

    public function applyCursor(Builder $query, int|string|null $after): Builder
    {
        if ($after === null) {
            return $query;
        }

        return $query->where($this->newModel()->getKeyName(), '>', $after);
    }

    public function find(string $externalId): ?Model
    {
        return $this->newModel()->newQuery()->find($externalId);
    }

    public function externalId(Model $model): string
    {
        return (string) $model->getKey();
    }

    public function cursorValue(Model $model): int|string
    {
        $value = $model->getKey();

        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException('Resource cursor values must be integers or strings.');
        }

        return $value;
    }

    public function sourceUpdatedAt(Model $model): CarbonInterface
    {
        $updatedAt = $model->getAttribute($model->getUpdatedAtColumn());

        if (! $updatedAt instanceof CarbonInterface) {
            throw new RuntimeException(sprintf(
                'Resource model [%s] must expose an updated timestamp.',
                $model::class,
            ));
        }

        return $updatedAt;
    }

    protected function newModel(): Model
    {
        $modelClass = $this->modelClass();
        $model = new $modelClass;

        if (! $model instanceof Model) {
            throw new RuntimeException(sprintf(
                'Resource adapter [%s] must return an Eloquent model class.',
                static::class,
            ));
        }

        return $model;
    }
}
