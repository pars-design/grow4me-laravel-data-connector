<?php

namespace ParsDesign\Grow4MeDataConnector\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ActiveResolver
{
    public function isActive(Model $model): bool;
}
