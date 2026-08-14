<?php

namespace ParsDesign\Grow4MeDataConnector\Services;

use ParsDesign\Grow4MeDataConnector\Exceptions\InvalidResourcePayload;

class PayloadValidator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(string $resource, string $contractVersion, array $payload): void
    {
        $errors = match ($contractVersion) {
            'catalog.product.v1' => $this->catalogProductErrors($payload),
            'dynamic.entry.v1' => $this->dynamicEntryErrors($payload),
            default => [],
        };

        if ($errors !== []) {
            throw InvalidResourcePayload::forResource($resource, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function catalogProductErrors(array $payload): array
    {
        $errors = [];

        if (! is_string($payload['name'] ?? null) || trim($payload['name']) === '') {
            $errors[] = 'name must be a non-empty string.';
        }

        if (isset($payload['images']) && (! is_array($payload['images']) || collect($payload['images'])->contains(
            fn (mixed $url): bool => ! is_string($url)
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || parse_url($url, PHP_URL_SCHEME) !== 'https',
        ))) {
            $errors[] = 'images must contain only HTTPS URLs.';
        }

        if (isset($payload['detail_url']) && (! is_string($payload['detail_url'])
            || filter_var($payload['detail_url'], FILTER_VALIDATE_URL) === false
            || parse_url($payload['detail_url'], PHP_URL_SCHEME) !== 'https')) {
            $errors[] = 'detail_url must be an HTTPS URL.';
        }

        if (isset($payload['attributes']) && ! is_array($payload['attributes'])) {
            $errors[] = 'attributes must be an object.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function dynamicEntryErrors(array $payload): array
    {
        $errors = [];

        foreach (['title', 'slug', 'status'] as $field) {
            if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                $errors[] = "{$field} must be a non-empty string.";
            }
        }

        if (! is_array($payload['fields'] ?? null)) {
            $errors[] = 'fields must be an object.';
        }

        return $errors;
    }
}
