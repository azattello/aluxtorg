# Copilot Prompt Pack — E-commerce + CRM + Inventory

Этот комплект предназначен для разработки интернет-магазина с каталогом, личным кабинетом, оформлением заказов без онлайн-оплаты, CRM, складским учётом, Telegram-уведомлениями и аналитикой.

## Целевая архитектура (утверждена)

- Frontend: React + TypeScript + Vite SPA
- UI: Tailwind CSS + shadcn/ui
- Backend: Laravel REST API
- Database: PostgreSQL
- Authentication: Laravel Sanctum SPA cookie/session authentication + CSRF protection
- Media: Cloudinary
- Production: Ubuntu 24.04, Nginx + PHP-FPM; Nginx serves the built React app from `dist/`
- Node.js: local development/CI build only; not required in production runtime
- Tests: Pest/PHPUnit для backend, Vitest для frontend unit tests, Playwright для E2E
- Analytics: GA4 + GTM + Google Ads conversion tracking
- Notifications: Telegram Bot API + email
- Deployment: Ubuntu 24.04 VPS

Подробности: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/DATABASE.md](docs/DATABASE.md) и [docs/PRODUCTION-CHECKLIST.md](docs/PRODUCTION-CHECKLIST.md). Redis, Docker, Inertia.js, Next.js, SSR и микросервисы не входят в целевую архитектуру и требуют отдельного одобрения владельца.

## Как использовать

1. Скопируйте `.github/` и `docs/` в корень репозитория приложения.
2. Откройте проект в VS Code и включите GitHub Copilot Agent Mode.
3. Запускайте `.github/prompts/01-discovery.prompt.md` — `.github/prompts/12-quality-launch.prompt.md` по порядку. Список фаз и критерии перехода — в [docs/DEVELOPMENT-ROADMAP.md](docs/DEVELOPMENT-ROADMAP.md).
4. На каждом этапе Copilot обязан сначала изучить проект, показать план, выполнить только текущий этап и отчитаться.
5. После каждого этапа запустите тесты, проверьте diff и сделайте Git commit.
6. Не запускайте следующий prompt, пока не приняты критерии завершения предыдущего.
7. Перед разработкой разрешайте соответствующие вопросы в [docs/DECISIONS.md](docs/DECISIONS.md); не угадывайте бизнес-правила.

## Важное правило

Prompt-файлы — не гарантия корректности. Проверяйте миграции, права доступа, расчёты остатков, безопасность и бизнес-логику вручную. Не передавайте Copilot реальные секреты, токены Telegram или production credentials.
