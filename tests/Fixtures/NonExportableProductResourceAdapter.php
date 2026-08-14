<?php

namespace ParsDesign\Grow4MeDataConnector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Resources\EloquentResourceAdapter;

class NonExportableProductResourceAdapter extends EloquentResourceAdapter
{
    public function key(): string
    {
        return 'non-exportable-products';
    }

    public function modelClass(): string
    {
        return Product::class;
    }

    public function isActive(Model $model): bool
    {
        return true;
    }

    public function payload(Model $model): array
    {
        return ['name' => $model->name];
    }
}
