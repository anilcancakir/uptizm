# UPTIZM KNOWLEDGE BASE

**Generated:** 2026-02-07 | **Commit:** e567c9e | **Branch:** master

## OVERVIEW

Deep uptime monitoring platform. Flutter (Magic Framework) + Laravel API. Tracks response body metrics (DB connections, queue sizes) beyond HTTP status codes.

## DUAL PURPOSE

1. **Application**: Uptizm uptime monitoring platform
2. **Framework Development**: Improving custom plugins and their skills

## PRIMARY GOAL: PLUGIN DEVELOPMENT

**This project exists primarily to develop and improve our custom Flutter frameworks.**

The Uptizm app serves as a real-world testbed. When you encounter bugs or unexpected behavior:

### Bug Investigation Protocol

1. **First**: Check if the issue originates from plugin source code
2. **If plugin-related**: Fix in plugin, not workaround in app
3. **If app-related**: Fix in app code

### Plugin Source Locations

| Plugin | Source Path | Skill |
|--------|-------------|-------|
| **Magic Framework** | `plugins/fluttersdk_magic/` | `.opencode/skills/magic-framework/` |
| **Wind UI** | `plugins/fluttersdk_magic/plugins/fluttersdk_wind/` | `.opencode/skills/wind-ui/` |

### Key Plugin Directories

```
plugins/fluttersdk_magic/                    # Magic Framework core
├── lib/src/
│   ├── foundation/                          # IoC container, service providers
│   ├── facades/                             # Auth, Http, Route, Config, etc.
│   ├── database/eloquent/                   # Model, HasTimestamps, relationships
│   ├── routing/                             # MagicRouter, middleware
│   └── http/                                # Http client, Response
└── plugins/fluttersdk_wind/                 # Wind UI (nested plugin)
    └── lib/src/
        ├── widgets/                         # WDiv, WText, WButton, WFormInput...
        ├── core/parser/                     # className string parser
        └── theme/                           # WindTheme, color utilities
```

### When to Modify Plugins

| Symptom | Action |
|---------|--------|
| Widget behaves unexpectedly | Check `fluttersdk_wind/lib/src/widgets/` |
| className not parsing correctly | Check `fluttersdk_wind/lib/src/core/parser/` |
| Facade method missing/wrong | Check `fluttersdk_magic/lib/src/facades/` |
| Model persistence issue | Check `fluttersdk_magic/lib/src/database/eloquent/` |
| Routing not working | Check `fluttersdk_magic/lib/src/routing/` |
| Http response handling wrong | Check `fluttersdk_magic/lib/src/http/` |

### After Plugin Changes

1. Update corresponding skill in `.opencode/skills/`
2. Test in Uptizm app
3. Run plugin tests if available

## STRUCTURE

```
uptizm/
├── lib/                    # Flutter app (Magic Framework)
│   ├── app/
│   │   ├── controllers/    # Singleton controllers, Laravel-style actions
│   │   ├── models/         # Eloquent-style models with InteractsWithPersistence
│   │   ├── enums/          # fromValue() + selectOptions pattern
│   │   ├── policies/       # extends Policy (NOT MagicPolicy)
│   │   ├── providers/      # Service providers registered in config/app.dart
│   │   └── validation/rules/  # Custom validators (MinNumeric, MaxNumeric)
│   ├── config/             # App configuration (app, auth, network, etc.)
│   ├── routes/             # auth.dart, app.dart (Laravel-style routing)
│   └── resources/views/    # Wind UI components, layouts, pages
├── back-end/               # Laravel 11 API
│   ├── app/                # Controllers, Models, Services, Jobs
│   ├── routes/api/v1.php   # API routes (NOT root api.php)
│   └── tests/              # PHPUnit Feature/Unit tests
├── test/                   # Flutter tests (mirrors lib/)
├── plugins/                # Custom frameworks (see plugins/AGENTS.md)
│   ├── magic/              # SYMLINK → fluttersdk_magic core
│   ├── magic_notifications/
│   ├── magic_social_auth/
│   └── magic_deeplink/
└── .opencode/skills/       # wind-ui, magic-framework, flutter-design, mobile-app-design-mastery
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Add monitor feature | `lib/app/controllers/`, `lib/resources/views/monitors/` | Controller + View pair |
| Add API endpoint | `back-end/routes/api/v1.php`, `back-end/app/Http/Controllers/Api/V1/` | Form Request + Resource |
| Add Flutter model | `lib/app/models/` | Copy Monitor pattern |
| Add Wind widget | Check `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/` FIRST | Don't build custom if exists |
| Improve Magic Framework | `plugins/fluttersdk_magic/` | Update skill in `.opencode/skills/magic-framework/` |
| Improve Wind UI | `plugins/fluttersdk_magic/plugins/fluttersdk_wind/` | Update skill in `.opencode/skills/wind-ui/` |

## SKILLS USAGE

| Domain | Activate Skills |
|--------|-----------------|
| Views/Styling | `wind-ui` + `flutter-design` + `mobile-app-design-mastery` |
| Controllers/Models/API | `magic-framework` |
| Forms | `wind-ui` + `magic-framework` |

## TDD: NON-NEGOTIABLE

**RED → GREEN → REFACTOR** — No code without failing test first.

| Layer | Test Location | Focus |
|-------|---------------|-------|
| Enums | `test/app/enums/` | `fromValue()`, `selectOptions` |
| Models | `test/app/models/` | `fromMap()`/`toMap()`, typed getters |
| Controllers | `test/app/controllers/` | Actions, state changes |
| Views | `test/resources/views/` | Key elements, form validation |
| Backend | `back-end/tests/Feature/` | API, validation, policies |

## COMMANDS

```bash
# Flutter
flutter test                    # Run all tests
flutter test test/app/models/   # Run specific tests
flutter run -d chrome           # Run web
dart format .                   # Format (NOT flutter format)

