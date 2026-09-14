# Nginx and Tailscale Team Access

## Executive summary

The most likely reason the frontend team stopped reaching the backend after
Nginx was installed is that the access URL changed.

`php artisan serve` normally exposes Laravel at:

```text
http://<tailscale-ip>:8000
```

The Nginx deployment is intended to expose it at:

```text
https://debian.tail7e9e1e.ts.net
```

Nginx does not automatically keep port `8000` available. A frontend still
configured for `http://100.84.115.25:8000` will fail with connection refused or
timeout once `php artisan serve` is stopped. The clients must use the Nginx
HTTPS hostname, not the old artisan port.

This document is a feasibility and setup review. It does not change the server
or application configuration.

## What changed when Nginx was introduced

With `php artisan serve`:

- Laravel's development server listens on port `8000`.
- The team calls the server IP and port directly.
- HTTPS is not normally involved.
- Nginx and PHP-FPM are not required.

With the supplied Nginx configuration:

- Nginx listens on ports `80` and `443`.
- Port `80` redirects to HTTPS.
- PHP requests are passed to the PHP-FPM socket.
- The Laravel document root is `backend-laravel/public`.
- Port `8000` is no longer the application entry point.
- The TLS certificate must match the hostname used by clients.

Therefore, the frontend base URL must change from an old `:8000` URL to the
Nginx HTTPS origin.

## Configuration findings

### 1. The Nginx server name is stale unless it was edited on the server

The checked-in Nginx template still contains:

```nginx
server_name 100.84.115.25 api.omnivote.local;
```

The setup script replaces only `api.omnivote.local` with the server hostname;
it does not replace `100.84.115.25`. If the team uses
`debian.tail7e9e1e.ts.net`, the deployed server block should explicitly include
that hostname and the certificate should contain that hostname.

A request with an unmatched Host header may reach the wrong server block or
produce a TLS/404 failure.

### 2. HTTPS certificate and hostname must agree

The Nginx server block uses certificate files under `/etc/ssl/omnivote/`.
Every team machine must be able to resolve the Tailscale hostname, and the
certificate must be trusted and valid for that hostname.

If the certificate is self-signed, browsers and Flutter Web will reject it
unless the certificate authority is installed on each device. `curl -k` can
appear to work while a browser still fails.

### 3. The old port may no longer be listening

The Nginx configuration listens on `80` and `443`, not `8000`. These URLs are
different deployments:

```text
Old artisan server: http://100.84.115.25:8000
Nginx server:       https://debian.tail7e9e1e.ts.net
```

Do not make the frontend use `:8000` unless `php artisan serve` is intentionally
running again.

### 4. React authentication requires the correct browser origin

The React admin client uses cookie-based Sanctum authentication and CSRF. The
backend must recognize the frontend origin in both CORS and Sanctum stateful
domains.

The current defaults cover `localhost:5173`, which is appropriate when each
developer runs Vite locally. They do not automatically cover arbitrary
Tailscale IPs, custom hostnames, or different Vite ports.

If the React app is opened at `http://100.x.y.z:5173`, that exact origin must be
configured. A successful backend `curl` request does not prove that browser
cookies, CORS, and CSRF are working.

### 5. Flutter native and Flutter Web differ

Native Flutter uses bearer tokens and is not subject to browser CORS. Flutter
Web is subject to browser CORS and TLS trust. The Flutter API base should be:

```text
https://debian.tail7e9e1e.ts.net/api
```

## Server-side verification checklist

Run these on the Debian/Tailscale server:

```bash
sudo nginx -t
sudo systemctl status nginx --no-pager
sudo systemctl status php8.3-fpm --no-pager
sudo ss -tlnp | grep -E ':80|:443|:8000'
```

Expected production behavior:

- Nginx listens on `80` and `443`.
- PHP-FPM is running.
- Port `8000` may be absent.
- Nginx configuration test succeeds.

Check the configured host and response:

```bash
curl -I https://debian.tail7e9e1e.ts.net/up
curl -i https://debian.tail7e9e1e.ts.net/api/election/status
curl -i https://debian.tail7e9e1e.ts.net/sanctum/csrf-cookie
```

