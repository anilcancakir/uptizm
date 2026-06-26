# Wind responsive layout (Flutter reference)

Read this when building any screen layout. Wind's responsive system is breakpoint-prefix-driven,
not CSS media-query-driven. This doc covers breakpoints, page containers, safe-area handling,
navigation layout patterns, hit targets, and mobile-first rules.

## Breakpoints

Wind uses three responsive prefixes. They apply to the logical viewport width reported by
`MediaQuery.of(context).size.width`:

| Prefix | Min width | Typical device |
|---|---|---|
| (none, default) | 0px | All screens, mobile first |
| `sm:` | 480px | Large phones, landscape |
| `md:` | 768px | Tablets, desktop |
| `lg:` | 1024px | Wide desktop |

Breakpoints are mobile-first: a class without a prefix applies to ALL widths; a prefixed class
overrides from that breakpoint up.

```dart
// Single-column on mobile, two-column on md+
WDiv(
  className: 'flex flex-col md:flex-row gap-4',
  children: [MainContent(), Sidebar()],
)
```

Wind has no CSS `@media` query mechanism; the breakpoint resolution runs inside the Wind parser
using the current `MediaQuery` width. Do not try to use CSS media queries or `LayoutBuilder`
directly for layout decisions that are already expressible as Wind breakpoint prefixes.

## PageContainer

`PageContainer` (from magic_starter) constrains the content width and applies consistent
horizontal gutters. Always wrap page-level content in `PageContainer`; do not build custom max-
width constraints per-screen.

```dart
PageContainer(
  child: WDiv(
    className: 'flex flex-col gap-6 py-6',
    children: [...],
  ),
)
```

`PageContainer` sets `maxWidth: 1200px` and the horizontal padding:

- Mobile: `px-4` (16px each side)
- `md:` and above: `px-6` (24px each side)

Do not add extra horizontal padding inside `PageContainer`; let it handle the gutters.

## Safe area handling

On iOS and Android, system UI (status bar, home indicator, notch) can overlap content. Always
wrap the root of a full-screen page in `SafeArea`:

```dart
// In a page-level build method
Scaffold(
  body: SafeArea(
    child: PageContainer(child: content),
  ),
)
```

`AppLayout` and `GuestLayout` apply `SafeArea` at the shell level; views rendered inside the
layout shell do NOT need to re-wrap in `SafeArea`.

For custom bottom actions (a sticky CTA at the bottom of the screen), account for the home
indicator via `MediaQuery.viewPaddingOf(context).bottom`:

```dart
Padding(
  padding: EdgeInsets.only(
    bottom: MediaQuery.viewPaddingOf(context).bottom + 16,
  ),
  child: ActionButton(),
)
```

Dialog safe area uses the modal formula: `safeHeight = screenHeight - top - bottom insets`,
then `maxHeight = safeHeight * 0.85`. The `Dialog` and `BottomSheet` components in magic_starter
handle this automatically via `MediaQuery.viewPaddingOf(context)`.

## Navigation: sidebar vs bottom nav

The `AppLayout` shell switches navigation patterns by breakpoint:

| Breakpoint | Navigation pattern |
|---|---|
| Below `md:` (mobile) | Bottom navigation bar + hamburger drawer |
| `md:` and above | Sidebar navigation rail |

To implement this in a custom layout follow the `AppLayout` pattern:

```dart
// Show sidebar on desktop, hide on mobile
WDiv(
  className: 'hidden md:flex flex-col w-64 bg-surface-container border-r border-color-border',
  children: [SidebarContent()],
)

// Show bottom nav on mobile, hide on desktop
WDiv(
  className: 'flex md:hidden flex-row bg-surface-container border-t border-color-border',
  children: [BottomNavItems()],
)
```

Never show both a sidebar and a bottom nav at the same breakpoint. On mobile, the sidebar
appears as a drawer (use `Drawer` + `Scaffold.drawer`).

## 44px hit targets

Every interactive element must have a minimum 44x44 logical-px hit target (Apple HIG / Material
recommendation; WCAG 2.5.8 floor is 24px).

In Wind className: `min-h-11` equals 44px logical height. The default Button `md`/`lg` sizes
already clear this.

For icon-only controls (icon buttons, nav items, close buttons):

