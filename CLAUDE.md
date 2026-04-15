# CLAUDE.md

This file provides guidance to Claude Code when working with code in this repository.

## Mission

Uptizm Flutter frontend: deep uptime monitoring that goes beyond status codes. Web + mobile from single codebase.
Built on **magic** + **magic_starter** framework (Laravel-inspired Flutter architecture) + **wind** (Tailwind-for-Flutter design system).

## Flutter SDK Packages (In-House)

| Package                            | Source Path                |
|------------------------------------|----------------------------|
| **Magic** (core framework)         | `references/magic`         |
| **Magic Starter** (app scaffold)   | `references/magic_starter` |
| **Wind UI** (Tailwind-for-Flutter) | `references/wind`          |

- **Read-only**: Research source locally for debugging and understanding internals
- **STRICT: NEVER fix bugs or make changes directly.** File a GitHub issue, the project's LLM agent handles implementation

## Commands

| Command                            | Description          |
|------------------------------------|----------------------|
| `flutter test`                     | Run all tests        |
| `flutter test test/path_test.dart` | Run single test file |
| `dart format lib/ test/`           | Format all Dart code |
| `dart analyze`                     | Static analysis      |
| `flutter run -d chrome`            | Run on web           |

## Architecture

Magic framework follows a **Laravel-inspired** directory convention:

```
lib/
├── main.dart                    # Magic.init() + WindThemeData + runApp
├── app/
│   ├── controllers/              # MagicController + MagicStateMixin (ValueNotifier-based)
│   ├── models/                   # Data models (extend magic's base Model)
│   ├── enums/                    # Business logic enums
│   ├── helpers/                  # Utility functions
│   ├── validation/               # Custom validation rules
│   ├── providers/                # Service + route providers (boot lifecycle)
│   ├── middleware/               # Route middleware (auth guards)
│   ├── listeners/                # Event handlers
│   └── policies/                 # Authorization policies
├── config/                       # Config files (app, auth, network, cache, database, view, etc.)
├── resources/
│   └── views/                    # All UI
│       ├── components/           # Reusable widgets grouped by domain
│       ├── layouts/              # App shell layouts (magic manages these)
│       ├── dashboard/            # Dashboard feature views
│       ├── monitors/             # Monitor feature views
│       ├── alerts/               # Alert feature views
│       ├── incidents/            # Incident feature views
│       ├── announcements/        # Announcement feature views
│       ├── status_pages/         # Status page feature views
│       ├── teams/                # Team management views
│       └── auth/                 # Authentication views
└── routes/                       # Route definitions (app.dart, auth.dart)
```

## Key Decisions

- **State**: `MagicController` + `MagicStateMixin` with `ValueNotifier` pattern, no Riverpod, no Bloc
- **HTTP**: `Http` facade, never raw Dio
- **Routing**: `MagicRoute.page()` / `MagicRoute.group()`, not raw GoRouter
- **IDs**: All model IDs are `String` (UUID), never `int`
- **Auth**: Sanctum token via magic_starter, do NOT reimplement auth/team/profile screens
- **Dart SDK**: ^3.11.0
- **UI**: Wind UI first, never Flutter native layout equivalents (see Widget Rules)
- **i18n**: All user-facing strings via `trans('dot.key')` from `assets/lang/en.json`, never hardcode strings in views
- **Code quality**: English only (code, comments, commits, task sections, documents); strict types on every param/return/property; zero `dart analyze` warnings, no suppressions
- **After every change**: run `dart analyze` (zero warnings) + `dart format lib/ test/` + `flutter test`

## i18n Rules (STRICT)

All user-facing strings MUST be in `assets/lang/en.json` and accessed via `trans()`.

- **Usage**: `trans('section.key')` from `package:magic/magic.dart`
- **Params**: `trans('key', {'param': value.toString()})`, placeholder syntax is `:param` in JSON
- **Key naming**: `{feature}.{context}`, e.g., `dashboard.title`, `monitors.empty_title`
- **Shared strings**: `common.*` (cancel, save, delete), `errors.*`, `validation.*`
- **DO NOT**: hardcode strings in views, use Flutter `intl`/`arb`, use `{}` or `%s` placeholders

## Design System

- Primary color: Green `#009E60` (hue 155)
- Brand identity: Sage (70%) + Hero (30%)
- Wind UI tokens for all spacing, typography, and components
- Dark mode support via WindThemeData

## Testing

- Widget tests: use shared `test/test_setup.dart` with `initMagicForTests()` for Magic framework initialization
- Wind UI flex layouts need larger test viewport (1440x900) with `tester.view.physicalSize`
- WDiv overflow: suppress via `FlutterError.onError` filter in test helpers
- Async views (HTTP in onInit): use `tester.runAsync()` or set data after network call fails
- Controllers: test via `ValueNotifier` state transitions

## Skills (Always Active)

| Skill             | When                                                             | Priority  |
|-------------------|------------------------------------------------------------------|-----------|
| `magic-framework` | **Every** code task: framework patterns, conventions, lifecycle  | Mandatory |
| `wind-ui`         | **Every** UI task: Wind UI tokens, components, theme system      | Mandatory |
| `frontend-design` | **Every** design/UI task: visual hierarchy, layout, components   | Mandatory |

## Agent Context (MUST inject into all subagent prompts)

When spawning agents (Agent tool, ac:execute workers, background tasks), include these non-negotiable rules in their
prompt, subagents do NOT auto-inherit CLAUDE.md:

1. **Wind UI only**: WDiv/WText/WIcon/WSpacer/WAnchor with className. Never Row, Column, Container, Expanded,
   SizedBox (except CircularProgressIndicator wrapper), Icon, Text, TextFormField
2. **i18n via trans()**: All user-facing strings from `assets/lang/en.json` via `trans('section.key')`. Never hardcode
   strings in views. Params: `trans('key', {'param': value.toString()})`, placeholder syntax `:param`
3. **Component structure**: Reusable widgets in `lib/resources/views/components/{domain}/`. Feature views in
   `lib/resources/views/{feature}/`
4. **Layout standard**: Root `WDiv(className: 'p-4 lg:p-6 flex flex-col gap-6')` + AppPageHeader + AppCard. No *max-w-*, no SingleChildScrollView
5. **w-full on centered containers**: WDiv with `flex items-center justify-center` MUST include `w-full`
6. **Magic framework**: Http facade, MagicRoute, MagicController+MagicStateMixin, Vault, Config, Log. Never raw Dio, GoRouter, print

## Gotchas

- `.env` is loaded as a Flutter asset (declared in pubspec.yaml), not dart-define
- Test setup excludes `NetworkServiceProvider` and `DatabaseServiceProvider` to avoid Timer pending issues
- `Monitor.find()` and similar model queries require NetworkServiceProvider; in tests, they throw and get caught by controller try/catch
- `addPostFrameCallback` in `onInit` means network calls fire after first build; tests must account for this timing
