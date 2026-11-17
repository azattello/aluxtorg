# Acceptance Checklist

Mark an item complete only after the relevant test or manual check is run and its result is recorded. Resolve applicable items in [DECISIONS.md](DECISIONS.md) before treating behavior as accepted.

## Storefront
- [ ] Admin can create, schedule, order and deactivate homepage banners; only active banners in their active period are shown.
- [ ] Category navigation, product-name/SKU search, filters and sorting return correct server-paginated results.
- [ ] Product detail displays current image order, SKU, server-provided price/availability, description and approved specifications.
- [ ] Storefront and admin are usable at 360px, 768px, 1024px and desktop widths without horizontal overflow in primary flows.
- [ ] Keyboard users can reach and operate every interactive control with visible focus; forms and errors have accessible labels/announcements.
- [ ] SEO metadata, canonical URLs, sitemap, robots, product structured data and 404 behavior are accurate for published content. (Dynamic sitemap/robots endpoints and storefront metadata are implemented; production Nginx routing and HTTP 404 behavior still need verification.)

## Customer and checkout
- [x] Guest can submit an order without registration; optional customer registration, email verification, login/logout and password reset work through Sanctum SPA cookie sessions and CSRF.
- [x] Checkout requires name and phone; email is optional; delivery requires city, street and building, with apartment and delivery instructions optional.
- [x] Both delivery and pickup can be selected; manager can confirm delivery coverage and cost.
- [ ] Prices are displayed/stored as whole KZT amounts without tax calculation in v1; no fractional/float rounding changes the amount.
- [x] Backend recalculates all prices and totals; tampered client totals do not affect the saved order.
- [x] Duplicate submission creates at most one order and returns a recoverable result to the customer.
- [x] Customer can view own order history and allowed status details, but cannot access another customer's order.
- [ ] Stored order-item and required contact/delivery snapshots remain unchanged after customer/product edits.
- [x] No online payment is requested or falsely reported as captured in the initial release.
- [x] Order states follow the approved baseline; payment states remain separate, and unpaid orders are never labeled paid.
- [x] Reservation is created atomically only when a manager confirms an order with sufficient available stock; it has no automatic expiry and is released on the approved manual close/cancel or dispatch event.
- [ ] Eligible packaged goods follow the approved 14-day return policy from receipt; cut-to-length fabric/material is excluded, subject to legal review.

## CRM
- [x] Manager can search/filter orders using approved criteria and open only authorized details.
- [ ] Status changes follow the approved transition matrix; invalid and concurrent transitions are rejected safely. (Invalid transitions are tested; PostgreSQL concurrent requests still need verification.)
- [x] Each change records actor and timestamp in append-only history.
- [x] Internal comments are not exposed through customer API or frontend.
- [x] Role permissions are enforced server-side for direct API requests, not only hidden in UI.
- [ ] Super administrator has full access; administrator manages catalog/warehouse but not roles/system settings; manager can process CRM/orders, edit catalog, conduct stock movements and update verified offline payment status; warehouse operator is limited to warehouse operations.

## Inventory
- [x] Receipts, adjustments and dispatches create auditable stock movements with actor, reason/source and quantity delta.
- [ ] Stock operations target the single approved initial warehouse.
- [ ] Concurrent orders cannot oversell under the approved warehouse/reservation policy.
- [x] Cancellation/expiry releases a reservation at most once.
- [x] Low-stock threshold follows the approved global policy (2 available units) and produces the expected alert on downward threshold crossing; it rearms only after stock rises above 2.
- [x] Stock movement history can be reconciled with displayed balance or documented balance derivation.

## Integrations
- [x] Telegram notification includes order number and only approved/necessary summary data. (The alert includes no customer contact, address or internal notes.)
- [x] Telegram outage does not lose or roll back an order. (The order commits before queued delivery; queue/API failures are logged and retried.)
- [ ] Failed notifications retry within configured bounds and permanent failure is visible to an authorized operator. (Jobs are limited to three attempts with increasing backoff; failures are logged and stored in `failed_jobs`, but the production worker/monitoring path has not been exercised.)
- [x] Email confirmation and customer status notifications follow the approved transition/consent policy. (Status mail requires explicit checkout opt-in and includes a signed unsubscribe confirmation.)
- [x] GA4/GTM/Ads and UTM attribution are intentionally disabled in v1 per D-11; do not enable third-party tracking without a new owner decision and privacy/legal review.
- [x] No UTM attribution is collected in v1 under the approved no-tracking scope.

## Operations
- [ ] Production secrets are not committed or included in frontend `dist/`.
- [ ] PostgreSQL connectivity and migrations are verified with `pdo_pgsql` in a clean environment.
- [ ] Backups and restore procedure tested.
- [ ] Error monitoring/logging and queue health visibility are enabled. (Failed jobs are recorded and logged; production monitoring and an in-app operator view are not configured.)
- [ ] HTTPS and secure Sanctum cookies enabled.
- [ ] Privacy and consent flows reviewed for the target market with qualified local review where required.
- [ ] Sanctum SPA cookie and CSRF flows work on the deployed same-origin site.
- [ ] React `dist/` is served by Nginx; `/api/*` and `/sanctum/*` reach Laravel through PHP-FPM.
- [ ] Node.js is not needed as a production runtime.
- [ ] Deployment and rollback are tested on Ubuntu 24.04.
- [ ] VPS memory and database/worker settings are validated under representative load.
- [ ] Relevant business decisions are resolved in [DECISIONS.md](DECISIONS.md).
