# Uptizm Brand Guidelines

> Deep uptime monitoring that goes beyond status codes.

## Brand Personality

**Primary Archetype**: Sage (70%)
Uptizm is the knowledgeable guide that sees what others miss. Where traditional monitors give a binary up/down, Uptizm reveals the deeper truth — surfacing hidden metrics before they become incidents.

**Secondary Archetype**: Hero (30%)
Empowering DevOps teams to act decisively with real data. The hero element drives confidence and reliability.

## Core Values

1. **Clarity**: Cut through noise to show what matters
2. **Precision**: Deep, accurate metrics — not surface-level checks
3. **Reliability**: Always-on monitoring you can trust
4. **Efficiency**: Instant insight without configuration overhead

## Color Palette

### Primary Colors

| Color          | HEX       | Usage                                    |
|----------------|-----------|------------------------------------------|
| Primary        | `#009E60` | Main brand color, CTAs, active states    |
| Primary Dark   | `#007A49` | Hover states, pressed states             |
| Background     | `#F5F8F7` | Light mode page background               |
| Background Dark| `#0F231B` | Dark mode page background                |
| Surface        | `#FFFFFF` | Light mode cards, panels                 |
| Surface Dark   | `#162E25` | Dark mode cards, panels                  |
| Text Main      | `#0C1D16` | Light mode primary text                  |
| Text Muted     | `#6B7280` | Secondary text, labels, placeholders     |

### Primary Scale (50-900)

| Shade | HEX       | Usage                          |
|-------|-----------|--------------------------------|
| 50    | `#ECFDF5` | Lightest backgrounds, tints    |
| 100   | `#D1FAE5` | Light backgrounds, badges      |
| 200   | `#A7F3D0` | Light accents, borders         |
| 300   | `#6EE7B7` | Disabled states                |
| 400   | `#34D399` | Secondary buttons, indicators  |
| 500   | `#009E60` | Primary (base color)           |
| 600   | `#007A49` | Hover states                   |
| 700   | `#005C37` | Active/pressed states          |
| 800   | `#064E3B` | Dark accents                   |
| 900   | `#022C22` | Darkest, text on light bg      |

### Semantic Colors

| Role    | HEX       | Usage                               |
|---------|-----------|--------------------------------------|
| Success | `#009E60` | Systems operational, "Up" indicators |
| Warning | `#F59E0B` | Degraded performance, cautions       |
| Error   | `#EF4444` | Down status, incidents, alerts       |
| Info    | `#3B82F6` | Informational badges, links          |

### Dark Mode Surfaces

| Element            | Light           | Dark            |
|--------------------|-----------------|-----------------|
| Page background    | `#F5F8F7`       | `#0F231B`       |
| Card/Surface       | `#FFFFFF`       | `#162E25`       |
| Border             | `#E5E7EB` (gray-200) | `#374151` (gray-700) |
| Input background   | `#F9FAFB` (gray-50)  | `#1F2937/50` (gray-800/50) |
| Muted text         | `#6B7280`       | `#9CA3AF` (gray-400) |

## Typography

### Font Stack

- **Display/Body**: Inter (clean, geometric, technical)
- **Monospace**: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas

### Type Scale

| Name  | Size  | Weight    | Line Height | Usage                    |
|-------|-------|-----------|-------------|--------------------------|
| xs    | 10px  | Medium    | 14px        | Timestamps, micro labels |
| sm    | 11px  | Semibold  | 16px        | Badges, tag text         |
| base  | 12px  | Bold      | 16px        | Labels (uppercase)       |
| md    | 13px  | Medium    | 20px        | Body text, descriptions  |
| lg    | 14px  | Semibold  | 20px        | Card titles, nav items   |
| xl    | 16px  | Bold      | 24px        | Section headings         |
| 2xl   | 18px  | Bold      | 28px        | Page titles              |
| 3xl   | 24px  | Bold      | 32px        | Stat card numbers        |
| 4xl   | 30px  | Bold      | 36px        | Hero headings            |

### Typography Patterns

- **Labels**: xs/sm size, UPPERCASE, bold, `tracking-wide`, text-muted color
- **Card section headers**: sm size, bold, with icon prefix in `bg-primary/10` pill
- **Stat numbers**: 3xl size, bold, text-main color
- **Monospace**: Used for URLs, JSON previews, assertion rules, response times

