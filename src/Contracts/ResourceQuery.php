<?php

namespace ParsDesign\Grow4MeDataConnector\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ResourceQuery
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $definition
     */
    public function build(string $modelClass, CarbonImmutable $snapshotAt, array $definition): Builder;
}
