<?php

namespace ParsDesign\Grow4MeDataConnector\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ResourceAdapter
{
    public function key(): string;

    public function contractVersion(): string;

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    public function query(CarbonImmutable $snapshotAt): Builder;

    public function applyCursor(Builder $query, int|string|null $after): Builder;

    public function find(string $externalId): ?Model;

    public function externalId(Model $model): string;

    public function cursorValue(Model $model): int|string;

    public function sourceUpdatedAt(Model $model): CarbonInterface;

    public function isActive(Model $model): bool;

    /**
     * @return array<string, mixed>
     */
    public function payload(Model $model): array;
}
