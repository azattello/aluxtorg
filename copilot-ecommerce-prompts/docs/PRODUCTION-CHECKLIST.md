# Production Checklist

Use before release to the Ubuntu 24.04 production VPS. This complements [ACCEPTANCE-CHECKLIST.md](ACCEPTANCE-CHECKLIST.md); it is not evidence that any item has already been completed.

## Architecture and build

- [ ] Production topology is Nginx + PHP-FPM + PostgreSQL; Cloudinary is configured for media.
- [ ] React production build is generated in CI or on a developer machine and deployed as static files in `dist/`.
- [ ] No Node.js process/build step is required on the production server.
- [ ] Nginx serves the SPA and falls back to `index.html` only for frontend routes.
- [ ] `/api/*` and `/sanctum/*` bypass SPA fallback and reach Laravel via PHP-FPM.
- [ ] No production dependency on Inertia, Blade as primary frontend, Next.js, SSR, Docker or Redis without explicit owner approval.

## Authentication and application security

- [ ] Sanctum first-party SPA session/cookie and CSRF flows are verified on the production hostname.
- [ ] Session cookies use HTTPS, `Secure`, appropriate `HttpOnly`/`SameSite` settings; session domain and stateful domains are narrowly configured.
- [ ] Credentialed CORS, if needed, has explicit allowed origins; no wildcard origin with credentials.
- [ ] TLS certificate, HTTP-to-HTTPS redirect, trusted-proxy settings, security headers and Laravel production settings are checked.
- [ ] Backend enforces authorization, validation, rate limits and order/inventory rules.
- [ ] No secrets are committed or embedded in `dist/`; Cloudinary and notification credentials exist only in server-side secret configuration.
- [ ] Production errors do not disclose debug traces, SQL, tokens or unnecessary personal data.

## Database, queues and storage

- [ ] PostgreSQL is reachable only from required hosts/accounts; least-privilege credentials are used.
- [ ] Migrations are reviewed and applied with a backup and rollback plan.
- [ ] Backup schedule, off-host retention and a restore test are confirmed.
- [ ] Queue backend is explicitly configured; database queue is the default when asynchronous notifications are needed.
- [ ] Any queue worker is supervised, concurrency-bounded, monitored and does not starve PHP-FPM/PostgreSQL on the constrained host.
- [ ] Cloudinary upload/signing, public ID persistence, image delivery and failure handling are tested without exposing API secrets.

## VPS capacity and operations

- [ ] Actual RAM, CPU, disk, PHP-FPM worker count, PostgreSQL settings and queue concurrency are recorded.
- [ ] Capacity is tested under expected traffic; 1 GB RAM is treated as a risk, not an assumed safe production size.
- [ ] Frontend assets are not built on the VPS; disk usage, logs, backups and upload/cache growth are bounded.
- [ ] Memory pressure, swap activity, disk, database connections, queue backlog, HTTP errors and latency are monitored with alerts.
- [ ] An upgrade threshold and procedure are documented; consider 2–4 GB RAM based on measured load.
- [ ] Deploy and rollback runbooks are tested; the prior frontend bundle and application release can be restored.
- [ ] Security updates, firewall/SSH access, service restart behavior and log rotation are documented.

## Product and launch readiness

- [ ] [DECISIONS.md](DECISIONS.md) items needed by the release are resolved.
- [ ] Order/payment state separation, server-side totals, idempotency and stock concurrency are verified.
- [ ] Telegram/email failures cannot lose saved orders; failed delivery is observable.
- [ ] SEO, consent/privacy, analytics events and absence of personal data in analytics are reviewed.
- [ ] [ACCEPTANCE-CHECKLIST.md](ACCEPTANCE-CHECKLIST.md) is reviewed and release blockers are recorded.
