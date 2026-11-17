# Aluxtorg

The application is split into two projects alongside the Copilot prompt pack:

- `frontend/` — React + TypeScript + Vite SPA, Tailwind CSS and shadcn/ui.
- `backend/` — Laravel REST API, PostgreSQL and Sanctum SPA cookie authentication.
- `copilot-ecommerce-prompts/` — project instructions, phased prompts and product documentation.

The current implementation includes the Aluxtorg storefront, Kazakhstan-focused marketing homepage, product pages, account access, catalog administration, editable store contacts, first-party aggregate analytics, Cloudinary media endpoints and scheduled homepage banners. Registration requires a phone number; email is optional, and customers can sign in with either. Cart and checkout require a signed-in customer; customer order history/details, 14-day whole-package return requests, manager return processing, order CRM, stock reservations, warehouse receipts/reconciliation and movement history are also implemented. Several decisions, integrations and production-readiness checks remain open.

See [PROJECT-AUDIT.md](./PROJECT-AUDIT.md) for the verified feature/screen audit, test results and prioritized work required for full project readiness.

Manager and administrator tools are available at `/admin/orders`, `/admin/returns`, `/admin/warehouse`, `/admin/catalog`, `/admin/analytics` and `/admin/settings`; `/account` contains customer order history and links to `/account/orders/{id}`. Access is enforced by backend roles. Desktop admin pages use a role-aware sidebar; on mobile, role-appropriate bottom tabs remain available. User, order, return and product lists can switch between cards and tables; supported lists provide server-side search, filters, sorting and pagination.

## Local development

Requirements: PHP with `pdo_pgsql`, Composer for the API, Node.js/npm for the SPA, and PostgreSQL.

1. Configure `backend/.env` using `backend/.env.example` and create a PostgreSQL database/user. The current local setup uses database `ecommerce_dev` and role `ecommerce_app`; the password is in the ignored `backend/.env`.
2. From `backend/`, run `php artisan migrate` and `php artisan serve --host=127.0.0.1 --port=8000`. If the active PHP CLI configuration does not load PostgreSQL extensions, enable `php_pgsql` and `php_pdo_pgsql` there. On Windows, a one-command migration can load them with `php -d "extension=<PHP ext path>\php_pgsql.dll" -d "extension=<PHP ext path>\php_pdo_pgsql.dll" artisan migrate`. For a development server with the same one-command override, change to `backend/public` and run `php -d "extension=<PHP ext path>\php_pgsql.dll" -d "extension=<PHP ext path>\php_pdo_pgsql.dll" -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php`. Use Laravel's `server.php` router rather than `public\index.php` directly so existing static files and dynamic Laravel routes are handled correctly.
3. In a second terminal, from `frontend/`, run `npm install` once and `npm run dev`.
4. In a third terminal, from `backend/`, run `php artisan queue:work database --queue=notifications --tries=3 --timeout=20`. If this Windows PHP CLI does not load PostgreSQL extensions from `php.ini`, prepend `-d extension=php_pgsql.dll -d extension=php_pdo_pgsql.dll` as with the migration command. The database queue is used for order emails and Telegram alerts; check failed sends with `php artisan queue:failed`, and retry an inspected job with `php artisan queue:retry <id>`.
5. Open the Vite URL shown in the terminal. The Vite development server proxies `/api` and `/sanctum` to Laravel on port 8000.

## PostgreSQL concurrency tests

The regular feature suite uses SQLite. The PostgreSQL-only concurrency suite requires a dedicated, empty schema in the local test database because it runs `migrate:fresh` there. Create the schema once, then set `DB_CONNECTION=pgsql`, `DB_DATABASE` to the local development database and `DB_SEARCH_PATH` to that schema before running `php artisan test --filter=PostgreSqlConcurrencyTest`. Never point this suite at `public`, a production database or a schema containing application data.

## Catalog and media

- The storefront is at `/`; category and product pages use `/categories/{slug}` and `/products/{slug}`. Catalog management is at `/admin/catalog`.
- The public API includes `GET /api/v1/categories`, `GET /api/v1/products`, `GET /api/v1/products/{slug}` and `GET /api/v1/banners`.
- Catalog writes require a manager, administrator or super-administrator session. Public catalog responses only include published products from active categories.
- To load the three demo products and sample homepage hero/promo banners for local viewing, run `php artisan db:seed --class="Database\Seeders\DemoCatalogSeeder"` from `backend/`. This seeder is separate from the default database seeder and refuses to run in production; demo images use external sample URLs.
- Configure `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY` and `CLOUDINARY_API_SECRET` in the backend environment before uploading product or banner images. Credentials are only read by Laravel; there is no local permanent-storage fallback.
- Image uploads accept JPG, PNG and WebP files up to 5 MB. Without Cloudinary configuration, text-only catalog operations remain usable and media endpoints return an explicit service-unavailable error.

