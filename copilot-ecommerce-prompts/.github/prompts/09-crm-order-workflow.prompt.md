---
name: "09 — CRM and Order Workflow"
description: "Implement manager-facing order processing, assignment, comments, audited status history, and customer timeline."
argument-hint: "Optional CRM workflow area"
---
# Phase 09 — CRM and Order Workflow

Implement manager-facing order processing using the approved order/payment lifecycle. Read [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DATABASE.md](../../docs/DATABASE.md), [DECISIONS.md](../../docs/DECISIONS.md) and existing policies/API patterns.

Features:
- Order inbox with filters by status, date, customer and assigned manager.
- Order detail with customer, item snapshots, totals, approved delivery details and permitted source attribution.
- Assignment/reassignment to manager.
- Internal notes kept separate from customer-visible messages.
- Backend-validated status transition controls.
- Append-only status history with actor and timestamp.
- Customer order timeline.
- Follow-up date only if approved.

Rules:
- Validate transitions and role authorization on the backend, including direct API access.
- Keep payment state separate from fulfillment/order state.
- Do not let unauthorized roles see restricted customer data.
- Never rewrite history; create a new event.
- Notify customers only for owner-approved customer-facing transitions.
- Keep the React interface on the REST API; do not implement business rules solely in the UI.

Tests: invalid transitions, concurrent edits, manager permissions, private comments, history integrity and notification failure. Stop after CRM and report results.
