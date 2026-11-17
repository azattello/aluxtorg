---
description: "Use when writing or running Laravel/Pest/PHPUnit, React/Vitest, API integration, authorization, or Playwright end-to-end tests."
applyTo: "**/*.{php,ts,tsx,js,jsx}"
---
# Testing instructions

- Test happy paths, validation failures, unauthenticated/unauthorized access, and relevant boundary/concurrency cases.
- API tests must verify JSON response shapes, status codes, permissions and Sanctum cookie/CSRF behavior where applicable.
- An order cannot be created with zero/negative quantity, an inactive product, or an unavailable quantity. Recalculate prices and totals server-side.
- Verify idempotent order submission and immutable order-item snapshots after later product edits.
- Verify order/payment status separation and that reservation release happens exactly once on approved cancellation/expiry flows.
- Verify managers cannot access administrator or super-administrator capabilities by changing URLs or direct API requests.
- Notification-provider failure must not delete or roll back a persisted order; test retry/error state through the configured queue mechanism.
- Cover storefront and manager journeys with E2E tests; keep tests deterministic and avoid production services or credentials.
- Run the smallest relevant test set first, then required build/type/lint checks. Report commands and actual results; do not claim unrun tests passed.
