# Flutter Web API Connectivity Fix

This guide addresses the Flutter web error:

```text
Cannot reach the API server. Check that the backend is running.
```

The message is produced by the Flutter client for a Dio connection failure.
It does not prove that Laravel is stopped. The browser may be unable to reach
the configured host, may reject the TLS certificate, or may block the request
because of CORS.

## Review findings

### Client API target

The Flutter client reads `API_BASE_URL` from a Dart compile-time define and
defaults to:

```text
https://debian.tail7e9e1e.ts.net/api
```

The web server running at `http://localhost:8080` therefore makes browser
requests to the Tailscale host, not to `localhost:8000`.

### Backend route

The health and phase endpoints are public:

```text
GET /up
GET /api/election/status
```

The student login endpoint is:

```text
POST /api/auth/login
```

### CORS configuration

Laravel currently allows `http://localhost:5173`, `APP_URL`, and
`FRONTEND_URL`. A Flutter web server on `http://localhost:8080` is a different
origin and must be added to `FRONTEND_URL` (or to the configured allowed
origins). `localhost:8080` and `127.0.0.1:8080` are different origins too.

## Recommended fix: test Flutter web against the production API

Use this when the server is running through Nginx and the browser machine has
Tailscale access.

### 1. Test the API outside Flutter

From the same computer that opens the Flutter web page:

```bash
curl -v https://debian.tail7e9e1e.ts.net/up
curl -v https://debian.tail7e9e1e.ts.net/api/election/status
```

Expected results are a successful HTTP response and JSON. If these commands
cannot connect, fix Tailscale, DNS, TLS, Nginx, or the server before changing
Flutter code.

In a browser, also open:

```text
https://debian.tail7e9e1e.ts.net/api/election/status
```

Certificate warnings or an unreachable page indicate an infrastructure
problem, not a Flutter login problem.

### 2. Allow the Flutter web origin in Laravel

On the server, edit the real production `.env` (never commit it) and set:

```env
FRONTEND_URL=http://localhost:8080
```

If the page is opened using `127.0.0.1:8080`, use that origin instead. If both
origins are required, update `backend-laravel/config/cors.php` to allow both,
then clear cached configuration.

After changing `.env`:

```bash
cd /var/www/omnivote/backend-laravel
php artisan optimize:clear
```

Do not use `*` for `allowed_origins` while credentials or authenticated
requests are involved.

### 3. Run Flutter with the explicit production URL

From the Flutter project:

```bash
cd user-flutter
flutter pub get
flutter run -d web-server --web-port 8080 \
  --dart-define=API_BASE_URL=https://debian.tail7e9e1e.ts.net/api
```

Open the exact URL printed by Flutter. If it prints `127.0.0.1:8080` rather
than `localhost:8080`, configure that exact origin in CORS.

## Local backend alternative

Use this only when Laravel is intentionally running on the same development
machine:

```bash
cd backend-laravel
php artisan serve --host 0.0.0.0 --port 8000
```

Start Flutter with:

```bash
cd user-flutter
flutter run -d web-server --web-port 8080 \
  --dart-define=API_BASE_URL=http://localhost:8000/api
```

The Laravel CORS configuration must allow `http://localhost:8080`. This setup
is not the production path and should not be used as a workaround if the
server is meant to be authoritative.

## Browser diagnostics

Open the browser developer tools and inspect **Console** and **Network**:

| Symptom | Meaning | Correct action |
| --- | --- | --- |
| `ERR_NAME_NOT_RESOLVED` | Tailscale DNS name is unavailable | Connect Tailscale and verify DNS |
| `ERR_CONNECTION_TIMED_OUT` | Host/port is unreachable | Verify Tailscale, firewall, and Nginx |
| `ERR_CERT_*` | TLS certificate is not trusted or does not match | Fix the server certificate; do not disable TLS checks |
| CORS policy error | API responded but browser rejected the origin | Add the exact Flutter web origin to Laravel CORS and clear config |
| HTTP 404 | Wrong base URL or route | Confirm the `/api` suffix and endpoint |
| HTTP 401 | API is reachable; authentication failed | Check credentials or existing token |
| HTTP 403 | API is reachable; phase/permission denied | Read and surface the server message |
| HTTP 500/502 | Backend or PHP-FPM failure | Inspect Laravel and Nginx logs |

The Flutter error mapper intentionally collapses connection-level failures into
the friendly message shown in the UI. The browser Network request is the
source of truth for the underlying cause.

## Server checks

Run these on the server:

```bash
cd /var/www/omnivote/backend-laravel
php artisan optimize:clear
sudo nginx -t
sudo systemctl status nginx
sudo systemctl status php8.4-fpm
```

The deployed Nginx configuration must point to the PHP-FPM socket that exists
on the server. Check available sockets if needed:

```bash
ls /run/php/
```

Then reload only after `nginx -t` succeeds:

```bash
sudo systemctl reload nginx
```

Inspect logs while making one request:

```bash
sudo tail -f /var/log/nginx/omnivote-error.log
tail -f storage/logs/laravel.log
```

## Verification checklist

1. Tailscale is connected on the browser machine.
2. `/up` returns a successful response.
3. `/api/election/status` returns JSON.
4. The Flutter web origin exactly matches the Laravel CORS allow-list.
5. Laravel configuration cache was cleared after `.env` changes.
6. Flutter was rebuilt or restarted after changing `--dart-define`.
7. The Network tab shows requests to the intended API host.
8. Login is tested only after the public status request succeeds.

## Security notes

- Do not change production API traffic to HTTP just to bypass certificate
  errors.
- Do not add `Access-Control-Allow-Origin: *` for authenticated requests.
- Do not commit production `.env` files, tokens, passwords, or TLS keys.
- Do not run `php artisan serve` as the production service; production uses
  Nginx and PHP-FPM.
