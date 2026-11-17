---
name: "11 — Notifications and Analytics"
description: "Add resilient Telegram/email notifications and privacy-conscious GA4, GTM, Ads, and UTM integrations."
argument-hint: "Optional integration or event"
---
# Phase 11 — Telegram, Email and Marketing Analytics

Implement integrations behind configurable adapters. Read [ARCHITECTURE.md](../../docs/ARCHITECTURE.md), [DECISIONS.md](../../docs/DECISIONS.md), privacy requirements and existing event/queue conventions.

### Telegram

- Send new-order alert to the configured group.
- Include order number, timestamp and approved useful summary; restrict personal data to what is necessary and authorized.
- Send selected status/low-stock alerts only if enabled.
- Store delivery attempts, retry failures and expose failure state to admin.
- Never commit bot token/chat ID or other secrets.

### Email

- Send customer order confirmation.
- Send status messages only for owner-approved customer-facing transitions.
- Queue delivery and record failures without affecting order persistence.

### Queue behavior

- Default to Laravel's database-backed queue; no Redis requirement.
- Supervise a bounded worker appropriate to the VPS; keep order persistence independent from provider availability.
- Avoid retry storms and expose permanent failure for operational follow-up.

### Analytics

- Integrate GA4 + GTM and Google Ads conversion tracking only after consent/privacy requirements are approved.
- Track relevant events such as `view_item`, `add_to_cart`, `begin_checkout`, and a clearly named no-payment conversion such as `order_submitted`; do not misrepresent an order as a paid `purchase`.
- Capture UTM source, medium, campaign, term and content only where permitted.
- Do not send personal data, email, phone, address, or raw order contents to analytics platforms.
- Respect consent and applicable privacy requirements.

Verify events in test/debug mode. Notification failure must never roll back a saved order. Update integration/privacy docs. Stop after integrations and report exact checks.
