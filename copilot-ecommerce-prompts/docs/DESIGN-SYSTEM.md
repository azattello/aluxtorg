# Design System

## Status and intent

This is the initial design specification for the storefront and admin SPA. It follows the owner-approved direction in [DECISIONS.md](DECISIONS.md): modern minimalism, calm neutrals, product-focused layouts and a light theme. No logo, brand guide or reference sites have been supplied. Colors below are a proposal for the interface foundation, not a final brand identity.

The UI is a React + TypeScript + Vite SPA using Tailwind CSS and shadcn/ui. Customer storefront and admin share tokens and accessible primitives, but have different information density and navigation.

## Visual principles

1. **Products first:** photography, price, availability and product information carry more visual weight than decorative UI.
2. **Quiet hierarchy:** use spacing, typography and alignment before adding borders, shadows or color.
3. **Operational clarity:** status, stock and order totals remain scannable in dense admin views.
4. **Trust through consistency:** prices use KZT formatting, status labels are consistent, and destructive or irreversible actions are confirmed.
5. **Light theme only:** do not add a dark theme or auto-switch by OS preference unless the owner later asks for it.

## Proposed design tokens

### Color

| Token | Proposed value | Use |
|---|---|---|
| `background` | `#F8F9F7` | Page canvas |
| `surface` | `#FFFFFF` | Cards, dialogs, menus and forms |
| `foreground` | `#202622` | Main text and headings |
| `muted` | `#F0F2EF` | Secondary panels, quiet controls |
| `muted-foreground` | `#66716A` | Supporting text, captions |
| `border` | `#E1E6E1` | Dividers and control outlines |
| `primary` | `#315C4D` | Main actions, selected states and key links |
| `primary-hover` | `#274B3F` | Hover/pressed primary actions |
| `primary-foreground` | `#FFFFFF` | Text/icons on primary actions |
| `accent` | `#EAF1EC` | Subtle highlights and selected rows |
| `success` | `#26734D` | Completed/available state |
| `warning` | `#8A5A12` | Pending/low-stock state |
| `destructive` | `#B42318` | Errors, cancellation and destructive actions |

The restrained evergreen accent supports a calm commerce interface and separates primary actions from neutral content without inventing a logo or category-specific brand. Before implementation, verify every text/background pairing against WCAG AA: at least 4.5:1 for normal text and 3:1 for large text and meaningful controls. Do not communicate status by color alone.

Map these values into shadcn/ui CSS variables and Tailwind semantic utilities. Components should consume semantic tokens, not repeat raw hex values.

### Typography

- Primary family: Geist Variable, already included in the frontend foundation; use system sans-serif fallbacks.
- Admin numeric data should use tabular numerals where available.
- Page heading: 32–40px desktop, 28–32px mobile; semibold, compact line height.
- Section heading: 20–24px; semibold.
- Card/form heading: 16–18px; medium/semibold.
- Body: 14–16px; line height 1.45–1.6.
- Supporting text: 12–14px, never rely on low-contrast color to make it visually secondary.
- Use sentence case for labels and buttons. Avoid long uppercase labels.

### Spacing, shape and elevation

- Base spacing increment: 4px; common gaps: 8, 12, 16, 24, 32 and 48px.
- Control height: 40px default, 36px compact admin, 44px minimum for primary mobile touch targets.
- Radius: 8px controls, 12px cards, 16px large panels/dialogs, fully rounded only for chips/avatars.
- Borders are the default surface separator. Use soft, short shadows only for floating menus, dialogs and elevated overlays.
- Storefront content width: approximately 1200–1280px; admin workspace: up to approximately 1440px with a readable central content column.

### Responsive breakpoints

Use the project's Tailwind breakpoints consistently:

- Small phone: 360px baseline; single-column content and full-width primary checkout actions.
- Tablet: 768px; reveal compact two-column catalog and secondary navigation where it fits.
- Desktop: 1024px; full catalog filters and admin navigation.
- Wide desktop: 1280px+; constrain readable content instead of stretching text and product cards.

