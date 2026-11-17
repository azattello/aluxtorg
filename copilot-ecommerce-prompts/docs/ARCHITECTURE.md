# Application Architecture

## Target stack

- Frontend: React + TypeScript + Vite SPA.
- UI: Tailwind CSS + shadcn/ui; Framer Motion only where it improves an interaction.
- Backend: Laravel JSON REST API.
- Database: PostgreSQL.
- First-party authentication: Laravel Sanctum SPA cookie/session authentication with CSRF protection.
- Media: Cloudinary.
- Production OS and web tier: Ubuntu 24.04 LTS, Nginx and PHP-FPM.

This document defines the application target. In the workspace root, the application scaffold is in `frontend/` and `backend/`; the `copilot-ecommerce-prompts/` folder contains the shared instructions, prompts and product documentation.

## Current implementation status

- The React + TypeScript + Vite frontend shell and Laravel API scaffold exist; the API health route and Sanctum SPA middleware/CSRF endpoint are in place.
- Identity and access are implemented with Sanctum sessions, email verification, password reset, customer profile, role gates, audited role administration and first-super-admin bootstrap.
- The catalog milestone adds public category/product/banner APIs, paginated search/filter/sort, SEO-friendly category/product SPA routes, product and category management, media metadata, Cloudinary-backed image endpoints and the React storefront/catalog admin. Cart, checkout, order processing and inventory operations are not implemented yet.
- The owner-approved domain schema, Eloquent models, factories and initial warehouse seeder remain the foundation for future phases.
- Backend feature tests and frontend production builds cover the completed identity and catalog flows. PostgreSQL verification is performed against the local database when the CLI PDO extension is enabled.
- PostgreSQL 17 is installed and running locally on `127.0.0.1:5432`. The app uses a dedicated `ecommerce_app` role and `ecommerce_dev` database; credentials are stored only in the ignored `backend/.env`. The PHP `pdo_pgsql` DLL is loaded explicitly for CLI commands because it is disabled in the default PHP configuration. Base and domain migrations have been verified against PostgreSQL. See [DATABASE.md](DATABASE.md) for the approved model.

## Application boundaries

### React SPA

The SPA owns customer storefront and admin user interfaces: homepage and banners, category navigation, search/filter/sort, product details, cart, checkout, customer profile/order views, and operational/admin screens. It communicates with Laravel through JSON REST endpoints. It must not contain authoritative pricing, stock, order-state, or access-control rules.

Use the existing project router if one is selected. Keep API clients and request/response types centralized. Production assets are compiled to `dist/`; the deployed SPA is static and needs no Node.js runtime.

### Laravel REST API

Laravel owns validation, authentication, authorization, catalog and customer data, order creation and lifecycle, payment state, inventory, CRM, settings, notification dispatch, and audit history. New public endpoints should follow `/api/v1` unless an existing API convention has already been adopted. Return predictable JSON and appropriate HTTP status codes.

### PostgreSQL

PostgreSQL is the authoritative store for application records, order snapshots, inventory movements, audit history, and Cloudinary references. Schema and unresolved business rules are documented in [DATABASE.md](DATABASE.md) and [DECISIONS.md](DECISIONS.md).

### Cloudinary

Cloudinary stores product images, promotional banners, category images, and other approved media. Store `public_id`, `secure_url`, alt text, ordering, and any needed resource metadata in PostgreSQL. API secrets stay on the backend. Do not silently fall back to permanent local media storage in production.

The catalog backend signs Cloudinary image upload/delete calls using server-side credentials. Product images can only be attached to a product by an authorized catalog manager; banners are uploaded during an authorized banner create/update request. If Cloudinary credentials are absent or a provider request fails, return an explicit error and do not claim that media was stored.

## Authentication and API security

