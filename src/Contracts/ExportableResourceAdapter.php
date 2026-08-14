<?php

namespace ParsDesign\Grow4MeDataConnector\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ExportableResourceAdapter extends ResourceAdapter
{
    /**
     * @return array<string, string>
     */
    public function exportColumns(): array;

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function exportRow(Model $model): array;
}
