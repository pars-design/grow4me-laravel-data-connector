<?php

namespace ParsDesign\Grow4MeDataConnector\Support;

class MediaUrlNormalizer
{
    public function normalize(?string $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === 'https') {
            return $url;
        }

        $localHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);

        if (! app()->environment(['local', 'testing'])
            || $scheme !== 'http'
            || ! is_string($localHost)
            || strcasecmp($localHost, (string) $urlHost) !== 0) {
            return null;
        }

        return 'https://'.substr($url, strlen('http://'));
    }
}