- Use Sanctum's first-party SPA mode, session cookies, and CSRF protection; do not use bearer-token storage in the browser.
- Prefer a same-origin production deployment so the SPA, `/api/*`, and `/sanctum/*` share the site's origin.
- Nginx serves the SPA's static `dist/` files and routes `/api/*` and `/sanctum/*` to Laravel through PHP-FPM.
- Configure Sanctum stateful domains, session cookie domain/security/SameSite settings, trusted proxies, CORS and CSRF for the actual hostnames; do not use permissive wildcard credentialed CORS.
- Enforce all permissions and business rules in Laravel even when the SPA hides controls.
- Never return secret keys or unnecessary personal data in API responses or analytics.
- Authentication API supports registration, login/logout, current-user/profile, signed email verification, password reset and super-admin-only role changes under `/api/v1/auth/*` and `/api/v1/admin/*`. The browser API client obtains the CSRF cookie and sends the matching header; auth cookies are never read or stored as JavaScript tokens.
- Accounts register as customers by default; privileged assignments require a verified email, the super-admin role and an audit event. Bootstrap the first super administrator only through the confirmed, one-time CLI command documented in the root [README](../../README.md).
- Catalog write routes require the server-side `manage-catalog` Gate (manager, administrator or super-administrator). Public product responses exclude unpublished products, inactive categories and inactive variants; admin product removal archives records rather than deleting order-linked history.
- Configure `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` for the deployed hostname. Use the same HTTPS origin for SPA and API in production; enable `SESSION_SECURE_COOKIE=true`. CORS credentials are limited to explicitly listed origins.

## Production request flow

1. Nginx returns static files from the deployed frontend `dist/` directory; client-side routes fall back to `index.html`.
2. Requests under `/api/` and `/sanctum/` are excluded from the SPA fallback and passed to Laravel's `public/index.php` via PHP-FPM.
3. Laravel validates/authenticates the request, applies domain rules, and reads/writes PostgreSQL.
4. Laravel uses Cloudinary's backend credentials for media operations and dispatches notifications only after durable application changes.

Deployments may choose a repository layout, but deployment configuration must explicitly identify the Laravel `public/` directory and React `dist/` directory. Do not assume a scaffold or create application features while working only on this prompt pack.

## Production runtime and deployment

- Ubuntu 24.04 LTS, Nginx, PHP-FPM and PostgreSQL are the target production services.
- Build the React app on a developer machine or CI; publish the generated `dist/` files. Never require Node.js or a Node process on the production server.
- Keep secrets in server-side environment/configuration, not in frontend build variables that are shipped to browsers.
- Use TLS, secure cookies, least-privilege service accounts, backups, tested restore procedures, logs and monitoring.
- Use Laravel's database queue by default if asynchronous notifications are needed. A queue worker should be supervised (for example with systemd) and configured with bounded concurrency. Redis, Docker, SSR, microservices and Next.js are not part of this target and need explicit approval plus a documented resource/operational case.

## Constrained VPS profile

Initial planning assumption: 1 vCPU, 1 GB RAM, 25 GB NVMe. This is a severe shared-memory budget for PostgreSQL, PHP-FPM, Nginx, queue work and OS services. Do not build assets on this host. Start with conservative PHP-FPM worker counts and PostgreSQL memory/work settings, use a small database-backed queue worker only when required, and monitor memory, swap, disk and latency under representative load. Do not treat swap as a substitute for RAM. Record measured capacity and upgrade before production load exceeds it; 2–4 GB RAM is a planning recommendation, not a guaranteed sizing result.

## Explicitly out of the default architecture

- Inertia.js and Laravel Blade as the primary application frontend.
- Next.js and server-side rendering.
- Redis and Docker as required runtime/development infrastructure.
- Microservices.
- Online payment gateway and automatic carrier integration in the initial release.

An exception requires owner approval and updates to architecture, roadmap, deployment docs and tests before implementation.

## Business behavior to preserve

- The initial order flow collects orders without online payment; managers coordinate payment and delivery.
- Order state and payment state remain separate.
- The backend recalculates totals and validates availability.
- Order items retain snapshots even if catalog data changes later.
- Telegram/email failures cannot cancel or lose an order.
- Preserve catalog, customer account, admin, CRM, inventory, analytics, SEO, accessibility and privacy requirements from [PRODUCT-OVERVIEW.md](PRODUCT-OVERVIEW.md) and [ACCEPTANCE-CHECKLIST.md](ACCEPTANCE-CHECKLIST.md).
