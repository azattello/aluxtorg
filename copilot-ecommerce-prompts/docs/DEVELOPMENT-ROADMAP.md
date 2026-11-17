# Development Roadmap

Run the Prompt Files in `.github/prompts/` in numeric order. Each prompt is one bounded phase; read the project instructions and linked documents first, review the result and tests, then explicitly approve moving to the next phase. Do not start implementation until product discovery and the technical foundation are accepted.

Current status (2026-11-15): the owner requested a full project audit and continuation beyond the catalog milestone. The audit is recorded in the repository root [PROJECT-AUDIT.md](../../PROJECT-AUDIT.md). Gates 0–5 have not been accepted as production-ready. Gate 6 has the guest cart/checkout, server-priced order intake, idempotency and customer order history. Gate 7 is in progress: manager order inbox/detail, assignment, private notes, delivery fee, payment/status history and permission-checked lifecycle transitions are implemented. Confirmation reserves stock transactionally; cancellation releases active reservations; dispatch and pickup completion consume them. Gate 8 is in progress: warehouse balance lookup, receipt, physical-count reconciliation, movement history and low-stock alerts are implemented with server-side role checks and audit records. PostgreSQL concurrency verification remains open. D-06 is confirmed: Kazakhstan-wide delivery and manual WhatsApp coordination for delivery/pickup; no fixed pickup point or automatic WhatsApp/carrier integration. D-17 sets a global low-stock threshold of 2 available units, dashboard and Telegram alerts, and re-alerting only after a recovery above threshold. D-11 excludes third-party analytics/marketing tags and UTM attribution from v1; legal review of operational/customer data remains. Do not silently include optional features while D-09 is unresolved.

## Gate 0 — Product discovery

Deliverables: confirmed requirements, glossary, assumptions, MVP boundary, customer/manager/warehouse/admin journeys and acceptance criteria. Record unresolved product-owner choices in [DECISIONS.md](DECISIONS.md).

Exit: owner resolves the decisions required for the next implementation phase. Do not silently invent guest checkout, reservation, warehouse, payment, delivery or return policy.

## Gate 1 — Technical foundation

Deliverables: Laravel REST API + React/TypeScript/Vite SPA foundation; PostgreSQL; Sanctum SPA cookie/CSRF configuration; Tailwind CSS + shadcn/ui; environment example, formatting/lint/type/test commands, health endpoint and CI where repository conventions permit.

Build the SPA to `dist/`. Production uses Nginx static file serving plus PHP-FPM for Laravel; Node is required only for local/CI builds. Do not scaffold Inertia, Blade as the primary frontend, Next.js, SSR, Redis or Docker. Do not add business features in this gate.

Exit: clean local setup, API/SPA connection, database migrations, Sanctum browser flow, lint/type checks and test commands documented. Document exact package versions and validate current compatibility before pinning them.

## Gate 2 — UX and design system

Deliverables: sitemap, user flows, tokens, typography, responsive rules, component inventory and storefront/admin shell specifications.

Exit: reviewable, approved home, catalog, product, checkout and admin prototypes; no implementation of all business pages yet.

## Gate 3 — Domain model

Deliverables: reviewed ERD, reversible migrations, factories/seeders, enums/state transitions and audit model based on approved answers in [DECISIONS.md](DECISIONS.md).

Exit: constraints and core relationships tested; order and payment lifecycles are separate.

## Gate 4 — Identity and access

Deliverables: registration, verification, login/logout, password reset, customer profile and approved roles/permissions implemented with Sanctum SPA sessions and server-side policies.

Exit: role matrix, CSRF/session and authorization tests pass.

## Gate 5 — Catalog and content

Deliverables: categories, products, media, SKU, prices, attributes, search/filter/sort and banners; Cloudinary references protected behind backend credentials.

Exit: admin can publish a product and a customer can find/view it through the SPA and REST API. Verify image uploads with Cloudinary test fakes; real provider uploads require configured backend credentials.

## Gate 6 — Cart and order intake

Deliverables: cart, checkout, server-side price/stock validation, immutable order snapshots, idempotency and customer confirmation.

Exit: order submission passes end-to-end without online payment; reservation behavior follows owner-approved policy.

## Gate 7 — CRM and workflow

Deliverables: manager order inbox/detail, assignment, internal notes, status history, filters, customer timeline and transition-safe inventory reservation.

Exit: managers process orders with audited, permission-checked transitions; incomplete inventory operations remain a Gate 8 blocker.

## Gate 8 — Inventory

Deliverables: approved warehouse model, receipts, adjustments, movements, reservations and low-stock alerts. Order confirmation/cancellation/dispatch reservation handling is implemented as part of Gate 7; warehouse balance lookup, receipt and reconciliation UI/API are implemented. D-17 is confirmed: available quantity ≤2, warehouse dashboard and Telegram notification on downward crossing, re-armed after restocking above threshold.

Exit: concurrency and cancellation tests pass; no overselling or duplicate release under the approved policy.

## Gate 9 — Notifications and analytics

Notifications are implemented: database-queue-backed order email and minimal new-order Telegram alerts, bounded retries, failed-job logging, explicit customer opt-in for status changes, and signed unsubscribe confirmation. Production credentials and worker monitoring remain operational tasks.

Analytics and UTM attribution are out of scope for v1 by owner decision D-11. Reconsider them only after a separate approval, consent/retention policy and Kazakhstan legal review.

Exit: notification behavior tests pass; no third-party tracking tags or UTM attribution are loaded in v1.

## Gate 10 — Hardening and launch

Deliverables: security/privacy review, SEO, performance, backups, monitoring and Ubuntu 24.04 Nginx/PHP-FPM deployment/rollback runbooks. Dynamic sitemap/robots and product metadata are implemented; production Nginx routing and guaranteed HTTP 404 behavior still need wiring and verification.

Exit: [ACCEPTANCE-CHECKLIST.md](ACCEPTANCE-CHECKLIST.md) and [PRODUCTION-CHECKLIST.md](PRODUCTION-CHECKLIST.md) are reviewed; restore and rollback are tested; the VPS has measured capacity for expected load.

## Delivery cadence

Work in small PR-sized increments. Preserve existing store requirements; do not combine all modules into one Copilot request. Do not deploy or run destructive production operations without explicit approval.
