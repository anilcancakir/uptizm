# Draft: Status Page Feature

## Requirements (confirmed)
- Users can create status pages per team
- Status pages rendered by Laravel as server-side HTML (Blade templates)
- Subdomain-based routing (e.g., `acme.uptizm.com`)
- User enters: name + subdomain slug
- Configurable: colors, logo, description, title
- Displays monitoring data and metrics from selected monitors
- Standard design template with customizable branding

## Technical Decisions
- **Frontend**: Flutter app for CRUD management (create/edit/delete status pages)
- **Backend rendering**: Laravel Blade for public-facing status pages
- **Routing**: Subdomain routing in Laravel (`{slug}.uptizm.com`)
- **Data model**: New `status_pages` table (team-level resource)

## Research Findings
- **Flutter side**: Routes, navigation, and deeplinks for `/status-pages` already scaffolded (placeholder "Coming Soon")
- **Flutter patterns**: Singleton controllers via `Magic.findOrPut`, `ValueNotifier` state, `MagicStatefulView` views, Wind UI only
- **Laravel patterns**: `{data, message}` JSON responses, Form Requests for validation, API Resources for transformation, Policy-based authorization
- **Laravel web routes**: Currently only `welcome` page — no subdomain routing exists yet
- **Blade templates**: Only `welcome.blade.php` exists — Tailwind CSS + dark mode
- **Team model**: Has `name`, `personal_team`, `owner_id` — NO slug field yet (needs migration)
- **Monitor data available**: `last_status`, `last_checked_at`, `last_response_time_ms` for real-time; `monitor_checks` table (TimescaleDB) for history
- **Alert data available**: Active alerts per monitor can be displayed on status page

## Open Questions
- ~~What monitors appear on status page?~~ → **Seçilebilir** (user picks which monitors appear)
- ~~Status page visibility model?~~ → **Sadece public** (always public, no password protection)
- ~~Incident management?~~ → **Sadece otomatik durum** (no manual incidents)
- ~~Historical uptime display?~~ → **90 gün bar chart** (daily uptime % per monitor)
- ~~Custom domain support?~~ → **Hayır, sadece subdomain** (`{slug}.uptizm.com`)
- ~~Components/groups on status page?~~ → **Hayır, düz liste** (flat list, no grouping)
- ~~Multiple status pages per team?~~ → **Evet, birden fazla** (each with own subdomain)
- ~~Configurable fields?~~ → **Temel set** (title, description, logo URL, primary color, favicon URL)
- ~~Response time display?~~ → **Basit gösterim** (last + avg response time per monitor)
- ~~Test strategy?~~ → **TDD** (RED-GREEN-REFACTOR, both Flutter and Laravel)

## Scope Boundaries
- INCLUDE: StatusPage CRUD (Flutter), StatusPage model/migration/API (Laravel), Blade rendering with subdomain routing, 90-day uptime bar chart, monitor selection, branding customization (title/desc/logo/color/favicon), response time display, TDD tests
- EXCLUDE: Custom domains, incident management, monitor grouping, password protection, custom CSS/HTML, email subscriptions, webhooks for status changes
