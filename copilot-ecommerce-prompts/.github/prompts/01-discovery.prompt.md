---
name: "01 — Product Discovery"
description: "Clarify e-commerce MVP scope, customer journeys, assumptions, and unresolved business decisions before implementation."
argument-hint: "Optional focus area or product-owner answers"
---
# Phase 01 — Product Discovery and Repository Audit

Act as a Senior Product Analyst and Software Architect. Read the project instructions and [PRODUCT-OVERVIEW.md](../../docs/PRODUCT-OVERVIEW.md), [DEVELOPMENT-ROADMAP.md](../../docs/DEVELOPMENT-ROADMAP.md), [ACCEPTANCE-CHECKLIST.md](../../docs/ACCEPTANCE-CHECKLIST.md), [DECISIONS.md](../../docs/DECISIONS.md), and relevant repository documentation/code.

Do not implement application features, scaffold the stack, or change application behavior in this phase.

Tasks:
1. List confirmed requirements, assumptions, ambiguities and out-of-scope features.
2. Audit existing code and docs without destructive changes; report any deviation from the target architecture in [ARCHITECTURE.md](../../docs/ARCHITECTURE.md).
3. Review the open business decisions, especially registration/guest checkout, required contact fields, stock reservation, warehouse count, order/payment lifecycles, delivery/pickup, returns, currency/tax, analytics consent and role boundaries.
4. Describe journeys for guest/customer, manager, warehouse operator, administrator and super administrator.
5. Define MVP scope and measurable acceptance criteria while preserving catalog, customer account, admin, CRM, inventory, notification, analytics, SEO, accessibility and privacy needs.
6. Update [PRODUCT-OVERVIEW.md](../../docs/PRODUCT-OVERVIEW.md), [DECISIONS.md](../../docs/DECISIONS.md) and [ACCEPTANCE-CHECKLIST.md](../../docs/ACCEPTANCE-CHECKLIST.md) only as necessary to reflect confirmed answers. Do not silently decide for the owner.

Stop after discovery documentation. Report findings, unresolved owner decisions, architecture deviations, and the recommended next Prompt File. Do not continue into Phase 02.
