# Uptizm

Uptime monitoring platform. Flutter (Magic Framework) + Laravel.

## TDD: NON-NEGOTIABLE

**RED → GREEN → REFACTOR** — No code without failing test first.

| Layer | Test |
|-------|------|
| Enums | `fromValue()`, `selectOptions` |
| Models | `fromMap()`/`toMap()`, typed getters |
| Components | Renders, null/empty/error states |
| Controllers | Actions, state changes |
| Views | Key elements, form validation |

## Commands

```bash
flutter test              # Flutter tests
flutter run -d chrome     # Run web
cd back-end && php artisan test   # Laravel tests
dart format .             # Format (NOT flutter format)
```

## Skills

| Task | Activate |
|------|----------|
| Views/styling | `wind-ui` + `flutter-design` + `mobile-app-design-mastery` |
| Controllers/models/API | `magic-framework` |
| Forms | `wind-ui` + `magic-framework` |

## Architecture

```
lib/
├── config/           # app, auth, network, social_auth
├── routes/           # auth.dart, app.dart
├── app/
│   ├── controllers/  # Singleton, actions return Widgets
│   ├── models/       # Eloquent-style (User, Team, Monitor)
│   ├── enums/        # fromValue(), selectOptions
│   ├── middleware/   # auth, guest
│   ├── policies/     # extends Policy (NOT MagicPolicy)
│   └── providers/    # registered in config/app.dart
└── resources/views/
    ├── layouts/      # AppLayout, GuestLayout
    └── components/   # navigation/, settings/, monitors/
```

Backend: `back-end/routes/api/v1.php` (NOT root `api.php`)

## Naming

| Type | Convention |
|------|------------|
| Files | `snake_case.dart` |
| Classes | `PascalCase` |
| Variables | `camelCase` |
| Routes | `kebab-case` |

## Imports

```dart
// ✅ Relative within lib/
import '../../../app/models/monitor.dart';

// ❌ Package imports within lib/
import 'package:uptizm/app/models/monitor.dart';

// ✅ Package for externals only
import 'package:flutter/material.dart';
```

## Wind UI Only (views)

| Flutter ❌ | Wind ✅ |
|-----------|---------|
| `Container` | `WDiv` |
| `Text` | `WText` |
| `TextField` | `WInput` / `WFormInput` |
| `Row`/`Column` | `WDiv` + flex classes |
| `ElevatedButton` | `WButton` |

Check before building custom: `ls plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/`

## API Names (VERIFY, NEVER GUESS)

| ❌ Wrong | ✅ Correct |
|---------|-----------|
| `response.success` | `response.successful` |
| `Http.get(url, queryParams:)` | `Http.get(url, query:)` |
| `Route.back()` | `MagicRoute.back()` |
| `MagicRoute.params['id']` | `MagicRouter.instance.pathParameter('id')` |
| `extends MagicPolicy` | `extends Policy` |
| `get<String>('name')` | `getAttribute('name') as String?` |

## Model Pattern

```dart
class Monitor extends Model with HasTimestamps, InteractsWithPersistence {
  @override String get table => 'monitors';
  @override String get resource => 'monitors';
  @override List<String> get fillable => ['name', 'url', 'type'];

  String? get name => getAttribute('name') as String?;
  set name(String? value) => setAttribute('name', value);

  static Future<Monitor?> find(dynamic id) =>
      InteractsWithPersistence.findById<Monitor>(id, Monitor.new);

  static Monitor fromMap(Map<String, dynamic> map) => Monitor()
    ..setRawAttributes(map, sync: true)
    ..exists = map.containsKey('id');
}
```

## Eloquent vs Http

| Eloquent | Http |
|----------|------|
| Single CRUD | Search/filters/pagination |
| Type-safe access | Custom endpoints |

## Validation

```dart
// Available: Required(), Email(), Min(3), Max(255), Between(1,100), Confirmed(), In([...])
// Min()/Max() check STRING LENGTH, not numeric value
// For numeric: create custom rules in lib/app/validation/rules/
```

## Design

- **Primary**: `#009E60` — `bg-primary`, `text-primary`, `bg-primary/10`
- **Dark mode**: ALWAYS both variants
- **Icons**: Material Symbols Outlined only
- **Input**: `px-3 py-3 rounded-lg text-sm`
- **Cards**: `rounded-2xl`, soft shadow, 1px border

## Hooks

Auto-format runs `dart format` after Edit/Write. See `.claude/settings.json`.

## Gotchas

**Framework:**
- className typos silently fail (runtime parsing)
- `overflow-y-auto` needs `scrollPrimary: true` for iOS tap-to-top
- `.env` bundled as Flutter asset — no backend secrets

**API:**
- Laravel wraps `{"data": {...}}` — unwrap: `response.data['data']`
- Never `as int` — use `(value as num?)?.toInt() ?? 0`
- Http base URL auto-prepends — use relative paths

**Common mistakes:**
- Don't build custom widgets before checking Wind (`searchable`, `onSearch`, `onCreateOption` exist)
- `WFormMultiSelect.onCreateOption` must `setState(() => options.add(opt))`
- All inputs same context: identical padding (`py-3` everywhere)