If the certificate is self-signed, use `curl -k` only for diagnosis. Do not
use `secure: false` or disable browser TLS verification as the permanent fix.

Inspect logs while a teammate retries:

```bash
sudo tail -f /var/log/nginx/omnivote-access.log \
  /var/log/nginx/omnivote-error.log
```

If no access-log entry appears, the request is not reaching Nginx; investigate
Tailscale connectivity, DNS, firewall rules, and the URL. If the request is
logged but returns `502`, investigate PHP-FPM and the socket path. If it returns
`401`, `419`, or CORS errors, investigate Sanctum/session configuration.

## Recommended production values

On the server's real Laravel `.env` (not in source control), use values
equivalent to:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://debian.tail7e9e1e.ts.net
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,127.0.0.1:5173
```

If the React app is hosted at a fixed Tailscale origin, add that exact host and
port to both `FRONTEND_URL`/CORS configuration and
`SANCTUM_STATEFUL_DOMAINS`. Do not add broad wildcards for authenticated
origins.

After changing environment values, clear Laravel's cached configuration:

```bash
cd /var/www/omnivote/backend-laravel
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan config:cache
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
```

## Team setup: React Admin

### Option A: local Vite development with the committed proxy

1. Ensure the developer is connected to the same Tailscale network.
2. Clone the repository and install dependencies:

   ```bash
   cd admin-react
   npm install
   ```

3. Start Vite:

   ```bash
   npm run dev
   ```

4. Open the URL Vite prints, normally:

   ```text
   http://localhost:5173
   ```

The committed Vite proxy forwards `/api` and `/sanctum` to the Nginx HTTPS
origin. The browser should not be configured with the old `:8000` URL.

### Option B: direct cross-origin API calls

If the frontend is served by another development server or hostname, create
`admin-react/.env.local`:

```dotenv
VITE_API_BASE_URL=https://debian.tail7e9e1e.ts.net
```

Restart Vite after changing it. The backend must allow the exact frontend
origin and recognize it as a Sanctum stateful domain for cookie login.

## Team setup: Flutter

### Native Android or iOS

The committed default already targets:

```text
https://debian.tail7e9e1e.ts.net/api
```

Run:

```bash
cd user-flutter
flutter pub get
flutter run
```

To override the backend explicitly:

```bash
flutter run --dart-define=API_BASE_URL=https://debian.tail7e9e1e.ts.net/api
```

### Flutter Web

Flutter Web must use the HTTPS origin and a certificate trusted by the
browser:

```bash
flutter run -d chrome \
  --dart-define=API_BASE_URL=https://debian.tail7e9e1e.ts.net/api
```

If the browser reports CORS, add the exact Flutter Web origin (including its
port) to the backend CORS and Sanctum stateful configuration, then clear Laravel
configuration cache and restart the web app.

## Symptom-to-cause guide

| Symptom | Likely cause |
| --- | --- |
| `ECONNREFUSED ...:8000` | Frontend still uses the old artisan URL |
| `502 Bad Gateway` | PHP-FPM is stopped, wrong socket path, or Laravel permissions/fatal error |
| Browser certificate warning | Certificate is self-signed, expired, or does not match the hostname |
| CORS error | Frontend origin is missing from Laravel CORS configuration |
| Sanctum `401` after login | Frontend origin is missing from `SANCTUM_STATEFUL_DOMAINS`, cookies are not sent, or session configuration is stale |
| `419 Page Expired` | CSRF cookie/token was not obtained or was cached incorrectly |
| API works with `curl` but not browser | Browser TLS, CORS, cookie, or CSRF behavior differs from curl |
| Flutter native works but Flutter Web fails | Browser CORS or certificate trust issue |

## Safe conclusion

The migration to Nginx is compatible with the current React and Flutter code,
but all clients must use the Nginx HTTPS endpoint. The first items to verify
are the stale `:8000` client URL, the deployed Nginx `server_name`, the
certificate hostname, and PHP-FPM status. Only after those are correct should
you tune CORS and Sanctum stateful domains for the team's actual frontend
origins.
