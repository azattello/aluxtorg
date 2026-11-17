---
name: "06 — Catalog and Content"
description: "Implement the product catalog, categories, Cloudinary media, and managed storefront banners."
argument-hint: "Optional catalog/content subarea"
---
# Phase 06 — Catalog, Product Pages and Homepage Content

Implement catalog and content using the React + TypeScript + Vite SPA, Laravel REST API, PostgreSQL, Tailwind CSS and shadcn/ui target. Read [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DATABASE.md](../../docs/DATABASE.md), [DECISIONS.md](../../docs/DECISIONS.md), design docs and existing conventions.

Customer features:
- Hierarchical categories.
- Search by product name and SKU.
- Filters and sorting.
- Product cards and detail pages.
- Price, availability, images, description and specifications.
- Related products where appropriate.

Admin features:
- CRUD for categories and products.
- SKU uniqueness.
- Multiple ordered product images with validation.
- Publish/unpublish and visibility controls.
- Banner management: placement, image, title, destination URL, active dates and ordering.
- CSV import/export only if explicitly included in approved MVP.

Requirements:
- Use JSON REST endpoints and server-side pagination/filtering/sorting.
- Use SEO-friendly slugs and metadata while keeping rendering as a static SPA (no SSR/Next.js).
- Store media in Cloudinary. Keep Cloudinary secrets server-side; persist `public_id`, `secure_url`, alt text, ordering and useful resource metadata in PostgreSQL.
- Validate media type, size and ownership securely; avoid permanent production-local storage fallback.
- Test unpublished products, invalid filters, duplicate SKU and banner scheduling.

Do not implement cart/checkout or add Inertia, Blade as primary frontend, SSR, Redis or Docker. Report checks and stop before Phase 07.