At all widths, primary content must not cause horizontal overflow. Tables may switch to cards or a deliberate horizontally scrollable region with accessible controls.

## Storefront patterns

- Header: restrained wordmark placeholder, category/navigation access, search, account and cart. Do not fabricate a logo.
- Search: keyboard accessible, clear empty/no-result state, query preserved when navigating back.
- Category page: title/breadcrumbs, result count, filters, sorting and server-paginated product grid. Mobile filters open in a labelled sheet/dialog.
- Product card: consistent image ratio, concise title, SKU only where useful, KZT price, clear availability and a working add-to-cart action.
- Product detail: large product image area, title, SKU, price, availability, description/specifications and quantity control. Do not invent ratings or stock claims.
- Cart: editable quantity, remove action, server-refreshed prices and explicit subtotal/total; errors preserve the current cart.
- Checkout: linear steps with an order summary; distinguish delivery and pickup; disclose that this is an order request and not online payment.
- Confirmation: show order number and next step; do not imply payment or delivery is already confirmed.
- Account: profile, saved contacts/addresses and order history; customer data is private to the authenticated owner.

## Admin and operations patterns

- Desktop navigation may be persistent; mobile navigation uses a labelled menu/sheet.
- Lists provide server-side search, filters, sort, pagination and visible active-filter/reset affordances.
- Admin forms group fields by task and show server validation next to the relevant field. Preserve non-sensitive inputs on recoverable errors.
- Destructive actions use a confirmation dialog that names the affected record and consequence.
- Order detail distinguishes customer data, immutable item snapshots, order state, payment state, delivery details and internal notes.
- Stock changes require a movement type and reason; show the updated balance and auditable movement result.
- Never display fabricated dashboard values, charts, activity or stock.

## Component inventory

Use shadcn/ui primitives where they fit; extend them only with a documented interaction requirement.

- Structure: `Header`, `Footer`, `PageContainer`, `Breadcrumbs`, `CustomerNavigation`, `AdminNavigation`.
- Catalog: `SearchField`, `FilterPanel`, `SortSelect`, `ProductCard`, `ProductGrid`, `ProductGallery`, `Price`, `AvailabilityBadge`, `Pagination`.
- Forms: `FormField`, `PhoneField`, `AddressFields`, `QuantityControl`, `InlineValidation`, `FormErrorSummary`.
- Orders: `CartLine`, `OrderSummary`, `OrderStatusBadge`, `PaymentStatusBadge`, `StatusTimeline`, `InternalNote`.
- Admin: `DataTable`, `FilterToolbar`, `EmptyState`, `ConfirmDialog`, `MetricCard` (real data only), `StockMovementForm`.
- Feedback: `Skeleton`, `Spinner`, `Toast`, `Alert`, `InlineError`, `EmptyState`.

## Interaction, accessibility and motion

- All actions are real buttons or links with descriptive names; icon-only controls have accessible labels.
- Keep keyboard focus visible, logical, and never hidden under sticky headers/dialogs. Dialog focus is trapped and returned to the opener.
- Form errors are programmatically associated with inputs and announced to assistive technology. Provide a summary for multi-field failures.
- Use semantic headings, landmarks, labels and table headers. Avoid clickable non-semantic containers.
- Maintain WCAG AA contrast and do not encode status solely through color.
- Respect `prefers-reduced-motion`; use short transitions for feedback, not decorative movement. Avoid auto-advancing carousels.
- Loading states preserve layout; empty states explain the next action; errors provide a recovery path; disabled states explain why when unclear.

## Do not introduce

- Dark theme or automatic system-theme switching without approval.
- Fake product data presented as live inventory or real sale information.
- Unapproved logo, brand name, product photography or marketing claims.
- Motion/design dependencies that are unnecessary for the interface.
