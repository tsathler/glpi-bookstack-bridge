# GLPI BookStack Bridge — Agent Instructions

## Project Overview

GLPI BookStack Bridge is a native PHP plugin for GLPI 11 that connects GLPI users to a BookStack knowledge base. BookStack remains the authoritative source for documentation. The intended product is a GLPI-integrated interface inspired by BookStack, not a replacement for it.

The current implementation is M0: administrative connection settings, a protected API token, a BookStack connectivity check, and experimental search. It is not yet a general-purpose knowledge browser. Browsing shelves, books, chapters, and pages; a full reader; and ticket links are future work. Selective one-way synchronization to GLPI's native knowledge base is a later possibility and is not part of the current implementation.

The plugin declares compatibility with GLPI `>=11.0.0,<11.1.0` and requires PHP 8.2 or newer, cURL, and Sodium. Consult [README.md](README.md) and [docs/roadmap.md](docs/roadmap.md) for the current scope and acceptance status.

## Architecture Rules

- Do not modify GLPI core or BookStack source code.
- Use official GLPI plugin hooks, lifecycle contracts, controllers, routes, and APIs, and BookStack's official REST API. Verify contracts against official documentation and the targeted GLPI 11 release before relying on them.
- Keep HTTP/API behavior, controllers, configuration, and security policy separated. Add services or repositories when real responsibilities require them; do not create empty layers for planned milestones. No service or repository classes currently exist.
- Never connect directly to the BookStack database.
- Prefer GLPI-native facilities for plugin configuration, secret protection, session permissions, CSRF, routing, and rendering where appropriate.
- Keep runtime dependencies minimal. Do not add packages unless the need and compatibility are justified.
- Favor GLPI 11 compatibility, least privilege, clear error handling, and maintainability over convenience or speculative abstractions.

## Development Workflow

Development happens locally on Windows. The GLPI test server is a separate environment; do not assume the agent can access it.

The intended workflow is:

1. Develop locally and inspect the existing worktree first.
2. Run relevant local checks and tests.
3. Review the changes and Git status.
4. Organize changes into coherent commits when the user has authorized committing.
5. Push only to an explicitly authorized remote and only when explicitly authorized.
6. Update the separate test server through Git only with explicit authorization and confirmed access.
7. Validate installation and behavior against real GLPI 11 and BookStack instances when available.
8. Record exactly what was validated, where, and what remains pending.

Never deploy, push, or modify a remote server without explicit authorization. Do not claim server access or real integration validation unless it actually occurred.

## Security Rules

- Never hardcode, commit, print, render, or log credentials, API token secrets, or other sensitive values. Do not add real credentials to tests or documentation.
- Preserve GLPI's `secured_configs` handling for the API token and use GLPI's security-key mechanism. Keep credentials out of browser-visible form values and responses.
- Keep GLPI session checks and authorization on every route. The current M0 screen is restricted to users with GLPI configuration update rights. Do not treat a hidden menu entry as authorization.
- Preserve GLPI CSRF protection for state-changing and form-submission routes.
- BookStack authorization belongs to the API token's user and BookStack role/content permissions. Use a dedicated, least-privileged read-only account; never assume the token grants safe access to all content for every GLPI user.
- Prevent SSRF: validate configured URLs, accept HTTPS only, reject unsafe destinations, verify TLS certificates, set timeouts, and do not follow redirects. The current policy blocks private and reserved addresses. Compatibility with internal/private BookStack infrastructure must be explicitly reviewed before deployment; do not remove these protections indiscriminately or silently.
- Treat BookStack content and API previews as untrusted. Escape output by default and sanitize HTML before any future rich-content rendering. Do not mark remote HTML safe in Twig without a well-defined sanitizer and tests.
- Validate request parameters and bound page sizes, query lengths, and other inputs.

## Coding Standards

