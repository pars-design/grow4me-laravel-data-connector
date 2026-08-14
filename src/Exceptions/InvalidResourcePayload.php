<?php

namespace ParsDesign\Grow4MeDataConnector\Exceptions;

use RuntimeException;

class InvalidResourcePayload extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public static function forResource(string $resource, array $errors): self
    {
        return new self(sprintf(
            'Grow4Me resource [%s] produced an invalid payload: %s',
            $resource,
            implode(' ', $errors),
        ));
    }
}
