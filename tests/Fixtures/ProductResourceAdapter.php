<?php

namespace ParsDesign\Grow4MeDataConnector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ExportableResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Resources\EloquentResourceAdapter;

class ProductResourceAdapter extends EloquentResourceAdapter implements ExportableResourceAdapter
{
    public function key(): string
    {
        return 'products';
    }

    public function modelClass(): string
    {
        return Product::class;
    }

    public function isActive(Model $model): bool
    {
        return $model->active;
    }

    public function payload(Model $model): array
    {
        return [
            'name' => $model->name,
        ];
    }

    public function exportColumns(): array
    {
        return [
            'name' => 'Name',
            'description' => 'Description',
        ];
    }

    public function exportRow(Model $model): array
    {
        return [
            'name' => $model->name,
            'description' => $model->description,
        ];
    }
}
