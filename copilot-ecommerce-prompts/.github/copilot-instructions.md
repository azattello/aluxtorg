# Project Instructions

Build a production-ready e-commerce store with customer storefront, admin operations, CRM and inventory. This repository is a prompt-and-documentation pack; do not assume the application already exists.

## Architecture

- Frontend: React + TypeScript + Vite SPA; Tailwind CSS + shadcn/ui.
- Backend: Laravel REST API; PostgreSQL; Laravel Sanctum SPA cookie/session authentication.
- Media: Cloudinary for product images, banners, category images and other media. Persist provider identifiers and relevant metadata in PostgreSQL; never expose API secrets to the browser.
- Production: Ubuntu 24.04 LTS, Nginx, PHP-FPM and PostgreSQL. Nginx serves the built React files from `dist/`; Laravel handles `/api/*` and `/sanctum/*`.
- Node.js is for local development/CI builds only. Production serves static frontend assets and must not require a Node.js process.

See [ARCHITECTURE.md](../docs/ARCHITECTURE.md), [DATABASE.md](../docs/DATABASE.md), and [PRODUCTION-CHECKLIST.md](../docs/PRODUCTION-CHECKLIST.md) for details.

## Non-negotiable boundaries

- Do not use Inertia.js, Next.js, SSR, Redis, Docker or microservices by default. Any exception requires explicit owner approval and a documented reason. The production design must not depend on Node.js.
- Frontend and backend communicate using a JSON REST API. Keep authorization and business rules on the server.
- Use Sanctum's SPA cookie/session flow with CSRF protection; never store authentication tokens in `localStorage`.
- Do not add online payment or automatic carrier integrations to the initial release.
- Order status and payment status are distinct. The server calculates prices, discounts, availability and totals; preserve order-item snapshots.
- A Telegram/email failure must not undo or lose a persisted order.
- Treat the initial 1 vCPU / 1 GB RAM / 25 GB VPS as a constrained target; measure resource usage and document when it is insufficient.

## Working rules

1. Read the relevant instructions, docs and existing code before implementation.
2. Resolve applicable items in [DECISIONS.md](../docs/DECISIONS.md) with the owner; do not invent business policy.
3. Implement only the current Prompt File phase, in small reviewable increments.
4. Preserve existing behavior and store requirements unless the owner approves a change.
5. Add focused tests, run the checks relevant to the change, and report exact outcomes.
6. Never expose or commit secrets, `.env` files, or production credentials. Never claim checks passed unless they ran.
