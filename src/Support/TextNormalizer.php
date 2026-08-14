<?php

namespace ParsDesign\Grow4MeDataConnector\Support;

use Illuminate\Support\Str;

class TextNormalizer
{
    public function normalize(?string $value, int $maximumLength = 10_000): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $withoutExecutableContent = preg_replace(
            '/<(script|style)\b[^>]*>.*?<\/\1>/is',
            ' ',
            $value,
        ) ?? $value;
        $text = html_entity_decode(strip_tags($withoutExecutableContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : Str::limit($text, $maximumLength, '');
    }
}
