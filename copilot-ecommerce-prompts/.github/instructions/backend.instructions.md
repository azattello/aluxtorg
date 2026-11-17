---
description: "Use when implementing Laravel backend behavior, REST API endpoints, Sanctum authentication, authorization, validation, jobs, notifications, or integrations."
applyTo: "**/*.php"
---
# Backend instructions

- Follow the existing Laravel structure and conventions. Expose a JSON REST API (use `/api/v1` for new public API routes unless the project already has an established versioning convention).
- Validate input with Form Requests or the project's equivalent; enforce access through Policies/Gates/permissions on every protected endpoint.
- Keep controllers thin and business operations in focused Actions/Services where useful.
- Use Laravel Sanctum SPA cookie/session authentication with CSRF protection. Configure the SPA stateful domain, allowed origins, credentials and secure cookies for the actual deployment; do not issue browser bearer tokens for the first-party SPA.
- Keep browser and API same-origin in production where possible. Do not rely on frontend-only route protection.
- Critical order and inventory operations require database transactions and appropriate row locks/atomic updates.
- Never trust client-supplied prices, totals, roles, stock quantities or status transitions. Use exact monetary arithmetic, not float.
- Persist an order before dispatching external notifications. Queue/retry and log failures without rolling back the order.
- Use Cloudinary server-side credentials only. Validate media input and persist the provider public ID and secure URL/metadata needed by the application.
- Public errors must not reveal stack traces, SQL, credentials or sensitive customer data.
- The default constrained deployment does not require Redis or Docker. Use Laravel's database-backed queue when asynchronous work is needed unless an approved decision states otherwise.