## Authentication setup

- The storefront opens at `/`; use `/account` to register or sign in. Registration requires a unique phone number and a password of at least 8 characters; email is optional. Sign-in accepts a phone number or email. Password recovery remains email-only; customers without an email must contact the store manager. In local development the example mailer writes verification/reset links to `backend/storage/logs/laravel.log`. Open verification links in the same browser used for the SPA.
- To bootstrap the first super administrator, register with an email address, verify it, then run `php artisan app:make-super-admin <email>` from `backend/` and confirm the prompt. The command only works when no super administrator exists; later role changes require an authenticated super administrator and are audited.
- Auth API endpoints are under `/api/v1/auth/`; super-administrators can manage accounts at `/admin/users` or use `GET /api/v1/admin/users` and `PATCH /api/v1/admin/users/{id}/role`. Role changes are audited; the SPA uses Sanctum session cookies and CSRF, not API tokens.
- For production, configure `FRONTEND_URL` to the public HTTPS origin, add that host to `SANCTUM_STATEFUL_DOMAINS`, set `CORS_ALLOWED_ORIGINS` to only trusted browser origins, and enable `SESSION_SECURE_COOKIE=true`. Keep the SPA and API same-origin where possible.

## Order notifications

- An order-received email is queued when checkout includes an email address. Status emails are sent only after the customer checks the separate, unchecked-by-default consent box; each status email has a signed unsubscribe confirmation.
- Configure a production mail transport with `MAIL_MAILER` and its credentials. The local `MAIL_MAILER=log` writes messages to `backend/storage/logs/laravel.log`.
- Configure `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` on the backend for new-order and low-stock alerts. New-order messages contain only the order number and a direction to open CRM. Low-stock alerts trigger when available stock (on-hand minus reserved) crosses down to 2 units; they re-arm after stock rises above 2, and include SKU/availability but no customer data.
- Keep a database queue worker running in production. Notification jobs try at most three times with increasing delays; failed jobs are recorded in `failed_jobs` and reported in application logs. Queue credentials and mail/Telegram secrets must never be added to the frontend.
- Third-party analytics, Google Ads tags and UTM attribution are not used. Storefront page views are aggregated by day and public page slug in the application database; visitor IPs, account IDs and device identifiers are not stored in the analytics table.

## Store contacts and analytics

- Administrators and super-administrators edit the public phone, WhatsApp, email, address, hours and social links at `/admin/settings`. No placeholder business contact values are published.
- `/admin/analytics` is restricted to administrators and super-administrators. It reports order counts and amounts, paid orders, distinct buyers, delivery cities, recent order details, daily page views and popular catalog pages for a selected period (up to 366 days).
- First-party page-view rows contain only the day, page type, catalog slug and aggregate count. This is separate from Google Ads/GA tracking and does not send data to third parties. Define data retention and complete Kazakhstan privacy/legal review before production.

## Orders and returns

- Creating a new order requires a signed-in customer account. The order is attached to that account and appears in its history; anonymous order submission is rejected by the API.
- A customer may request a return within 14 days of the order's completion date. Only catalog variants explicitly marked returnable are eligible, and cut-length units such as `м` are blocked even if the catalog flag is misconfigured. The customer confirms the original package is unused and unopened; staff verify it at handover.
- Customers submit and track requests at `/account/orders/{id}`. CRM staff review requests at `/admin/returns`, contact the customer through the WhatsApp link and update the status manually. `refund_processed` is a tracking status, not a payment-gateway refund.
- Returned inventory is not restocked automatically. A policy for verifying resale condition and recording stock movements must be agreed before adding restocking automation. The return rules require jurisdiction-specific legal review before public launch.

## SEO endpoints

- Laravel generates `/sitemap.xml` from active categories and published products, and `/robots.txt` excludes private account/checkout/admin routes.
- Production Nginx must route `/sitemap.xml` and `/robots.txt` to Laravel using the same public origin as the SPA. No production Nginx config is included yet; verify these endpoints and real HTTP 404 behavior when adding deployment configuration.

Build the static frontend with `npm run build` in `frontend/`. It outputs `frontend/dist/`; production Nginx serves those files, while PHP-FPM serves Laravel. Node.js is not a production runtime.

## Prompt workflow

See [copilot-ecommerce-prompts/README.md](./copilot-ecommerce-prompts/README.md) and its [development roadmap](./copilot-ecommerce-prompts/docs/DEVELOPMENT-ROADMAP.md). Resolve business questions in [DECISIONS.md](./copilot-ecommerce-prompts/docs/DECISIONS.md) before implementing features that depend on them.
