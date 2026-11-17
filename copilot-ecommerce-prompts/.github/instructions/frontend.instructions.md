---
description: "Use when building the React, TypeScript, Vite storefront or admin SPA, UI components, forms, routing, API integration, Tailwind CSS, or shadcn/ui."
applyTo: "**/*.{tsx,ts,jsx,js,css}"
---
# Frontend instructions

- Build a React + TypeScript SPA with Vite. Use Tailwind CSS and shadcn/ui, following established project conventions.
- Do not use Inertia.js, Next.js, server-side rendering, or server-only frontend routes. The production output is static files in `dist/`.
- Use the project's client-side router if configured; route data and mutations go through the Laravel JSON REST API, not Blade or Inertia props.
- Centralize API access and types. Use same-origin requests in production with credentials enabled for Sanctum cookies and handle the CSRF-cookie flow before state-changing requests.
- Never store authentication tokens or session credentials in `localStorage`; do not embed backend or Cloudinary secrets in frontend bundles.
- Use function components and explicit types; avoid `any` when a useful type can be defined.
- Keep order, pricing, permissions and inventory decisions on the server. Treat browser values as untrusted.
- Forms must show field-level server validation errors and preserve entered values after recoverable failures.
- Use server-side pagination, filtering and sorting for large datasets.
- All interactive controls need an actual action, keyboard support, visible focus, and appropriate loading, disabled, empty, error and success states.
- Verify responsive layouts at 360px, 768px, 1024px and desktop widths; follow the accessibility and design guidance in project docs.
