---
name: "05 — Authentication and Access"
description: "Implement Laravel Sanctum SPA cookie authentication, customer profiles, and server-enforced role access."
argument-hint: "Optional role or identity area"
---
# Phase 05 — Authentication, Customer Profile and Access Control

Implement identity and authorization only. Read project instructions, [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DECISIONS.md](../../docs/DECISIONS.md), approved design and existing auth packages.

Use Laravel Sanctum's first-party SPA session/cookie authentication with CSRF protection. The React + TypeScript + Vite SPA uses same-origin JSON REST endpoints in production. Never put tokens in `localStorage`, and do not use bearer-token SPA auth.

Requirements, subject to owner-approved policy:
- Registration, email verification, login/logout and password reset.
- Customer profile and approved saved contact/address data.
- Roles: customer, manager, warehouse operator, administrator, super administrator, with exact boundaries confirmed in `DECISIONS.md`.
- Server-side authorization via policies/permissions, protected admin APIs and audit trail for privileged role changes.

Tasks:
1. Verify existing Laravel/auth packages and use the simplest compatible solution.
2. Configure session, stateful domains, CSRF, secure cookies, rate limits and credentialed CORS only for explicit trusted origins.
3. Implement secure validation, auth API endpoints and role policies.
4. Build responsive auth/profile SPA screens using approved design tokens and REST APIs.
5. Add tests for auth outcomes, verification, reset, CSRF/session behavior and every role boundary.
6. Ensure managers cannot access admin/super-admin functions by manipulating URLs or direct API requests.

Do not build product/order modules. Update API/setup docs and report actual checks. Stop before Phase 06.
