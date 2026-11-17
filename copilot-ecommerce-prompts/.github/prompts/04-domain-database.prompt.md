---
name: "04 — Domain and Database"
description: "Model the approved e-commerce domain in PostgreSQL and Laravel, including order history and inventory integrity."
argument-hint: "Optional domain area to focus on"
---
# Phase 04 — Domain Model and Database

Act as a Senior Laravel Architect and Data Modeler. Read [DATABASE.md](../../docs/DATABASE.md), [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DECISIONS.md](../../docs/DECISIONS.md), the approved product scope, and existing schema/code.

Do not implement unresolved business policy. If a necessary decision is still open, document the dependency and stop before creating schema that assumes an answer.

Tasks:
1. Produce an ERD with entities, cardinalities, lifecycle ownership and constraints.
2. Define separate order and payment states and their allowed transitions based only on approved answers.
3. Define stock movement ledger, reservations, cancellation/expiry behavior and warehouse cardinality from approved decisions.
4. Define immutable order-item and relevant customer contact/address snapshots.
5. After model review/approval, create reversible PostgreSQL migrations, models, factories and seeders.
6. Add foreign keys, indexes, unique constraints and appropriate deletion behavior.
7. Add tests for constraints, relationships, snapshots and important state rules.

Use transactions and appropriate row locks for stock-sensitive flows. Use exact decimal monetary types, never float. Keep history append-only and use compensating events for corrections. Avoid speculative tables and explain each one. Update `docs/DATABASE.md` and `docs/ARCHITECTURE.md`.

Stop after the model/schema phase and report test results and decisions still needed. Do not begin authentication or storefront implementation.
