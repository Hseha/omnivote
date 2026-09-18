# Admin User Management Feasibility

## Decision

Admin user management is feasible and fits the current OmniVote architecture. It
should be implemented as a server-authorized Laravel feature with a React admin
screen. The existing registrar import should remain focused on bulk provisioning;
it should not be treated as the user-management interface.

## Current state

The current `main` branch already provides most of the foundation:

- `users` stores `name`, `email`, `student_id`, `role`, `has_voted`,
  `year_level`, and `block_number`.
- Admin authentication uses Laravel Sanctum sessions and rate-limited login.
- `User` supports mass assignment for account fields and hashes passwords.
- The React admin shell already has a `voters`/Student Registry view.
- Registrar CSV import creates or updates student accounts.

The following pieces are still missing:

- No protected `GET /api/admin/users` endpoint exists.
- No protected endpoint exists for changing roles, disabling accounts, or
  resetting a password.
- `AdminUserController.php` is currently empty.
- The React Student Registry only uploads registrar CSV files; it does not list,
  search, edit, or manage existing accounts.
- The current admin route group is authenticated but does not yet apply a
  dedicated permission middleware.
- There is no account-status column or audit log for administrative changes.

## Recommended first implementation

### Backend

Add an `AdminUserController` with:

1. `index`
   - Paginated users.
   - Search by name, email, or student ID.
   - Filters for role and account status.
   - Select only safe display fields; never return password or token data.
2. `updateRole`
   - Allow only approved roles.
   - Prevent an administrator from changing their own role.
   - Prevent demoting the last active administrator.
   - Revoke the affected user's Sanctum tokens after a role change.
3. `updateStatus`
   - Add an `is_active` boolean through a migration.
   - Block inactive users in both admin and student authentication.
   - Revoke tokens when an account is disabled.
4. `resetPassword`
   - Generate a secure temporary password or accept an explicitly validated
     password.
   - Hash it through Laravel's normal password cast.
   - Revoke existing tokens.
   - Return the temporary credential only in the authenticated admin response;
     never log or store it in plaintext.

Use a dedicated permission such as `manage_accounts`, granted only to `admin`.
The frontend may hide controls for other roles, but Laravel middleware must be
the security boundary.

### Frontend

Extend the existing Student Registry view or add a separate Users view with:

- Search and pagination.
- Name, email, student ID, role, active status, and voted status.
- Role and status actions with confirmation dialogs.
- Password-reset action with a one-time result display.
- Separate CSV import controls so bulk import remains understandable.
- Clear server error messages and loading/empty states.

Do not expose `has_voted` as an editable field. It is election integrity data
and must only be changed by voting workflows.

## Security and integrity requirements

- Enforce `manage_accounts` on every management endpoint.
- Validate route model binding and prevent self-demotion.
- Prevent removal or deactivation of the final active administrator.
- Revoke active sessions/tokens after role, status, or password changes.
- Do not allow changing `has_voted`, vote records, or election audit data.
- Use pagination and indexed search fields to avoid loading the whole user table.
- Add audit logging for role, status, and password-reset actions before the
  feature is used in a real election.
- Keep account-management endpoints unavailable to teachers, students, and SSG
  President accounts.

## Suggested implementation order

1. Add `is_active` and an account-audit table migration.
2. Add `manage_accounts` permission middleware and backend endpoints.
3. Add authentication checks for inactive accounts.
4. Add the React management table and guarded actions.
5. Add focused backend tests for authorization, last-admin protection, token
   revocation, and inactive-account rejection.
6. Run the existing React lint/build and Laravel test suite.
7. Deploy only after database backup and migration verification.

## Conclusion

The feature is practical and should be implemented. The safest scope for the
first version is account listing/search, role management, activation status, and
password reset, with server-side authorization and audit logging. CSV import
already covers bulk account provisioning and should remain a separate workflow.