```dart
// Ensure 44px hit target on a small icon
SizedBox(
  width: 44,
  height: 44,
  child: Center(
    child: Icon(Icons.close, size: 20),
  ),
)
```

Or with Wind: `WButton(className: 'min-h-11 min-w-11 p-0 flex items-center justify-center', ...)`

Bottom nav items must be at least 44px tall. Sidebar nav items should be at least 44px tall with
sufficient horizontal padding (`py-2 px-3` minimum on a nav item gives ~40px; add `min-h-11` to
guarantee it).

## Mobile-first composition rules

1. Start with the mobile layout (no prefix). Confirm it reads on a 375px width.
2. Add `md:` overrides for tablet/desktop changes (column -> row, hidden -> shown, etc.).
3. Add `lg:` overrides only when desktop needs a third layout step.
4. Never write `sm:hidden` or `sm:flex` as the primary display rule; start mobile-visible, then
   hide at breakpoints.

```dart
// Correct: visible by default, hidden on desktop
WDiv(className: 'flex md:hidden', children: [MobileMenu()])

// Correct: hidden by default (inline), visible on desktop
WDiv(className: 'hidden md:flex', children: [Sidebar()])
```

## Text and content constraints

Do not fill available width with text. Constrain reading columns:

```dart
ConstrainedBox(
  constraints: const BoxConstraints(maxWidth: 600),
  child: WText(longBodyText, className: 'text-fg body-md'),
)
```

Inside `PageContainer`, text naturally hits the container max-width. For individual narrow
columns (auth forms, settings cards) apply an inner `ConstrainedBox(maxWidth: 480)`.

## WindRecipe with responsive variants

When a component has a layout-responsive variant, express it in the recipe's `className` caller
rather than as a recipe variant axis. Responsive breakpoints are caller context, not component
internals:

```dart
// Caller supplies the responsive layout via className
Card(
  className: 'w-full md:w-1/2 lg:w-1/3',
  child: ...,
)
```

The `WindRecipe` base and variant tokens handle visual state (tone, size, intent). Responsive
width is caller responsibility.

## Platform prefixes

Wind provides platform prefixes for platform-specific styles:

| Prefix | Platform |
|---|---|
| `ios:` | iOS |
| `android:` | Android |
| `web:` | Flutter Web |
| `mobile:` | iOS + Android |
| `macos:` | macOS |

Use these sparingly for platform-specific affordances (for example, `ios:rounded-xl` to apply
a rounder corner on iOS only). Do not use them as a substitute for breakpoint-based layout.

## Common layout patterns

### Full-screen auth page (GuestLayout shell)

```dart
// GuestLayout constrains to 480px max-width, centered, scrollable
// Views inside it use:
WDiv(
  className: 'flex flex-col gap-6 px-4 py-8',
  children: [
    Logo(),
    Card(child: LoginForm()),
    SocialDivider(),
    SocialButtons(),
  ],
)
```

### Dashboard with sidebar (AppLayout shell on desktop)

```dart
// AppLayout provides the sidebar rail on md+; the view sees only the content slot
WDiv(
  className: 'flex flex-col gap-6 p-6',
  children: [
    PageHeader(title: 'Dashboard'),
    StatsGrid(),
    RecentActivity(),
  ],
)
```

### Responsive two-column form

```dart
WDiv(
  className: 'grid grid-cols-1 md:grid-cols-2 gap-4',
  children: [
    FormField(label: 'First name', child: Input(...)),
    FormField(label: 'Last name', child: Input(...)),
  ],
)
```

## See also

- [DESIGN.md](../DESIGN.md): spacing scale (4px logical grid), gutter/section values
- [accessibility-wcag.md](accessibility-wcag.md): 44px hit targets, SafeArea requirements
- [material-design-3.md](material-design-3.md): navigation by breakpoint (M3 guidance)
- [apple-hig.md](apple-hig.md): screen-edge margins, concentric radii
- [refactoring-ui.md](refactoring-ui.md): whitespace, text column constraints

## Sources

- magic_starter `AppLayout` and `GuestLayout` source (`lib/src/ui/layouts/`).
- Wind breakpoint prefix documentation (`wind/CLAUDE.md`).
- Flutter documentation: MediaQuery, SafeArea, Scaffold.drawer, ConstrainedBox.
- Apple HIG Layout guidelines; Material 3 navigation components.
