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

        if ($scheme !== 'https') {
            $localHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $urlHost = parse_url($url, PHP_URL_HOST);

            if (! app()->environment(['local', 'testing'])
                || $scheme !== 'http'
                || ! is_string($localHost)
                || strcasecmp($localHost, (string) $urlHost) !== 0) {
                return null;
            }

            $url = 'https://'.substr($url, strlen('http://'));
        }

        return $this->encodePath($url);
    }

    private function encodePath(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        $path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', (string) ($parts['path'] ?? '')),
        ));
        $host = str_contains($parts['host'], ':') ? '['.$parts['host'].']' : $parts['host'];
        $normalizedUrl = $parts['scheme'].'://'.$host;

        if (isset($parts['port'])) {
            $normalizedUrl .= ':'.$parts['port'];
        }

        $normalizedUrl .= $path;

        if (isset($parts['query'])) {
            $normalizedUrl .= '?'.$parts['query'];
        }

        return filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false ? null : $normalizedUrl;
    }
}