- Use modern PHP compatible with the declared PHP 8.2 minimum. Add parameter and return types where practical; follow the existing typed controller/client style. The test runner uses `declare(strict_types=1)`; preserve strict typing in tests.
- Follow the existing PSR-4 namespace and directory convention: `GlpiPlugin\Bookstackbridge\` maps to `src/`, with subdirectories matching namespaces (`Api`, `Controller`, `Security`). Keep namespace and file-name casing consistent.
- Keep plugin metadata and GLPI hook registration in `setup.php`; lifecycle install/uninstall functions belong in `hook.php`. Put plugin classes in `src/` and Twig templates in `templates/`.
- Controllers should remain thin: enforce GLPI authorization, validate input, call the relevant component, and render a response. Put reusable API and security behavior in their existing components rather than duplicating it.
- Use `BookStackException` or a similarly consistent, user-safe error path. Do not expose raw transport errors, response bodies, token values, or stack traces to users. Do not silently swallow exceptions.
- Use Twig autoescaping for output. Keep any future CSS scoped to the plugin; add JavaScript only for a concrete need. There is currently no custom CSS or JavaScript.
- Tests currently use a standalone PHP runner with an injected simulated HTTP transport; do not introduce a test framework or other tooling without justification.
- Keep README and `docs/` aligned with actual behavior. Mark planned functionality as planned, not implemented.

## Testing and Validation

Distinguish these validation levels in reports:

- **Static checks:** syntax and metadata/package checks; these do not execute application behavior.
- **Unit tests:** isolated component behavior; the existing runner is a lightweight standalone test script, not a PHPUnit suite.
- **Simulated tests:** the current API tests inject responses and do not make network requests. Passing them does not prove BookStack connectivity.
- **Integration tests:** real HTTP/API interaction with a test BookStack instance.
- **Environment validation:** installation, activation, permissions, routes, forms, and encrypted configuration exercised in a real GLPI 11 instance.

Commands currently documented and available in this repository:

```powershell
php tests/run.php
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Run relevant checks after code changes. Do not describe simulated checks as real GLPI or BookStack validation. Record unavailable environments or extensions as pending prerequisites instead of inventing results.

## Git Rules

- Keep commits small, coherent, and descriptively named when committing is authorized.
- Never version credentials, tokens, `.env` files, or temporary artifacts.
- Do not force-push, rewrite history, or run destructive Git operations without explicit authorization.
- Preserve pre-existing user changes. Inspect `git status` and diffs before editing; do not reset, clean, or discard unrelated work.
- Do not publish code, create a remote release, or push automatically.

## Agent Behavior

- Inspect relevant source, documentation, and Git state before editing.
- Prefer official GLPI 11 and BookStack documentation and confirm version-specific contracts.
- Implement incrementally within the user's requested milestone. Preserve existing behavior and avoid overengineering or speculative abstractions.
- Run relevant existing checks after changes and report their exact scope and result.
- Update documentation when behavior, security, commands, or milestone status changes.
- Clearly separate implemented behavior, simulated validation, real-environment validation, known limitations, and pending work.
- Do not start another milestone automatically; wait for the user to request it.

## Project Milestones

- **M0 — Foundation and connectivity:** implemented locally; awaiting validation in real GLPI 11 and BookStack environments.
- **M0.1 — Test-server adjustments and validation:** planned; requires the separate server and explicit authorization for remote changes.
- **M1 — BookStack interface and navigation:** planned; shelves, books, chapters, pages, reader, and safe content rendering.
- **M2 — Integrated search:** planned expansion beyond the M0 experimental administrator-only search.
- **M3 — Ticket linking:** planned association of BookStack articles with GLPI tickets, including authorization and persistence.
- **M4 — MVP stabilization:** planned integration/security/authorization validation and release readiness.
- **Future version — Selective synchronization:** planned one-way synchronization into GLPI's native knowledge base; BookStack remains authoritative. Not implemented.

## Known Repository Inconsistencies

- No known repository inconsistencies are currently recorded here. Recheck source, documentation, and Git status as part of each task; do not assume this note remains current indefinitely.
