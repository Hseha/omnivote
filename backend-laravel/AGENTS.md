# Multi-Agent Jurisdiction & Concurrency Policy

To prevent code conflicts, duplicate file rewrites, and race conditions across autonomous tools (Cline, OpenCode, Antigravity CLI, and GitHub Copilot), all development must strictly adhere to the following domain boundaries. Cline and Antigravity CLI are primarily backend, OpenCode is primarily frontend — but any agent may cross into another's domain when a task genuinely requires full-stack work, subject to the cross-domain rules in the Execution Protocol below.

## 1. Cline — Architecture & System State (Primary: Backend)
- **Primary Jurisdiction:** Database migrations, seeders, Laravel backend controllers/routes/middleware, global Git/repo cleanup, and core project configuration.
- **Cross-Domain Allowance:** May touch frontend files (Flutter or React) when a task genuinely requires it end-to-end — e.g., a backend change that needs a matching frontend update to actually work (a new field, a changed response shape, a new endpoint that needs to be wired into a screen). This is not a green light for general frontend feature work or styling — only the minimum needed to make a backend change actually usable.
- **Rule:** Do not modify individual UI screens or feature-level frontend widgets for reasons unrelated to a backend change. Always check git status before mass file operations, and especially before touching any file outside the primary backend jurisdiction.

## 2. OpenCode — Feature Implementation & Frontend Logic (Primary: Frontend)
- **Primary Jurisdiction:** Flutter UI features (`user-flutter/lib/features/`), state providers, local service layers, React admin components (`admin-react/src/`), and screen routing.
- **Cross-Domain Allowance:** May touch backend files (Laravel controllers, routes, migrations) when a task genuinely requires it end-to-end — e.g., a new frontend feature that needs a new endpoint, a new field, or a small backend fix to function correctly. This is not a green light for general backend architecture work — only the minimum needed to make the frontend feature actually work.
- **Rule:** Do not modify core database schema design, backend routing structure, or global project configuration beyond what's strictly needed to support the frontend feature at hand. Build upon existing models and endpoints where possible; extend rather than restructure.

## 3. Antigravity CLI — Data Integrity, Validation & Hardening (Primary: Backend)
- **Primary Jurisdiction:** Cross-cutting backend correctness work that does not reshape schema or architecture — input validation and eligibility enforcement (e.g. position/electorate scope checks such as `Position::voterIsInScope`), seat-cap and duplicate-entry guards, registrar-import and self-registration field propagation (`year_level`, `block_number`, `department`, `course`, activation/turnout flags), authentication/backoff recovery (e.g. individual account unlock), and deploy/infra config (nginx, proxy / real-client-IP handling) — together with the feature tests that lock each fix in.
- **Cross-Domain Allowance:** May touch frontend or deployment files when a fix genuinely requires it end-to-end (e.g. a validation/error response the UI must render, or an infra config change). Only the minimum edits needed — never general UI feature work or styling.
- **Rule:** This domain overlaps Cline's backend jurisdiction, so Antigravity CLI owns *correctness and validation inside existing controllers/requests and their tests*, not new architecture. Do not introduce migrations, routes, or middleware changes without coordinating with Cline first, and never edit a file another agent has already modified (`git status` before every file group).

## 4. GitHub Copilot — Inline Acceleration Only
- **Jurisdiction:** Real-time editor assistance, boilerplate code, method completions, and docstrings.
- **Rule:** Restricted strictly to active file editing in the IDE. No autonomous multi-file refactoring, no cross-domain access, and no background terminal command execution.

## Execution Protocol for Concurrency

1. **Never edit overlapping files concurrently.** If a file is currently modified or staged by one agent, other agents must leave it untouched. This applies with special force to shared backend controllers, which both Cline and Antigravity CLI may need: only one of them may hold an edit to a given file at a time, so check `git status` (and `git branch --show-current`) before touching any controller, then wait for the current holder to commit before starting.
2. **Always run a pre-check (`git status`)** before initiating major file modifications or automated cleanup loops — this applies doubly when an agent is about to cross into the other's primary domain.
3. **Check first, then report back before proceeding.** Before starting any task — cross-domain or not — an agent must first investigate the current state (relevant files, existing git status, whether related work is already in progress or partially built elsewhere) and report findings back before making changes. This is a report-then-proceed step, not a silent green light: if the check surfaces something ambiguous (unclear ownership, conflicting in-progress work, a missing dependency), the agent should stop and flag it rather than guessing and continuing.
4. **Cross-domain changes must be minimal and clearly reported.** When any agent crosses into another's primary domain — e.g. Cline or Antigravity CLI touching frontend files, OpenCode touching backend files — the change should be the smallest set of edits needed to complete the task, and must be explicitly called out in the agent's report (e.g., "Backend change required this frontend update: ...") rather than folded in silently — so cross-domain work is always visible and reviewable, not assumed.
5. **If a concurrent refactor is detected**, halt autonomous deletions, inspect the newly modified live code, and reconcile the Git index rather than overwriting active work.

## In-Flight Work (do not overlap)

Work listed here is actively being edited by another agent. Do not touch the listed files until it is committed and pushed; check `git status` and `git branch --show-current` first, and confirm the working tree is clean for those paths before starting.

- **Antigravity CLI — backend data integrity** (`fix/security-vulnerabilities-and-integrity`, uncommitted at last check):
  - Electorate-scope eligibility enforcement via `Position::voterIsInScope` in `CandidateController::store/update` and `AdminCandidateController::store`.
  - Party seat-cap guards on admin-created candidacy and on candidacy update (prevents switching into a full party).
  - Self-registration field propagation from registrar imports (`RegistrationController`): `year_level`, `block_number`, `department`, `course`, `is_active`, `has_voted`.
  - Individual account unlock recovery (`AdminUserController::unlock` now accepts the `Request`).
  - nginx real-client-IP hardening (`deploy/nginx-funnel-api.conf` → `HTTP_X_FORWARDED_FOR $remote_addr`).
  - Matching feature tests: `CandidacySeatCapTest`, `LoginBackoffRecoveryTest`, `SelfRegistrationActivationCodeTest`.
  - **Files other agents must leave untouched until this lands:** `AdminCandidateController.php`, `AdminUserController.php`, `CandidateController.php`, `RegistrationController.php`, and the three tests above.

## Pending Handoffs

Read the relevant handoff fully before starting. Do not redo completed work or re-derive verified findings.

- **Cline (backend/nginx):** `docs/HANDOFF_TO_CLINE_ADMIN_HARDENING.md` — secure the public admin console (nginx HTTP Basic gate, real client IP, env/guard hardening, admin-auth audit).
- **OpenCode (React admin):** `docs/HANDOFF_TO_OPENCODE_AUDIT_LOG.md` — Audit Log screen, `noindex` meta, wiring `GET /api/admin/audit-logs`.

**Always work on a feature branch, never on `main`.** Create the branch named in the handoff (e.g. `security/admin-hardening-backend` for Cline, `security/admin-audit-ui` for OpenCode) before making changes, and merge via PR.