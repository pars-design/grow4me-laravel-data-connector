<?php

namespace ParsDesign\Grow4MeDataConnector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceDependencyResolver;
use ParsDesign\Grow4MeDataConnector\Data\ResourceChange;

class CategoryDependencyResolver implements ResourceDependencyResolver
{
    public function modelClass(): string
    {
        return Category::class;
    }

    public function changes(Model $model, string $operation): iterable
    {
        if (! $model instanceof Category) {
            return [];
        }

        return $model->products()
            ->pluck('id')
            ->map(fn (int $id): ResourceChange => new ResourceChange('products', (string) $id));
    }
}
