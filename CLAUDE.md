# Uptizm

Uptime monitoring platform with response body parsing for deep health metrics. Flutter (Magic Framework) + Laravel.

## TDD: NON-NEGOTIABLE

**RED → GREEN → REFACTOR** for every change.

- No code without a failing test first
- No bug fix without a failing test reproducing it first
- Run `flutter test` / `cd back-end && php artisan test` after every change
- Test behavior, not implementation

| Layer | Test | Type |
|-------|------|------|
| Enums | All values, `fromValue()`, `selectOptions` | Unit |
| Models | `fromMap()`/`toMap()`, typed getters, computed props | Unit |
| Components | Renders correctly, null/empty/error edge cases | Widget |
| Controllers | Actions return correct widgets, state changes | Unit |
| API responses | Null fields, wrapped data, error states | Widget |
| Views | Key elements render, form validation | Widget |

## Commands

| Command | Description |
|---------|-------------|
| `flutter test` | Run Flutter tests |
| `flutter run -d chrome` | Run web app |
| `cd back-end && php artisan test` | Run Laravel tests |
| `cd back-end && php artisan serve` | Run Laravel API |
| `dart format` | Format code (NOT `flutter format`) |

## Skills

Activate skills from `.claude/skills/` based on task:

| Task | Activate |
|------|----------|
| Building/styling views | `wind-ui` + `flutter-design` + `mobile-app-design-mastery` |
| Controllers/models/routes/auth/events/API calls | `magic-framework` |
| Forms with validation | `wind-ui` + `magic-framework` |

## Tech Stack & Plugins

- **Frontend**: Flutter 3.10+ with Magic Framework (`fluttersdk_magic`)
- **UI**: Wind UI (Tailwind-like utility classes for Flutter)
- **Backend**: Laravel in `back-end/`
- **Auth**: Magic Auth + Social Auth (`fluttersdk_magic_social_auth`)

| Plugin | Path |
|--------|------|
| Magic Framework | `plugins/fluttersdk_magic/` |
| Wind UI | `plugins/fluttersdk_magic/plugins/fluttersdk_wind/` |
| Social Auth | `plugins/fluttersdk_magic_social_auth/` |

Modify plugin source directly when framework-level changes are needed.

## Architecture

```
lib/
├── main.dart                    # App entry, WindTheme, Magic.init()
├── config/                      # Config maps (app, auth, network, social_auth)
├── routes/                      # Route registration (auth.dart, app.dart)
├── app/
│   ├── controllers/             # Singleton controllers, actions return Widgets
│   ├── models/                  # Eloquent-style models (User, Team)
│   ├── enums/                   # Enums with fromValue(), selectOptions
│   ├── middleware/              # auth (EnsureAuthenticated), guest (RedirectIfAuthenticated)
│   ├── listeners/              # Event listeners
│   ├── policies/               # Authorization (extend Policy, NOT MagicPolicy)
│   ├── providers/              # Service providers (registered in config/app.dart)
│   └── streams/                # Reactive streams
└── resources/views/
    ├── auth/                   # Login, register, forgot/reset password
    ├── layouts/                # AppLayout, GuestLayout
    ├── components/             # Reusable UI (navigation/, settings/)
    └── teams/                  # Team views
```

Backend: Standard Laravel. Routes in `back-end/routes/api/v1.php` (NOT root `api.php`).

## Naming

| Type | Convention | Example |
|------|-----------|---------|
| Files | `snake_case.dart` | `monitor_type.dart` |
| Classes | `PascalCase` | `MonitorType` |
| Variables/functions | `camelCase` | `checkInterval` |
| Routes | kebab-case | `/status-pages` |

## Imports

```dart
// CORRECT: Relative imports within lib/
import '../../../app/models/monitor.dart';

// WRONG: Package imports within lib/
import 'package:uptizm/app/models/monitor.dart';

// CORRECT: Package imports for external deps only
import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
```

## Critical Rule: Wind UI Only

**NEVER use Flutter widgets directly in views.** Always use Wind equivalents.

| Flutter (WRONG) | Wind (CORRECT) |
|------------------|----------------|
| `Container` | `WDiv` |
| `Text` | `WText` |
| `TextField` | `WInput` |
| `SelectableText` | `WText(selectable: true)` |
| `ElevatedButton` / `TextButton` | `WButton` |
| `IconButton` | `WButton(child: WIcon(...))` |
| `Row` / `Column` | `WDiv` with flex classes |

Before building ANY custom widget, check framework first:
1. `ls plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/`
2. `ls lib/resources/views/components/`

## API Name Reference (VERIFY, NEVER GUESS)

| Wrong (guessed) | Correct (actual) |
|------------------|------------------|
| `response.success` | `response.successful` |
| `Http.get(url, queryParams:)` | `Http.get(url, query:)` |
| `Route.back()` / `Route.to()` | `MagicRoute.back()` / `MagicRoute.to()` |
| `MagicRoute.params['id']` | `MagicRouter.instance.pathParameter('id')` |
| `extends MagicPolicy` | `extends Policy` |
| `Numeric()` validator | Use `InputType.number` on the widget |
| `String get endpoint` | `String get table` + `String get resource` |
| `get<String>('name')` | `getAttribute('name') as String?` |
| `flutter format` | `dart format` |

