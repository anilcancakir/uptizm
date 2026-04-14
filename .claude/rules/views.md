---
path: "lib/resources/**/*.dart"
---

# Views & Widgets (Wind UI)

## Wind UI Widget System

All layout uses Wind UI widgets with Tailwind CSS className strings:

- `WDiv(className: '...', child/children: [])` -- container/div equivalent
- `WText('content', className: '...')` -- text rendering
- `WIcon(Icons.name, className: '...')` -- icon rendering
- `WSpacer(className: 'h-N w-N')` -- spacing
- `WAnchor(onTap: () => ..., child: ...)` -- tappable element
- `Launch.url('https://...')` -- open external URLs

className uses Tailwind utility classes: `flex`, `items-center`, `gap-3`, `p-4`, `rounded-xl`, `text-sm`, `font-bold`, `bg-primary`, `dark:bg-gray-800`, etc.

Multi-line className: use triple-quote strings for readability when >3 classes.

## Widget Organization

```
lib/resources/
├── views/
│   ├── components/           # Reusable widgets grouped by domain
│   │   ├── monitors/         # Monitor-specific components
│   │   ├── alerts/           # Alert-specific components
│   │   ├── analytics/        # Analytics-specific components
│   │   ├── charts/           # Chart widgets (MultiLineChart, StatusTimelineChart)
│   │   ├── dashboard/        # Dashboard components (StatCard, etc.)
│   │   └── navigation/       # App header, sidebar, etc.
│   ├── layouts/              # App shell layouts (managed by magic_starter)
│   ├── dashboard/            # Dashboard feature views
│   ├── monitors/             # Monitor feature views
│   ├── alerts/               # Alert feature views
│   ├── incidents/            # Incident feature views
│   ├── announcements/        # Announcement feature views
│   ├── status_pages/         # Status page feature views
│   ├── teams/                # Team management views
│   └── auth/                 # Authentication views (magic_starter manages these)
```

- Views are full screens registered in `routes/app.dart`
- Components are reusable, grouped by domain under `components/`
- Name convention: file name matches class name in snake_case
- View naming: `{feature}_{action}_view.dart`, e.g., `monitor_edit_view.dart`, `monitor_analytics_view.dart`

## View Pattern (magic_starter standard)

```dart
class MyFeatureView extends MagicStatefulView {
  const MyFeatureView({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'p-4 lg:p-6 flex flex-col gap-6',
      children: [
        AppPageHeader(title: trans('feature.title')),
        AppCard(children: [ /* content */ ]),
      ],
    );
  }
}
```

- NO `max-w-*` constraints, views are full-width
- NO `SingleChildScrollView`, layout's `overflow-y-auto` handles scrolling
- `AppPageHeader` for page titles with actions
- `AppCard` for content sections
- All strings via `trans('section.key')`

## STRICT: Widget Rules

NEVER use Flutter native equivalents when Wind UI provides them:

| DO NOT use         | USE instead                       |
|--------------------|------------------------------------|
| `Row`              | `WDiv(className: 'flex ...')`      |
| `Column`           | `WDiv(className: 'flex flex-col')` |
| `Container`        | `WDiv(className: '...')`          |
| `Expanded`         | `WDiv(className: 'flex-1')`       |
| `SizedBox`         | `WSpacer(className: 'h-N w-N')`   |
| `Text`             | `WText('...', className: '...')`  |
| `Icon`             | `WIcon(Icons.x, className: '...')`|
| `Padding`          | `WDiv(className: 'p-N')`         |

Exception: `SizedBox` is allowed as wrapper for `CircularProgressIndicator`.
