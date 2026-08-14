<?php

namespace ParsDesign\Grow4MeDataConnector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class ExternalCodeProductResourceAdapter extends ProductResourceAdapter
{
    public function externalId(Model $model): string
    {
        return (string) $model->external_code;
    }

    public function find(string $externalId): ?Model
    {
        return Product::query()->where('external_code', $externalId)->first();
    }
}