Before using ANY facade method, verify in source:
```bash
grep -A 5 "static Future.*get\b" plugins/fluttersdk_magic/lib/src/facades/http.dart
grep "get " plugins/fluttersdk_magic/lib/src/network/magic_response.dart
ls plugins/fluttersdk_magic/lib/src/validation/rules/
```

## Model Pattern (REQUIRED)

```dart
class Monitor extends Model with HasTimestamps, InteractsWithPersistence {
  @override String get table => 'monitors';
  @override String get resource => 'monitors';
  @override List<String> get fillable => ['name', 'url', 'type'];

  String? get name => getAttribute('name') as String?;
  set name(String? value) => setAttribute('name', value);

  static Future<Monitor?> find(dynamic id) =>
      InteractsWithPersistence.findById<Monitor>(id, Monitor.new);

  static Monitor fromMap(Map<String, dynamic> map) {
    return Monitor()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }
}
```

**Checklist**: mixins `HasTimestamps, InteractsWithPersistence` | overrides `table`, `resource`, `fillable` | `getAttribute()` for getters | `fromMap()` with `setRawAttributes` | `find()` with `findById`

## Eloquent vs Http

| Use Eloquent | Use Http |
|-------------|----------|
| Single record CRUD | Search/filters/pagination |
| Type-safe model access | Custom endpoints |
| Authenticated user data | Bulk operations |

```dart
// Eloquent CRUD
final user = await User.find(1);
user?.name = 'Updated';
await user?.save();

// Http for search
Http.get('/monitors', query: {'team_id': 1});
Http.post('/monitors', data: {'name': 'Test'});
```

## Validation Rules

```dart
// Available: Required(), Email(), Min(3), Max(255), Between(1,100), Confirmed(), In([...])
// NOT available: Numeric(), Alpha()

// IMPORTANT: Min()/Max() check STRING LENGTH, not numeric value
// For numeric validation, create custom rules in lib/app/validation/rules/
```

Custom numeric rule pattern:
```dart
class MinNumeric extends Rule {
  final num min;
  MinNumeric(this.min);

  @override
  bool passes(String attribute, dynamic value, Map<String, dynamic> data) {
    if (value == null || value.toString().isEmpty) return true;
    final numValue = num.tryParse(value.toString());
    return numValue != null && numValue >= min;
  }

  @override String message() => 'validation.min_numeric';
  @override Map<String, dynamic> params() => {'min': min};
}
```

Add translation keys in `assets/lang/en.json` and field names in `attributes`.

## Policy Pattern

```dart
class MonitorPolicy extends Policy {
  @override
  void register() {
    Gate.define('view-monitor', _view);
  }
  bool _view(Authenticatable user, dynamic arguments) => arguments != null;
}
// Register in app_service_provider.dart: MonitorPolicy().register()
```

## Design Guidelines

- **Primary**: `#009E60` — use `bg-primary`, `text-primary`, `bg-primary/10`
- **Font**: Inter (body), monospace for technical values
- **Style**: Cards with `rounded-2xl`, soft shadow, 1px border
- **Dark mode**: ALWAYS implement both light and dark variants
- **Icons**: Material Symbols Outlined only
- **Status**: Green = Up, Red = Down, Amber = Degraded
- **Responsive**: Desktop sidebar (240px) → Tablet collapsed (64px) → Mobile bottom nav
- **Input standard**: `px-3 py-3 rounded-lg text-sm` for all inputs/selects

## Hooks

Auto-format hook runs `dart format` on any `.dart` file after Edit or Write tool use. Configured in `.claude/settings.json` under `hooks.PostToolUse`.

## Subagents

Custom agents in `.claude/agents/`:

| Agent | File | Use When |
|-------|------|----------|
| TDD Enforcer | `tdd-enforcer.md` | Writing new features, fixing bugs — enforces RED→GREEN→REFACTOR cycle |
| Code Reviewer | `code-reviewer.md` | Before commits — checks Wind UI compliance, imports, dark mode, API names, gotchas |

## Gotchas & Lessons Learned

**Framework behavior:**
- Wind className typos silently fail (runtime parsing, no compile errors)
- `.env` is bundled as Flutter asset — no backend-only secrets
- Route transitions default to platform; use `.transition(RouteTransition.none)` for instant
- Wind `overflow-auto`/`overflow-scroll` does NOT create scrollable containers — use `SingleChildScrollView`/`ListView`

**API responses:**
- Laravel wraps in `{"data": {...}}` — unwrap: `response.data['data']`
- Never `as int` on API fields — use `(value as num?)?.toInt() ?? 0`
- Http base URL auto-prepends from `lib/config/network.dart` — always use relative paths

**Common mistakes to avoid:**
- Don't build custom components before checking Wind UI widgets (`searchable: true`, `onSearch`, `onCreateOption` exist)
- Don't build custom form inputs — `WFormInput`/`WFormSelect`/`WFormCheckbox` have built-in validation, labels, hints, errors
- Don't use `Config.get('app.apiUrl')` — doesn't exist, causes null concatenation
- `WFormMultiSelect.onCreateOption` must add option to a state list or it disappears
- All inputs in same context must use identical padding (no `py-2` next to `py-3`)
