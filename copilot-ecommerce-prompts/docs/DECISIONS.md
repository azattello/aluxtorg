# Unresolved Business Decisions

This log separates confirmed scope from decisions that belong to the product owner. No implementation prompt may treat an unresolved item as an approved rule. Record the answer, date and any resulting scope/schema changes here.

## Required before checkout/domain implementation

| ID | Decision | Options / context | Status |
|---|---|---|---|
| D-01 | Is customer registration mandatory, or may guests submit orders? | Owner confirmed: account is optional; guest checkout is allowed, and a customer may create an account. | Confirmed |
| D-02 | Which customer contact fields are required at checkout? | Owner confirmed: name and phone are required; email is optional. Exact phone validation/normalization should be defined during implementation. | Confirmed |
| D-03 | When and how is stock reserved? | Owner chose reservation after manager confirmation, not on initial checkout. At confirmation, availability must be checked and stock reserved atomically. Keep the reservation until a manager cancels/closes the order or stock is dispatched; no automatic time expiry. Release must be idempotent. Dispatch consumes the reservation; a later cancellation must not release that stock a second time. | Confirmed baseline |
| D-04 | How many warehouses/stock locations are in the initial release? | Owner confirmed one warehouse/location for the initial release. | Confirmed |
| D-05 | What are the order and payment lifecycles? | Owner approved the proposed baseline. Normal order path: `new → confirmed → preparing → ready_for_pickup → completed` or `preparing → shipped → completed`; cancellation is allowed from a nonterminal status before completion. Payment: `unpaid → paid → partially_refunded → refunded`, with direct `paid → refunded` also allowed when applicable. Manager may update payment status based on verified offline payment. Enforce role permissions and allowed transitions server-side; cancellation after dispatch does not restore stock automatically. | Confirmed baseline |
| D-06 | What delivery and pickup data is required? | Owner confirmed (2026-11-11): delivery is available throughout Kazakhstan; the customer enters the full delivery address, and the manager/administrator manually agrees the delivery cost and timing with the customer in WhatsApp. Both delivery and pickup are supported. There is no fixed pickup address; pickup details are also coordinated manually in WhatsApp. No carrier/tariff automation or automatic WhatsApp integration is implied. Checkout still requires name and phone; delivery address includes city, street and building, with apartment/instructions optional. | Confirmed baseline |
| D-07 | Are returns/refunds in the initial release? | Owner confirmed a 14-day return period for goods in intact packaging; cut-to-length fabric/material is not returnable. The proposed period starts on receipt of goods. Implement only after checking this policy against applicable Kazakhstan consumer law; payment refund workflow remains separate from order status. | Confirmed, legal review required |
| D-08 | What currency, price precision, tax/VAT and rounding rules apply? | Owner confirmed KZT (tenge), whole-tenge prices, and no tax calculation in v1 until a separate accounting decision. Do not silently add a tax line; tax treatment must be reviewed before launch if legally required. Store monetary values exactly (not float) and format KZT without fractional digits. | Confirmed for v1; tax policy deferred |
| D-16 | How are purchasable product variants measured and priced when sold by length/weight rather than as whole pieces? | Owner approved product variants/SKUs and chose whole-unit quantities only in v1. Quantities are integers; no fractional quantity × price calculation or rounding is needed. A variant may label its sale unit (for example, piece or metre), but fractional cuts/weights are out of v1. | Confirmed for v1 |

## Required before the corresponding feature is implemented

| ID | Decision | Options / context | Status |
|---|---|---|---|
| D-09 | Are product reviews, loyalty, coupon/discount rules or customer-visible order messages in v1? | Scope, eligibility and moderation/rules must be specified before implementation. | Open |
| D-10 | Which customer-facing order status changes trigger notifications? | Owner approved: send an order-received email whenever checkout includes an email address; send email for confirmed, preparing, ready-for-pickup, shipped, completed and cancelled only after a separate unchecked-by-default opt-in at checkout. Every status email must include a signed unsubscribe link. For order events, send managers a Telegram alert for new orders only; keep it minimal (order number and CRM direction), with no customer contact/address or internal notes. Other operational alert types are decided separately. | Confirmed |
| D-11 | What privacy/analytics consent policy applies? | Owner approved no third-party analytics, advertising tags or UTM attribution in v1. Keep GA4/GTM/Ads disabled; any future addition requires a separate owner decision, consent/retention policy, lawful-basis review and Kazakhstan legal review. | Scope confirmed for v1; launch privacy review remains |
| D-12 | Which user roles can create or change roles and system settings? | Owner confirmed: super administrator has full access; administrator manages catalog and warehouse operations but not roles/system settings; manager handles CRM/orders, may edit catalog, conduct stock movements, and update payment status based on verified offline payment; warehouse operator is limited to warehouse work. Enforce all permissions server-side and audit sensitive actions. | Confirmed |
| D-13 | What are the launch catalog scale and expected traffic? | Product count, image volume, traffic/concurrency and growth expectations determine VPS sizing and performance targets. | Open |
| D-17 | What low-stock threshold, alert channel and repeat policy apply? | Owner approved one global threshold of 2 available units (on-hand minus reserved), alerts on crossing down to or below the threshold, and re-alerting only after restock raises availability above it and it crosses down again. Show alerts in the warehouse screen and send Telegram alerts to the manager chat. | Confirmed |

## Confirmed design direction

| ID | Decision | Answer | Status |
|---|---|---|---|
| D-14 | What visual direction should the storefront and admin use? | Modern minimalism: clean layouts, calm neutral palette, product-focused presentation. No existing brand guide or reference sites have been provided; Phase 03 should propose a restrained accent palette and document its rationale. | Confirmed |
| D-15 | What is the initial color theme? | Light theme. Do not add a dark theme unless requested later. | Confirmed |

## Technical defaults (not business decisions)

- Target architecture is fixed by the project owner: React + TypeScript + Vite, Tailwind CSS + shadcn/ui, Laravel REST API, PostgreSQL, Sanctum SPA cookie authentication, Cloudinary, Nginx + PHP-FPM on Ubuntu 24.04.
- Production frontend is static `dist/`; Node.js is not a production runtime.
- Redis, Docker, Inertia.js, Next.js, SSR and microservices are excluded unless the owner explicitly approves a documented exception.
- The target initial order flow has no online payment gateway or automatic carrier integration; managers coordinate payment and delivery.
- The initial VPS planning profile (1 vCPU / 1 GB RAM / 25 GB NVMe) is constrained and must be validated with measurements.
- The first super-administrator account is bootstrapped by a one-time `app:make-super-admin` Artisan command for an existing verified account, only when none exists. The command requires interactive confirmation and writes an audit event. Subsequent role assignments are restricted to a super administrator.

## Decision process

Before a feature starts, identify the open decisions that affect it and ask the owner to resolve them. Update the status and record the answer here before changing the data model or behavior. Do not block unrelated work on decisions that do not affect it.
