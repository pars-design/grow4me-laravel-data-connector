<?php

namespace ParsDesign\Grow4MeDataConnector\Support;

use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class SignedCursor
{
    /**
     * @param  array{resource: string, snapshot_at: string, after: int|string}  $payload
     */
    public function encode(array $payload): string
    {
        $json = json_encode(['version' => 1, ...$payload], JSON_THROW_ON_ERROR);
        $encoded = $this->base64UrlEncode($json);

        return $encoded.'.'.$this->signature($encoded);
    }

    /**
     * @return array{version: int, resource: string, snapshot_at: string, after: int|string}
     */
    public function decode(string $cursor, string $resource): array
    {
        try {
            [$encoded, $signature] = array_pad(explode('.', $cursor, 2), 2, null);

            if (! is_string($encoded) || ! is_string($signature) || ! hash_equals($this->signature($encoded), $signature)) {
                throw new RuntimeException('Cursor signature is invalid.');
            }

            $decoded = json_decode($this->base64UrlDecode($encoded), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($decoded)
                || ($decoded['version'] ?? null) !== 1
                || ($decoded['resource'] ?? null) !== $resource
                || ! is_string($decoded['snapshot_at'] ?? null)
                || (! is_int($decoded['after'] ?? null) && ! is_string($decoded['after'] ?? null))) {
                throw new RuntimeException('Cursor payload is invalid.');
            }

            return $decoded;
        } catch (JsonException|RuntimeException) {
            throw ValidationException::withMessages([
                'cursor' => 'The cursor is invalid or does not belong to this resource.',
            ]);
        }
    }

    private function signature(string $value): string
    {
        $secret = config('grow4me-connector.cursor_secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('GROW4ME_CONNECTOR_CURSOR_SECRET must contain at least 32 characters.');
        }

        return hash_hmac('sha256', $value, $secret);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Cursor encoding is invalid.');
        }

        return $decoded;
    }
}
