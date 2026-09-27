# Multi-Agent Jurisdiction & Concurrency Policy

To prevent code conflicts, duplicate file rewrites, and race conditions across autonomous tools (Cline, OpenCode, and GitHub Copilot), all development must strictly adhere to the following domain boundaries. Cline is primarily backend, OpenCode is primarily frontend — but either may cross into the other's domain when a task genuinely requires full-stack work, subject to the cross-domain rules in the Execution Protocol below.

## 1. Cline — Architecture & System State (Primary: Backend)
- **Primary Jurisdiction:** Database migrations, seeders, Laravel backend controllers/routes/middleware, global Git/repo cleanup, and core project configuration.
- **Cross-Domain Allowance:** May touch frontend files (Flutter or React) when a task genuinely requires it end-to-end — e.g., a backend change that needs a matching frontend update to actually work (a new field, a changed response shape, a new endpoint that needs to be wired into a screen). This is not a green light for general frontend feature work or styling — only the minimum needed to make a backend change actually usable.
- **Rule:** Do not modify individual UI screens or feature-level frontend widgets for reasons unrelated to a backend change. Always check git status before mass file operations, and especially before touching any file outside the primary backend jurisdiction.

## 2. OpenCode — Feature Implementation & Frontend Logic (Primary: Frontend)
- **Primary Jurisdiction:** Flutter UI features (`user-flutter/lib/features/`), state providers, local service layers, React admin components (`admin-react/src/`), and screen routing.
- **Cross-Domain Allowance:** May touch backend files (Laravel controllers, routes, migrations) when a task genuinely requires it end-to-end — e.g., a new frontend feature that needs a new endpoint, a new field, or a small backend fix to function correctly. This is not a green light for general backend architecture work — only the minimum needed to make the frontend feature actually work.
- **Rule:** Do not modify core database schema design, backend routing structure, or global project configuration beyond what's strictly needed to support the frontend feature at hand. Build upon existing models and endpoints where possible; extend rather than restructure.

## 3. GitHub Copilot — Inline Acceleration Only
- **Jurisdiction:** Real-time editor assistance, boilerplate code, method completions, and docstrings.
- **Rule:** Restricted strictly to active file editing in the IDE. No autonomous multi-file refactoring, no cross-domain access, and no background terminal command execution.

## Execution Protocol for Concurrency

1. **Never edit overlapping files concurrently.** If a file is currently modified or staged by one agent, other agents must leave it untouched.
2. **Always run a pre-check (`git status`)** before initiating major file modifications or automated cleanup loops — this applies doubly when an agent is about to cross into the other's primary domain.
3. **Check first, then report back before proceeding.** Before starting any task — cross-domain or not — an agent must first investigate the current state (relevant files, existing git status, whether related work is already in progress or partially built elsewhere) and report findings back before making changes. This is a report-then-proceed step, not a silent green light: if the check surfaces something ambiguous (unclear ownership, conflicting in-progress work, a missing dependency), the agent should stop and flag it rather than guessing and continuing.
4. **Cross-domain changes must be minimal and clearly reported.** When Cline touches frontend files or OpenCode touches backend files, the change should be the smallest set of edits needed to complete the task, and must be explicitly called out in the agent's report (e.g., "Backend change required this frontend update: ...") rather than folded in silently — so cross-domain work is always visible and reviewable, not assumed.
5. **If a concurrent refactor is detected**, halt autonomous deletions, inspect the newly modified live code, and reconcile the Git index rather than overwriting active work.