# Laravel
cd back-end && php artisan test         # Run tests
cd back-end && php artisan test --filter=MonitorApiTest  # Specific test
```

## CONVENTIONS

### Naming

| Type | Convention | Example |
|------|------------|---------|
| Files | `snake_case.dart` / `PascalCase.php` | `monitor_controller.dart` |
| Classes | `PascalCase` | `MonitorController` |
| Variables | `camelCase` | `selectedMonitor` |
| Routes | `kebab-case` | `/status-pages` |

### Imports (Flutter)

```dart
// ✅ Relative within lib/
import '../../../app/models/monitor.dart';

// ❌ Package imports within lib/
import 'package:uptizm/app/models/monitor.dart';

// ✅ Package for externals only
import 'package:flutter/material.dart';
```

## API NAMES (VERIFY, NEVER GUESS)

| ❌ Wrong | ✅ Correct |
|---------|-----------|
| `response.success` | `response.successful` |
| `Http.get(url, queryParams:)` | `Http.get(url, query:)` |
| `Route.back()` | `MagicRoute.back()` |
| `MagicRoute.params['id']` | `MagicRouter.instance.pathParameter('id')` |
| `extends MagicPolicy` | `extends Policy` |
| `getAttribute('name')` | `get<String>('name')` (in models) |
| `as int` | `(value as num?)?.toInt() ?? 0` |

## ANTI-PATTERNS

### Framework
- `className` typos fail silently (runtime parsing)
- `overflow-y-auto` needs `scrollPrimary: true` for iOS tap-to-top
- `.env` bundled as Flutter asset — NO backend secrets

### Type Safety
- NEVER `as int` — Laravel returns num, use safe cast
- NEVER `@ts-ignore`, `as any` equivalents

### API
- Laravel wraps `{"data": {...}}` — unwrap: `response.data['data']`
- Use relative paths with Http (base URL auto-prepends)

### UI
- Check Wind widgets BEFORE building custom
- `WFormMultiSelect.onCreateOption` must `setState(() => options.add(opt))`
- All inputs same padding context (`py-3` everywhere)

## DESIGN (brand.md)

- **Primary**: `#009E60` — `bg-primary`, `text-primary`, `bg-primary/10`
- **Dark mode**: REQUIRED for all views (`dark:` variants)
- **Icons**: Material Symbols Outlined only
- **Input**: `px-3 py-3 rounded-lg text-sm`
- **Cards**: `rounded-2xl`, soft shadow, 1px border
- **Spacing**: 4px base grid (4, 8, 12, 16, 20, 24, 32, 48, 64px)

## GOTCHAS

1. **Backend routes**: `back-end/routes/api/v1.php` NOT `api.php`
2. **Multi-tenancy**: Controllers scope by `current_team_id`
3. **Model persistence**: Framework handles `{data: {...}}` unwrapping
4. **Wind searchable**: `WFormSelect` has `searchable`, `onSearch`, `onCreateOption` built-in
5. **Test harnesses**: Flutter widget tests use simplified wrappers for layout constraints
