---
description: "Use when designing PostgreSQL schema, Laravel migrations, database constraints, indexes, order snapshots, inventory, reservations, or data migrations."
applyTo: "**/*.{php,sql}"
---
# Database instructions

- PostgreSQL is the system of record. Implement schema changes through reversible Laravel migrations and review generated SQL for safety.
- Add foreign keys, indexes and unique constraints that reflect approved business rules and real query patterns.
- Do not cascade-delete business records without explicit justification; retain order and stock history.
- Store immutable order-item snapshots, including product title, SKU, unit price, quantity and line total.
- Keep order lifecycle and payment lifecycle separate. Record status changes and stock movements as append-only history; correct mistakes with compensating events.
- Use `DECIMAL`/`NUMERIC` with an explicitly chosen precision and scale for money; never use floating-point types. Confirm currency and rounding policy in [DECISIONS.md](../../docs/DECISIONS.md).
- Store timestamps consistently in UTC and define the business timezone for display/reporting.
- Model current and reserved stock alongside the movement ledger; do not treat a cached balance as the only audit record.
- Confirm warehouse cardinality, reservation timing/expiry and release rules in [DECISIONS.md](../../docs/DECISIONS.md) before fixing schema constraints.
- Check query plans and N+1 behavior for production-facing queries. Keep PostgreSQL memory/workload settings conservative on the initial 1 GB VPS.
