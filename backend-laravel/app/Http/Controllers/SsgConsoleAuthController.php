<?php

namespace App\Http\Controllers;

/**
 * Session auth for the dedicated SSG President web console.
 *
 * The console lives at /ssg/ (ungated by nginx, unlike the staff /admin/
 * console) and is served from the same React bundle. It reuses the whole
 * {@see AdminAuthController} login + 2FA handshake so the security posture —
 * layered login throttling, account backoff, mandatory 2FA, disabled-account
 * lockout — is identical to staff, with three narrow overrides:
 *
 *   - only `ssg_president` may hold a session here, so the ungated login surface
 *     can never mint a staff (admin/teacher) session;
 *   - its own throttle bucket, so SSG spray cannot lock staff out (and vice
 *     versa);
 *   - an accurate audit label.
 *
 * The controller intentionally declares no new actions: the parent's `login`,
 * `completeTwoFactorLogin`, `me`, `updateAvatar` and `logout` are mounted under
 * the /api/ssg/console prefix in routes/api.php.
 */
class SsgConsoleAuthController extends AdminAuthController
{
    protected function loginLimiterPrefix(): string
    {
        return 'ssg-console-login';
    }

    /**
     * @return list<string>
     */
    protected function panelRoles(): array
    {
        return ['ssg_president'];
    }

    protected function consoleLabel(): string
    {
        return 'SSG President console';
    }
}
