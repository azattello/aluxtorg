---
name: "08 — Admin Workspace"
description: "Build React admin screens for catalog, customers, users, content, and approved operational settings."
argument-hint: "Optional admin module"
---
# Phase 08 — Admin Workspace and Operations

Build the admin shell and operational CRUD screens using the approved design system. Read project instructions, [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DECISIONS.md](../../docs/DECISIONS.md), and existing API/permission conventions.

Modules:
- Dashboard shell and navigation.
- Products and categories.
- Banners/content.
- Customer directory and customer detail.
- User/role management within approved permission boundaries.
- System settings required for storefront operation.

Requirements:
- React SPA consumes Laravel JSON REST API; backend enforces every permission.
- Use server-side pagination, search, filters and sorting.
- Show field-level validation errors and useful feedback.
- Confirm destructive actions and avoid deleting business history.
- Include empty/loading/error states and audit sensitive operations.
- Avoid fake charts or hard-coded production metrics.
- Dashboard metrics use documented real queries and date ranges. Label unavailable metrics rather than fabricate them.

Do not implement order CRM workflow here; that is Phase 09. Do not introduce Inertia, Blade as primary frontend, SSR, Next.js or a separate runtime. Report tests and stop.
