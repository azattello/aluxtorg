---
name: "03 — Design System"
description: "Specify accessible responsive customer storefront and admin design patterns using Tailwind CSS and shadcn/ui."
argument-hint: "Optional brand, audience, or design references"
---
# Phase 03 — UX Research and Design System

Act as a Senior E-commerce Product Designer and Frontend Architect. Read project instructions, [PRODUCT-OVERVIEW.md](../../docs/PRODUCT-OVERVIEW.md), [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DECISIONS.md](../../docs/DECISIONS.md) and existing design conventions.

Design for two connected but distinct interfaces: (A) customer storefront optimized for organic search and ad traffic; (B) an admin workspace optimized for accurate daily operations. The target frontend is a React + TypeScript + Vite SPA using Tailwind CSS + shadcn/ui and Laravel REST API data.

Approved visual direction from [DECISIONS.md](../../docs/DECISIONS.md): modern minimalism, calm neutral palette, product-focused storefront, and light theme. No brand guide or reference sites have been supplied. Propose a restrained accent palette and typography with a short rationale; do not invent a logo, brand identity, or dark theme.

Tasks:
1. Define personas, journeys and information architecture.
2. Define design tokens: colors, typography, spacing, radii, shadows, borders, breakpoints and motion.
3. Define component inventory and interaction states using Tailwind/shadcn/ui conventions.
4. Specify responsive patterns for homepage, category/search, product detail, cart, checkout and customer account.
5. Specify admin shell, dashboard, order list/detail, product/customer management and inventory views.
6. Include loading, empty, error, success, disabled and permission-denied states.
7. Document accessibility, keyboard/focus behavior, responsive rules and reduced-motion behavior.
8. Create or update `docs/DESIGN-SYSTEM.md` and `docs/UX-FLOWS.md` if appropriate to repository conventions.

Do not introduce Inertia, Blade-driven UI, Next.js, SSR or a second app runtime. Do not implement all pages in this phase. If no approved visual reference or design tool exists, provide precise textual specifications and limit code changes to an explicitly approved minimal foundation.

Report unresolved product assumptions instead of inventing behavior. Stop before Phase 04.
