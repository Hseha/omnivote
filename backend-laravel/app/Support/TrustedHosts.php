<?php

namespace App\Support;

/**
 * Builds the Host allow-list for Laravel's TrustHosts middleware (security
 * assessment L-1).
 *
 * `asset()`/`url()` resolve against the incoming request root unless a root is
 * forced, so an unvalidated Host header let a caller make the API emit URLs
 * pointing at their own domain (`photo_url = http://evil.example/...`). The
 * password-reset mail already used a static config URL and was safe; this closes
 * the general case.
 *
 * Defaults cover whatever APP_URL/FRONTEND_URL point at plus the loopback names
 * used by `php artisan serve`, and TRUSTED_HOSTS extends the list for deployments
 * reached by a name that appears nowhere in the config (a Tailscale IP or a
 * second DNS name). Ports are irrelevant: Symfony's HostValidator matches
 * Request::getHost(), which excludes the port.
 *
 * FAILURE MODE: a request whose Host is not listed gets a 400. Add the name you
 * browse to — `TRUSTED_HOSTS=100.84.115.25,debian.tail7e9e1e.ts.net` — and clear
 * the config cache if you see one.
 */
final class TrustedHosts
{
    /**
     * Regexes for TrustHosts (Symfony treats each entry as a raw pattern, so
     * dots are escaped and an optional sub-domain prefix is allowed).
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        return array_values(array_unique(array_map(
            fn (string $host): string => '^(.+\.)?'.preg_quote($host, '/').'$',
            self::hosts(),
        )));
    }

    /**
     * The bare host names that are always acceptable.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        $configured = array_map(
            fn (?string $url): ?string => self::hostFrom($url),
            [config('app.url'), config('app.frontend_url')],
        );

        $extra = array_map(
            fn (string $entry): ?string => self::hostFrom('//'.trim($entry)),
            (array) config('app.trusted_hosts', []),
        );

        return array_values(array_unique(array_filter(array_merge(
            $configured,
            $extra,
            // `php artisan serve` on loopback (serve-dev.sh) and health checks.
            ['localhost', '127.0.0.1', '::1'],
        ))));
    }

    /** Extract just the host from a URL-ish string, ignoring scheme and port. */
    private static function hostFrom(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);

        // parse_url() returns false for a bare "example.com:8080", so fall back
        // to stripping any port and path by hand.
        if ($host === false || $host === null) {
            $host = preg_replace('#[/:].*$#', '', trim($url));
        }

        $host = trim((string) $host, '[]');

        return $host !== '' ? strtolower($host) : null;
    }
}
