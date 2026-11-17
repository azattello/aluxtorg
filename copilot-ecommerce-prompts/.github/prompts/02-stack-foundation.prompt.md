---
name: "02 — Stack Foundation"
description: "Set up the approved React/Vite SPA, Laravel REST API, PostgreSQL, Sanctum cookie auth, and local/CI checks."
argument-hint: "Optional repository path or setup constraint"
---
# Phase 02 — Stack Foundation

Follow [copilot-instructions.md](../copilot-instructions.md), the frontend/backend instructions, [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DATABASE.md](../../docs/DATABASE.md), and [DECISIONS.md](../../docs/DECISIONS.md).

First inspect the repository, toolchain and environment. Do not migrate an existing project automatically; report incompatible infrastructure and request approval before destructive or broad changes. The approved target is React + TypeScript + Vite SPA, Tailwind CSS + shadcn/ui, Laravel JSON REST API, PostgreSQL, and Sanctum SPA cookie/session authentication with CSRF.

Tasks:
1. Verify current stable, mutually compatible package versions from official documentation where available; record exact versions and any verification gap.
2. Establish separate SPA and Laravel API boundaries consistent with existing repository layout. Scaffold only the foundation; do not build catalog, checkout, admin or other business features.
3. Configure local environment examples, PostgreSQL, formatting/linting/type checks, test commands, and a health endpoint.
4. Configure and verify the first-party Sanctum SPA session and CSRF flow without browser bearer tokens or `localStorage` tokens.
5. Add CI checks where repository conventions permit. CI may build the frontend; production serves only static `dist/` files.
6. Document local setup, API/SPA URLs, build artifact and commands in the appropriate setup documentation.

Do not use Inertia, Blade as the primary frontend, Next.js, SSR, Redis, Docker or microservices. Do not add a Node.js production service. Do not install/run server infrastructure without consent.

Run focused install/build/test/type/lint checks and report exact output. Stop after the foundation and report blockers; do not begin Phase 03.
