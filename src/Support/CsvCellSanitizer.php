<?php

namespace ParsDesign\Grow4MeDataConnector\Support;

class CsvCellSanitizer
{
    /**
     * @param  list<mixed>  $row
     * @return list<mixed>
     */
    public function sanitizeRow(array $row): array
    {
        return array_map($this->sanitize(...), $row);
    }

    private function sanitize(mixed $value): mixed
    {
        if (! is_string($value) || preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) !== 1) {
            return $value;
        }

        return "'".$value;
    }
}
