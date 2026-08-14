<?php

namespace ParsDesign\Grow4MeDataConnector\Contracts;

use Illuminate\Database\Eloquent\Model;
use ParsDesign\Grow4MeDataConnector\Data\ResourceChange;

interface ResourceDependencyResolver
{
    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * @return iterable<ResourceChange>
     */
    public function changes(Model $model, string $operation): iterable;
}