## Voice & Tone

### Brand Voice

- **Precise**: Use exact numbers and metrics, not vague descriptors
- **Calm authority**: Confident without being loud — like a trusted dashboard
- **Technical but accessible**: Developer-friendly language, no marketing fluff

### Tone by Context

| Context       | Tone              | Example                                          |
|---------------|-------------------|--------------------------------------------------|
| Dashboard UI  | Minimal, data-first| "11 Up" / "DB Conn: 45" / "240ms"              |
| Alerts        | Direct, urgent     | "API Core Service response time > 500ms"         |
| Empty states  | Helpful, guiding   | "Add your first monitor to start tracking"       |
| Errors        | Clear, actionable  | "Connection failed. Check the target URL."       |
| Marketing     | Confident, sharp   | "See what 200 OK is hiding."                     |

### Writing Guidelines

**Do:**
- Use short, scannable text in the UI
- Show numbers and metrics prominently
- Use monospace for technical values (URLs, JSON paths, response times)
- Use UPPERCASE + tracking-wide for section labels

**Don't:**
- Use vague language ("things look good")
- Add unnecessary words in status displays
- Use emoji in the product UI
- Write long paragraphs in dashboards

## Visual Principles

### Component Style

- **Cards**: Rounded-2xl (16px), soft shadow, 1px border (gray-100 / gray-800 dark)
- **Inputs**: Rounded-xl (12px), gray-50 bg, 1px border, primary focus ring
- **Buttons (primary)**: Rounded-xl, bg-primary, white text, shadow-lg with primary/30 tint
- **Buttons (secondary)**: Rounded-full for pills/tags, border style for unselected
- **Toggle switches**: 44x24px, primary color when active, gray-200 inactive

### Iconography

- **Icon set**: Material Symbols Outlined
- **Icon size**: 18-20px in UI, 14px in compact elements
- **Icon containers**: Rounded-lg pill with `bg-primary/10` tint and primary color icon

### Status Indicators

- **Operational/Up**: Pulsing green dot (`#009E60`) with glow shadow
- **Down/Incident**: Red dot (`#EF4444`)
- **Degraded**: Amber dot (`#F59E0B`)
- **Metric badges**: Rounded pill, mono font, border style (e.g., "DB Conn: 45")

### Spacing

- Base unit: 4px
- Common spacings: 4, 8, 12, 16, 20, 24, 32, 48, 64px
- Card padding: 20px (p-5)
- Section gaps: 20px (gap-5)
- Page horizontal padding: 20px (px-5)

### Border Radius Scale

| Token   | Value  | Usage                    |
|---------|--------|--------------------------|
| DEFAULT | 4px    | Small elements           |
| md      | 6px    | Badges, tags             |
| lg      | 8px    | Icon containers          |
| xl      | 12px   | Inputs, secondary buttons|
| 2xl     | 16px   | Cards, panels            |
| 3xl     | 24px   | Modal dialogs            |
| full    | 9999px | Pills, avatars, dots     |

### Shadows

| Name | Value                              | Usage              |
|------|------------------------------------|--------------------|
| soft | `0 4px 20px -2px rgba(0,0,0,0.05)` | Cards, panels     |
| glow | `0 0 15px rgba(0,158,96,0.3)`      | Active selections  |

### Layout Patterns (Cross-Platform)

**Desktop (>1024px):**
- Collapsible sidebar (240px) + top bar
- Content area with max-width constraint
- Stat cards in 4-column grid

**Tablet (768-1024px):**
- Collapsed sidebar (icons only, 64px)
- Stat cards in 2-column grid
- Monitor list in single column

**Mobile (<768px):**
- Bottom navigation bar (no sidebar)
- Stat cards in 2-column grid
- Full-width monitor cards
- Sticky header with back navigation

### Dashboard Structure

1. **Top Bar**: User profile, notification bell, theme toggle
2. **Sidebar**: Dashboard, Monitors, Incidents, Status Pages, Settings
3. **Stat Cards Row**: Total Monitors, Systems Up, Active Incidents, Avg Response Time
4. **Monitors Overview**: List/grid of monitors with status dot, name, sparkline, metric badge, response time
5. **Activity Panel**: Timeline of recent checks and alerts

---

*Generated with /my_brand on 2026-02-02*
