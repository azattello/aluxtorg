---
name: "10 — Inventory"
description: "Implement audited stock movements, reservations, warehouse operations, and low-stock alerts."
argument-hint: "Optional inventory or warehouse area"
---
# Phase 10 — Inventory and Stock Ledger

Implement inventory using only the warehouse and reservation policies approved in [DECISIONS.md](../../docs/DECISIONS.md). Read [DATABASE.md](../../docs/DATABASE.md), [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), existing migrations and order flows.

Features:
- Current stock and reserved stock.
- Goods receipt.
- Dispatch/issue.
- Manual adjustment with reason.
- Returns only if included in the approved MVP.
- Low-stock threshold and alert.
- Movement history with actor, timestamp, source document and quantity delta.
- Warehouse filters only if multiple warehouses are approved.

Rules:
- Stock movement ledger is the audit source; do not silently overwrite quantities.
- Use transactions and row-level locking/atomic updates to prevent overselling.
- Prevent negative available stock unless an explicit approved backorder policy exists.
- Reserve/release exactly once and make cancellation/expiry idempotent.
- Reconcile balances from movements where feasible and document any derived balance model.
- Do not assume one/multiple warehouses, reservation timing or expiry.

Add tests for concurrency, cancellation, repeated release, receipt, dispatch and adjustments. Document operational procedures. Stop after inventory and report test results.
