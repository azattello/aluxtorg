# Database — PostgreSQL

PostgreSQL is the system of record. This domain ERD is based on owner-approved decisions in [DECISIONS.md](DECISIONS.md), including product variants/SKUs and whole-unit quantities. Reversible Laravel migrations, Eloquent models, factories, the initial warehouse seeder and focused SQLite/PostgreSQL tests are implemented.

## Implemented domain ERD

```mermaid
erDiagram
    USERS o|--o{ ORDERS : places
    USERS o|--o{ ORDER_STATUS_HISTORY : records
    USERS o|--o{ PAYMENT_STATUS_HISTORY : records
    USERS o|--o{ STOCK_MOVEMENTS : performs
    USERS o|--o{ STOCK_RESERVATIONS : creates
    USERS o|--o{ STOCK_RESERVATIONS : resolves
    USERS o|--o{ AUDIT_LOGS : acts
    CATEGORIES o|--o{ CATEGORIES : parent_of
    CATEGORIES ||--o{ PRODUCTS : contains
    PRODUCTS ||--|{ PRODUCT_VARIANTS : offers
    PRODUCTS ||--o{ PRODUCT_IMAGES : has
    PRODUCT_VARIANTS o|--o{ PRODUCT_IMAGES : depicts
    BANNERS {
        bigint id PK
        string title
        string placement
        string cloudinary_public_id UK
        text secure_url
        string alt_text
        string destination_url "nullable"
        timestamp starts_at "nullable"
        timestamp ends_at "nullable"
        boolean is_active
        int sort_order
    }
    ORDERS ||--|{ ORDER_ITEMS : contains
    PRODUCT_VARIANTS o|--o{ ORDER_ITEMS : snapshots
    ORDERS ||--o{ ORDER_STATUS_HISTORY : tracks
    ORDERS ||--o{ PAYMENT_STATUS_HISTORY : tracks
    WAREHOUSES ||--o{ STOCK_BALANCES : holds
    PRODUCT_VARIANTS ||--o{ STOCK_BALANCES : counted_as
    WAREHOUSES ||--o{ STOCK_MOVEMENTS : records
    PRODUCT_VARIANTS ||--o{ STOCK_MOVEMENTS : affects
    ORDERS o|--o{ STOCK_MOVEMENTS : references
    ORDER_ITEMS o|--o{ STOCK_MOVEMENTS : references
    STOCK_MOVEMENTS o|--o{ STOCK_MOVEMENTS : corrects
    ORDERS o|--o{ STOCK_RESERVATIONS : reserves_for
    ORDER_ITEMS ||--o{ STOCK_RESERVATIONS : reserves_item
    WAREHOUSES ||--o{ STOCK_RESERVATIONS : reserves_at
    PRODUCT_VARIANTS ||--o{ STOCK_RESERVATIONS : reserves_variant

    USERS {
        bigint id PK
    }
    CATEGORIES {
        bigint id PK
        bigint parent_id FK
        string name
        string slug UK
        boolean is_active
        int sort_order
        string meta_title "nullable"
        string meta_description "nullable"
    }
    PRODUCTS {
        bigint id PK
        bigint category_id FK
        string slug UK
        string name
        text description
        boolean is_published
        int sort_order
        string meta_title "nullable"
        string meta_description "nullable"
    }
    PRODUCT_VARIANTS {
        bigint id PK
        bigint product_id FK
        string sku UK
        string name
        string sale_unit
        jsonb attributes
        bigint price_kzt
        boolean is_active
        int sort_order
    }
    PRODUCT_IMAGES {
        bigint id PK
        bigint product_id FK
        bigint product_variant_id FK "nullable"
        string cloudinary_public_id UK
        text secure_url
        string alt_text
        int sort_order
        string format "nullable"
        int width "nullable"
        int height "nullable"
        bigint bytes "nullable"
    }
    ORDERS {
        bigint id PK
        bigint user_id FK "nullable"
        string number UK
        string order_status
        string payment_status
        string fulfillment_method
        string customer_name
        string customer_phone
        string customer_email "nullable"
        bigint subtotal_kzt
        bigint delivery_fee_kzt "nullable until delivery quote"
        bigint total_kzt "nullable until delivery quote"
    }
    ORDER_ITEMS {
        bigint id PK
        bigint order_id FK
        bigint product_variant_id FK "nullable"
        string product_name_snapshot
        string variant_name_snapshot "nullable"
        string sku_snapshot
        jsonb variant_attributes_snapshot "nullable"
        bigint unit_price_kzt
        int quantity
        bigint line_total_kzt
    }
    ORDER_STATUS_HISTORY {
        bigint id PK
        bigint order_id FK
        bigint actor_user_id FK "nullable"
        string from_status "nullable"
        string to_status
        text note "nullable"
        timestamp created_at
    }
    PAYMENT_STATUS_HISTORY {
        bigint id PK
        bigint order_id FK
        bigint actor_user_id FK "nullable"
        string from_status "nullable"
        string to_status
        bigint amount_kzt "nullable"
        text note "nullable"
        timestamp created_at
    }
    AUDIT_LOGS {
        bigint id PK
        bigint actor_user_id FK "nullable"
        string event_key
        string subject_type "nullable"
        bigint subject_id "nullable"
        jsonb metadata
        timestamp created_at
    }
    WAREHOUSES {
        bigint id PK
        string code UK
        string name
        boolean is_active
    }
    STOCK_BALANCES {
        bigint id PK
        bigint warehouse_id FK
        bigint product_variant_id FK
        int on_hand
        int reserved
    }
    STOCK_MOVEMENTS {
        bigint id PK
        bigint warehouse_id FK
        bigint product_variant_id FK
        bigint order_id FK "nullable"
        bigint order_item_id FK "nullable"
        bigint actor_user_id FK "nullable"
        string type
        int quantity_delta
        bigint correction_of_movement_id FK "nullable"
        text note "nullable"
        timestamp created_at
    }
    STOCK_RESERVATIONS {
        bigint id PK
        bigint order_id FK
        bigint order_item_id FK
        bigint warehouse_id FK
        bigint product_variant_id FK
        bigint created_by FK "nullable"
        bigint resolved_by FK "nullable"
        int quantity
        string status
        timestamp created_at
        timestamp resolved_at "nullable"
    }
```

