<?php

namespace ParsDesign\Grow4MeDataConnector\Data;

final readonly class ResourceChange
{
    public function __construct(
        public string $resource,
        public string $externalId,
        public string $operation = 'upsert',
    ) {}
}
