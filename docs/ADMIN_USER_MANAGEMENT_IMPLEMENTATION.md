# Admin User Management Implementation Guide

## Goal

Add an admin-only user-management screen to the existing React admin panel,
connected to Laravel through the existing API client and Sanctum
authentication. Keep the existing registrar CSV import as the bulk-account
provisioning workflow.

## Navigation and current UI

Add a `users` view to the existing state-based navigation in
`admin-react/src/App.jsx`. Do not introduce React Router.

Place the navigation item directly below Student Registry:

```text
Dashboard
Candidates
Student Registry
User Management
Election Setup
Results
Settings
```

Only administrators may see or access this item. Teachers and SSG President
accounts must receive HTTP 403 from the backend even if they call the endpoint
directly.

Match the current admin UI. Reuse the existing `dashboard-container`, `sidebar`,
`main-content`, `top-header`, `card`, `card-header`, `nav-menu`, `nav-item`,
status badges, buttons, typography, spacing, and responsive styles. Follow the
component structure used by `StudentRegistry.jsx`, `Candidates.jsx`, and
`Results.jsx`. Do not add a new layout system, color palette, or UI framework.

Recommended screen structure:

```text
dashboard-container
├── sidebar with existing navigation and User Management
└── main-content
    ├── top-header
    └── users-body
        ├── page header and description
        ├── search/filter card
        ├── error or notice banner
        └── users table card
```

## Backend

### Permission

Add `manage_accounts` to the Laravel permission catalog and grant it only to
`admin`. Apply the permission middleware to every user-management route.
Frontend hiding is only a usability feature; Laravel is the security boundary.

### Database

Add an `is_active` boolean to `users`, defaulting to `true`, and enable all
existing users during the migration.

Do not allow this feature to modify:

- `has_voted`
- `voted_at`
- vote ledger rows
- ballots or ballot drafts

Add an audit-log table for role, status, and password-reset actions if possible.
Never store passwords or temporary passwords in audit data.

### API endpoints

All routes require admin authentication and `permission:manage_accounts`.

```http
GET /api/admin/users?search=&role=&is_active=&page=1
```

Return paginated users with only safe display fields:

```json
{
  "data": [
    {
      "id": 12,
      "name": "Example Student",
      "email": "student@example.com",
      "student_id": "20260001",
      "role": "student",
      "is_active": true,
      "has_voted": false,
      "year_level": "12",
      "block_number": "A"
    }
  ],
  "links": {},
  "meta": {}
}
```

Search `name`, `email`, and `student_id`. Support role and active-status
filters. Never return passwords, remember tokens, or personal access tokens.

```http
PATCH /api/admin/users/{user}/role
Content-Type: application/json

{"role": "teacher"}
```

Allow only explicitly approved roles: `admin`, `teacher`, `student`,
`candidate`, and `ssg_president` only when the election-certification rules
permit it. Prevent self-demotion and demotion of the final active
administrator. Revoke the target user's Sanctum tokens after a role change.

```http
PATCH /api/admin/users/{user}/status
Content-Type: application/json

{"is_active": false}
```

Prevent disabling the final active administrator. Revoke tokens when disabling
an account. Inactive users must be rejected by both admin and student login.

```http
POST /api/admin/users/{user}/password-reset
```

Generate a secure temporary password server-side, hash it, revoke existing
tokens, and return it only once in the authenticated response. Never log or
persist the plaintext value.

Use `401` for unauthenticated requests, `403` for missing permission, `404`
for unknown users, and `422` for invalid or protected operations. Return the
server's clear JSON message.

## React behavior

Display a responsive table with:

- Name and email
- Student ID
- Role
- Active/inactive status
- Read-only voted status
- Year level and block
- Actions

Add search, role/status filters, pagination, loading state, empty state, and
server-error state. Do not show mock users or fabricated totals.

Require confirmation before role changes, account disabling, and password
resets. Refresh the affected row or current page after successful mutations.
Display a temporary password only after a successful reset response and warn
the administrator to copy it immediately.

Use accessible labels, visible focus, `type="button"` for non-submit buttons,
and horizontal table scrolling on narrow screens.

## Authentication and tests

Update both admin and student login flows to reject inactive accounts.

Add backend tests proving:

1. Admins can list and manage users.
2. Teachers, students, and SSG Presidents receive 403.
3. Search, filters, and pagination work.
4. Self-demotion and final-admin removal are rejected.
5. Role/status/password changes revoke tokens.
6. Inactive users cannot log in.
7. Password reset stores only a hash.
8. Election-integrity fields cannot be changed.

Run the existing React lint/build and Laravel tests before deployment.

## Deployment

Back up MariaDB, deploy the migration and application changes, then run:

```bash
php artisan migrate --force
php artisan optimize:clear
```

Build the React admin application, validate Nginx, reload it, and test admin,
teacher, student, and inactive-account behavior through the Tailscale HTTPS
hostname.
