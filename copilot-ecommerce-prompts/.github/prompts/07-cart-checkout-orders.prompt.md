---
name: "07 — Cart and Orders"
description: "Implement cart, no-payment checkout, and durable order creation through the Laravel REST API."
argument-hint: "Optional checkout or order intake subarea"
---
# Phase 07 — Cart, Checkout and Order Creation

Implement cart and order intake. No online payment gateway and no automatic carrier integration in v1. Read [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DATABASE.md](../../docs/DATABASE.md), [DECISIONS.md](../../docs/DECISIONS.md) and relevant tests first.

Customer flow:
1. Add/remove products and change quantities.
2. Show cart totals using server-derived prices.
3. Collect the owner-approved customer contact and delivery/pickup information.
4. Follow the approved registration/guest-checkout policy; do not assume one.
5. Submit order and show confirmation with order number.
6. Allow customers to view only their own orders and permitted status details.

Backend rules:
- Recalculate prices and totals server-side; never trust browser totals.
- Validate active product and available quantity.
- Store immutable item and required contact/delivery snapshots.
- Keep order status separate from payment status.
- Make submission idempotent and safe under concurrent requests.
- Use transaction boundaries and the stock reservation policy approved in `DECISIONS.md`.
- Persist the order before dispatching notification work.
- Store UTM/source attribution only as permitted by consent and privacy policy.

Tests: invalid quantity, changed price, out-of-stock, duplicate submit, guest/customer permissions, concurrency, order snapshot integrity and notification dispatch failure behavior.

Do not add online payment or delivery-provider integration. Stop after checkout/order intake and report results before Phase 08.
