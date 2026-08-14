<?php

namespace ParsDesign\Grow4MeDataConnector\Contracts;

use Illuminate\Database\Eloquent\Model;

interface FieldTransformer
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function transform(mixed $value, Model $model, array $options = []): mixed;
}
