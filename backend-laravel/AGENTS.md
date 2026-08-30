# Multi-Agent Jurisdiction & Concurrency Policy

To prevent code conflicts, duplicate file rewrites, and race conditions across autonomous tools (Cline, OpenCode, and GitHub Copilot), all development must strictly adhere to the following domain boundaries:

## 1. Cline — Architecture & System State
- **Jurisdiction:** Database migrations, seeders, Laravel backend controllers/routes/middleware, global Git/repo cleanup, and core project configuration.
- **Rule:** Do not modify individual UI screens or feature-level frontend widgets unless requested for an API integration fix. Always check git status before mass file operations.

## 2. OpenCode — Feature Implementation & Frontend Logic
- **Jurisdiction:** Flutter UI features (`user-flutter/lib/features/`), state providers, local service layers, React admin components (`admin-react/src/`), and screen routing.
- **Rule:** Do not modify core database tables, backend routing files, or global project structures. Build upon existing models and endpoints provided by the backend layer.

## 3. GitHub Copilot — Inline Acceleration Only
- **Jurisdiction:** Real-time editor assistance, boilerplate code, method completions, and docstrings.
- **Rule:** Restricted strictly to active file editing in the IDE. No autonomous multi-file refactoring or background terminal command execution.

## Execution Protocol for Concurrency
1. **Never edit overlapping files concurrently.** If a file is currently modified or staged by one agent, other agents must leave it untouched.
2. **Always run a pre-check (`git status`)** before initiating major file modifications or automated cleanup loops.
3. **If a concurrent refactor is detected**, halt autonomous deletions, inspect the newly modified live code, and reconcile the Git index rather than overwriting active work.