### Relationship and lifecycle ownership

- Laravel's existing `users` table remains the identity source; order ownership is optional so guest checkout is supported. Order contact and delivery fields are snapshots, not references to an editable customer profile.
- Categories form an optional-parent tree. Products belong to one category in v1 and have a unique slug. Each product offers one or more variants; each purchasable variant has its own unique SKU, KZT price, sale-unit label and flexible JSONB attribute map (for example, size or color). Exact variant dimensions remain catalog data rather than schema-level columns. Quantities are integers; fractional cuts/weights are outside v1.
- Product images store Cloudinary references only; images can be product-wide or variant-specific. Credentials remain server-side.
- An order has one or more immutable item snapshots, including product name, variant name/attributes, SKU, price, quantity and line total. Deleting a catalog variant must not delete an order item; retain its variant reference as nullable with `ON DELETE SET NULL`.
- Order and payment lifecycles are separate. The normal order path is `new → confirmed → preparing → ready_for_pickup → completed` or `preparing → shipped → completed`; cancellation is allowed before completion. Payment transitions are `unpaid → paid → partially_refunded → refunded`, with direct `paid → refunded` also allowed. Store history as append-only rows with actor and timestamp; dispatch consumes stock, so a later cancellation must not release it again.
- `audit_logs` records privileged actions with an actor, stable event key and optional subject. Metadata must be allowlisted and must never include secrets, credentials or unnecessary customer data. Retention and access policy must be resolved before production (D-11).
- Fulfilment is `delivery` or `pickup`. Delivery address snapshots contain city, street and building; apartment and instructions are optional. A delivery fee and total may remain null until a manager confirms the coverage and charge; pickup orders have a zero fee and a total equal to their subtotal. Do not seed a pickup address or delivery-zone policy until D-06 is completed.
- One active warehouse is seeded for v1. `stock_balances` is the lockable current projection; `stock_movements` is the append-only ledger. `stock_reservations` records order-linked reserved quantities and release state.
- On manager confirmation, lock the affected balance rows and create reservations per order item atomically. There is no expiry. Cancellation releases an active reservation idempotently; dispatch consumes it. No return/refund tables are proposed until the legal review for D-07 is complete.

### Proposed database constraints and indexes

- Unique: category slug, product slug, variant SKU, Cloudinary public ID, order number, and `(warehouse_id, product_variant_id)` stock balance.
- Checks: KZT amounts are non-negative; order/reservation quantities are positive integers; stock movement deltas are non-zero integers; stock balance quantities are non-negative and `reserved <= on_hand`; line total equals unit price times quantity; when a delivery fee is known, `total_kzt = subtotal_kzt + delivery_fee_kzt`.
- Foreign keys use restrictive deletion for orders, history, stock movements, reservations and media. Optional identity/product references use `SET NULL` where preserving the business record is necessary. Categories with children/products cannot be deleted until dependencies are handled. Audit subjects are polymorphic by design; deleting an actor nulls the actor reference but never deletes the audit event.
- Index order lists by `(order_status, created_at)` and customer phone; history by `(order_id, created_at)`; stock ledger/reservations by `(warehouse_id, product_variant_id, created_at/status)`; catalog by publication/category/sort access patterns.
- Store timezone-aware timestamps in UTC and present them in the configured business timezone.

## Explicitly out of this initial schema

Do not add roles/permissions, customer profiles/addresses, carts, reviews, coupons, notifications/outbox, analytics, settings tables or return workflows until their implementation phase and applicable decisions are approved. Do not assume resolved delivery zones, pickup location, tax treatment, consent/retention rules or notification triggers. The open decisions are tracked in [DECISIONS.md](DECISIONS.md).

## Data integrity rules

- Use exact whole-KZT storage, never floating point. No tax line is included in v1 until the accounting/legal treatment is decided.
- Never trust client-supplied prices, totals, status transitions or stock quantities. Recalculate totals on the server.
- Keep order and payment status separate; record every transition append-only.
- Stock movement history is the audit source. Correct mistakes with compensating entries rather than rewriting the ledger.
- Make order submission and stock reservation/release transactional, idempotent and safe under concurrency with row locks or atomic updates.
- Do not cascade-delete business history. Review indexes and query plans against actual list/filter/report queries.
- Laravel's database queue is the default when asynchronous work is introduced; do not require Redis.

## Decisions that still constrain later features

The initial schema deliberately avoids features depending on open decisions. See [DECISIONS.md](DECISIONS.md) for delivery coverage/pickup configuration (D-06), legal/accounting review (D-07/D-08), reviews/loyalty/coupons (D-09), customer notifications (D-10), consent and retention (D-11), and expected volume/VPS sizing (D-13). D-16 is resolved for v1: only whole sale units.
