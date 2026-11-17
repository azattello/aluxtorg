# UX Flows

These flows describe the approved MVP interaction model for the customer SPA and operations UI. Unresolved policy is called out rather than hidden in interface behavior. See [PRODUCT-OVERVIEW.md](PRODUCT-OVERVIEW.md), [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) and [DECISIONS.md](DECISIONS.md).

## 1. Discover and browse

1. Customer arrives at the homepage, category or product from organic search/advertising.
2. The page shows accurate product/category content and active banners only.
3. Customer navigates categories or searches by product name/SKU.
4. Customer applies filters/sort; the API paginates results and URL/query state can be restored.
5. No results produce a useful message and a way to clear filters or broaden the query.
6. Opening a product shows ordered images, SKU, KZT price, server-provided availability, description and approved specifications.

**Failure handling:** API unavailable displays a recoverable error; do not render stale price/availability as current without explicit stale-data labeling.

## 2. Add to cart and submit an order

1. Customer selects quantity and adds an active product to cart.
2. Cart shows server-calculated product prices and totals; changing quantity triggers validation/recalculation.
3. Customer chooses delivery or pickup. Delivery coverage is all Kazakhstan; pickup has no fixed point and is coordinated with the manager.
4. Checkout collects name and phone (required), email (optional), and for delivery the full address (city, street, building; apartment and instructions optional).
5. Customer submits without creating an account; account creation remains optional.
6. Backend validates product activity and current available quantity, calculates totals, stores item/contact/delivery snapshots and an idempotency key.
7. Order is saved with a new order state and unpaid payment state. No payment is captured.
8. Confirmation displays the order number and explains that a manager/administrator will contact the customer in WhatsApp to confirm availability and agree delivery cost/timing or pickup details. Order status is updated in the manager workspace; no automatic WhatsApp/carrier integration is implied.
9. Notification dispatch occurs after persistence; a provider failure does not invalidate the confirmation/order.

**Stock rule:** creating the checkout order does not reserve stock. When a manager attempts to confirm it, the backend atomically rechecks available stock and reserves it. If not enough stock remains, confirmation is rejected and the manager must contact the customer/adjust the order under approved policy.

**Recoverable errors:** preserve cart and entered non-sensitive fields after validation failure; do not trust browser totals; a repeated submit with the same idempotency key must not create another order.

## 3. Optional account and customer order history

1. Customer may register with email/password, verify email, log in, log out or request password reset.
2. Customer may manage their own profile and saved contacts/addresses.
3. Customer order list includes only orders associated with that account.
4. A guest order is not exposed as account history unless a safe, approved ownership-claim flow is later designed.
5. Customer sees only customer-visible status and messages; manager notes and restricted contact data remain private.

Authentication uses Sanctum SPA cookies and CSRF protection; tokens are not stored in browser local storage.

## 4. Manager processes an order

1. Manager opens the order inbox and applies status/date/customer/assignment filters.
2. Manager opens details: immutable item snapshots, current order/payment states, contact, delivery/pickup choice, and attribution permitted by policy.
3. Manager contacts the customer in WhatsApp and agrees delivery cost/timing or pickup details.
4. Manager confirms the order. The server validates the allowed transition and atomically reserves sufficient stock; if it fails, no partial confirmation/reservation occurs.
5. Manager moves order through `new → confirmed → preparing → ready_for_pickup` or `shipped → completed`. Cancellation is a separate allowed transition before completion; exact actor/cut-off rules must be enforced according to approved permissions.
6. Payment status is maintained separately (`unpaid → paid`, with `partially_refunded` / `refunded` where applicable); managers do not mark payment automatically from order completion.
7. Every transition/assignment is recorded with actor and time. Internal notes are never shown to the customer.

## 5. Warehouse operator works with stock

1. Operator sees the one initial warehouse and permitted products/balances.
2. Operator records receipt, dispatch/issue or adjustment with a reason/source.
3. Backend applies the operation transactionally and appends an immutable movement.
4. UI shows the result and updated balance; repeated requests must not duplicate a movement.
5. Low-stock conditions are shown/alerted from configured thresholds.

## 6. Administrator maintains catalog and content

1. Administrator creates or edits category/product fields and SKU.
2. Backend validates unique SKU and approved data formats.
3. Administrator uploads product media to Cloudinary; the backend validates files and stores provider references/order/alt text.
4. Administrator previews and publishes/unpublishes the product; only active, published items appear in the storefront.
5. Administrator manages homepage banners, destination and active period; inactive/out-of-period banners are not shown.
6. Sensitive actions are permission-checked server-side and audited.

## 7. Return request

1. Customer contacts the manager to request a return within 14 days of receiving eligible goods.
2. The owner-approved policy says goods must remain in intact packaging; cut-to-length fabric/material is excluded.
3. Manager records a return decision and any approved restock/refund events; payment state remains separate from order state.
4. Before implementation, legal review must verify the rule and exact interpretation against applicable Kazakhstan consumer law. Do not publish a return claim as legally definitive before that review.

## Cross-flow states

Every page/form should define loading, empty, validation error, permission denied, API failure, success and retry/recovery states. Preserve search/cart/form context where safe. Never expose a success state when the server did not confirm persistence.

## Decisions still needed before dependent implementation

- D-06: final delivery coverage/cities and configured pickup address.
- D-08: accounting/legal review of whether tax calculation is required after v1; tax is not calculated in v1.
- D-09–D-11 and D-13: optional feature scope, notification rules, privacy/consent and capacity expectations. Role boundaries D-12 are confirmed.
- Legal review of D-07 return policy before coding/publishing that policy.
