---
name: "12 — Quality and Launch"
description: "Validate the React SPA/Laravel API, security, SEO, performance, backups, and Ubuntu 24.04 production deployment."
argument-hint: "Optional release candidate or QA focus"
---
# Phase 12 — QA, Security, SEO, Performance and Deployment

Act as a Senior QA, AppSec and DevOps engineer. Read the instructions, [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DATABASE.md](../../docs/DATABASE.md), [DECISIONS.md](../../docs/DECISIONS.md), [ACCEPTANCE-CHECKLIST.md](../../docs/ACCEPTANCE-CHECKLIST.md) and [PRODUCTION-CHECKLIST.md](../../docs/PRODUCTION-CHECKLIST.md).

Do not deploy to production or run destructive commands without explicit approval.

Tasks:
1. Run backend/frontend test suites, lint, type checks and production frontend build; report exact commands/results.
2. Add/complete Playwright E2E for registration, browse, cart, checkout, manager processing and inventory.
3. Test authorization matrix and direct API access, Sanctum cookie sessions and CSRF.
4. Review validation, rate limiting, media upload/Cloudinary secrets, CSRF/session, XSS, SQL injection risks and secret handling.
5. Review order idempotency, immutable snapshots, payment/order state separation and inventory concurrency.
6. Verify backups and restore procedure.
7. Add structured logs, error monitoring and database queue health checks where applicable.
8. SEO: metadata, canonical URLs, sitemap, robots, accurate structured product data and 404 behavior. Do not add SSR/Next.js as a shortcut.
9. Performance: image optimization, caching, pagination, query review and Core Web Vitals.
10. Prepare Ubuntu 24.04 deployment and rollback runbooks for Nginx + PHP-FPM + PostgreSQL.
11. Verify Nginx serves the built React `dist/`, passes `/api/*` and `/sanctum/*` to Laravel, and never requires a Node.js production process.
12. Review VPS memory constraints (initial assumption 1 vCPU / 1 GB RAM / 25 GB NVMe), service settings and measured load; record capacity risk and upgrade criteria.
13. Review privacy notice, consent and personal-data handling for target market with qualified local review where needed.

Do not add Docker, Redis, microservices or SSR by default. Update [PRODUCTION-CHECKLIST.md](../../docs/PRODUCTION-CHECKLIST.md) and create/update release, deployment and known-limitations docs consistent with the repository. Report actual tests, unresolved decisions, risks and launch blockers. Never claim production readiness while critical checks fail.
