# UPTIZM KNOWLEDGE BASE

**Generated:** 2026-02-06 | **Commit:** 6ab20bf | **Branch:** master

## OVERVIEW

Uptime monitoring platform. Flutter frontend (Magic Framework + Wind UI) with symlinked Laravel backend. Monitors HTTP/Ping/Port endpoints, evaluates alert rules (status/threshold/anomaly), sends notifications via database/push/mail channels.

## STRUCTURE

```
uptizm/
├── lib/
│   ├── main.dart                    # Entry: Magic.init() → MagicApplication
│   ├── config/                      # 7 config factories (app, auth, network, social_auth, view, notifications, deeplink)
│   ├── routes/                      # app.dart (20+ auth-protected), auth.dart (4 guest routes)
│   └── app/
│       ├── controllers/             # 8 singletons (Magic.findOrPut), return Widgets
│       ├── models/                  # 15 Eloquent-style (Model + HasTimestamps + InteractsWithPersistence)
│       ├── enums/                   # 16 enums (value/label/fromValue/selectOptions)
│       ├── policies/                # 2 policies (extends Policy, Gate.define)
│       ├── providers/               # 3 providers + kernel.dart (middleware)
│       ├── middleware/              # auth, guest guards
│       ├── helpers/                 # json_path_resolver, theme_preference, locale_list
│       ├── validation/rules/        # min_numeric, max_numeric, between_numeric
│       ├── listeners/               # auth_restore_listener (locale sync)
│       └── streams/                 # (empty)
├── lib/resources/views/             # → see views/AGENTS.md
│   ├── layouts/                     # AppLayout (sidebar+topbar), GuestLayout
│   ├── components/                  # 15+ shared components, 6 subdirs
│   ├── monitors/                    # 7 views (index/create/edit/show/analytics/alerts)
│   ├── auth/                        # login, register, forgot/reset password
│   ├── dashboard/                   # dashboard_view.dart
│   ├── alerts/                      # alert rules + alerts history views
│   ├── settings/                    # profile, notification preferences
│   ├── teams/                       # create, settings, members
│   └── notifications/               # notifications list
├── test/                            # → see test/AGENTS.md
├── plugins/                         # → see plugins/AGENTS.md
│   ├── magic/                       # Core framework (symlink to fluttersdk_magic)
│   ├── magic_notifications/         # Database/push/mail notifications
│   ├── magic_social_auth/           # Google/Microsoft/GitHub OAuth
│   └── magic_deeplink/              # Universal Links / App Links
├── back-end/                        # SYMLINK → external Laravel repo
├── .claude/rules/                   # controllers.md, views.md, backend.md
├── .claude/docs/                    # alerting-system-architecture.md
├── brand.md                         # Design system, color palette, typography
└── CLAUDE.md                        # Primary coding conventions
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Add route | `lib/routes/app.dart` (protected) or `auth.dart` (guest) | Pair with Laravel `back-end/routes/api/v1.php` |
| Add model | `lib/app/models/` | Extend `Model` + `HasTimestamps` + `InteractsWithPersistence` |
| Add controller | `lib/app/controllers/` | Singleton via `Magic.findOrPut`, actions return Widgets |
| Add enum | `lib/app/enums/` | Must include `fromValue()` + `selectOptions` |
| Add policy | `lib/app/policies/` | `extends Policy`, register in `AppServiceProvider.boot()` |
| Add middleware | `lib/app/middleware/` | Register alias in `lib/app/providers/kernel.dart` |
| Add service provider | `lib/app/providers/` | Register factory in `lib/config/app.dart` providers list |
| Add validation rule | `lib/app/validation/rules/` | Extend `Rule`, for numeric validation (Min/Max check string length!) |
| Add view | `lib/resources/views/` | Wind UI only, dark mode required |
| Add component | `lib/resources/views/components/` | Check Wind widgets first before building custom |
| Add event listener | `lib/app/listeners/` | Register in `EventServiceProvider` |
| Backend API endpoint | `back-end/routes/api/v1.php` | Form Requests + API Resources + `$this->authorize()` |
| Backend controller | `back-end/app/Http/Controllers/Api/V1/` | Response: `{data, message}` |
| Backend model | `back-end/app/Models/` | ALWAYS eager load relationships |
| Design colors/typography | `brand.md` | Primary `#009E60`, dark mode surfaces, Inter font |
| Alerting architecture | `.claude/docs/alerting-system-architecture.md` | 3 alert types, state machine, anomaly detection |
| Wind widgets available | `plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/` | Check before building custom |

## CODE MAP

### Controllers (Singletons)

| Controller | State | Key Actions |
|------------|-------|-------------|
| `AuthController` | `MagicStateMixin<bool>` | login, register, forgotPassword, resetPassword, social login |
| `DashboardController` | `MagicStateMixin<Map>` | index → DashboardView, loadStats |
| `MonitorController` | 5+ `ValueNotifier` | index/create/show/edit/alerts, loadMonitors, store/update/destroy |
| `AlertController` | 3+ `ValueNotifier` | rulesIndex/rulesCreate/rulesEdit, alertsIndex, loadRules/loadAlerts |
| `AnalyticsController` | `ValueNotifier` | analytics view, fetchAnalytics |
| `TeamController` | `MagicStateMixin<Team>` | create/edit, membersPage, store/update, manageMembers |
| `ProfileController` | `MagicStateMixin` | profile settings, updateProfile, updatePassword |
| `NotificationController` | `ValueNotifier` | index/preferences, loadNotifications, updatePreferences |

### Models (Eloquent-Style)

| Model | Mixins | Key Relations/Computed |
|-------|--------|------------------------|
| `User` | +`Authenticatable` | `currentTeam`, `allTeams`, `locale`, `timezone` |
| `Team` | standard | `canEdit`, `canManageMembers`, role helpers |
| `Monitor` | standard | `isUp/isDown/isDegraded`, `authConfig`, enum conversions |
| `Alert` | standard | `isAlerting/isResolved`, `duration`, `triggerMessage` |
| `AlertRule` | standard | `isTeamLevel/isMonitorLevel`, severity/operator enums |
| `MonitorCheck` | standard | status, response time, metrics |
| `MonitorAuthConfig` | (value object) | `fromMap()/toMap()`, auth type handling |
| `AnalyticsResponse/Series/DataPoint` | (value objects) | Chart data structures |

### Service Provider Boot Order

1. `CacheServiceProvider` → 2. `RouteServiceProvider` (kernel + routes) → 3. `AppServiceProvider` (policies, user factory, deeplink) → 4. `LocalizationServiceProvider` → 5. `NetworkServiceProvider` → 6. `VaultServiceProvider` → 7. `AuthServiceProvider` → 8. `SocialAuthServiceProvider` → 9. `NotificationServiceProvider` → 10. `DeeplinkServiceProvider` → 11. `EventServiceProvider`

## CONVENTIONS

> Only deviations from standard Flutter/Dart listed. See CLAUDE.md for full rules.

- **Relative imports** within `lib/` — never `package:uptizm/` internally
- **Package imports** in `test/` — always `package:uptizm/` for app code
- **Wind UI only** in views — no `Container`, `Text`, `TextField`, `Row`/`Column`, `ElevatedButton`
- **Controllers are singletons** — `Magic.findOrPut(ControllerName.new)`, never instantiate directly
- **Models use `get<T>()` / `set()`** — NOT `getAttribute()`/`setAttribute()` (despite CLAUDE.md example, actual code uses `get/set`)
- **Enum fallbacks required** — `orElse: () => DefaultValue` in `fromValue()`
- **`(value as num?)?.toInt()`** — never `as int` for numeric API values
- **`response.data['data']`** — Laravel wraps responses in `{data: {...}}`
- **`response.successful`** — not `.success`
- **`Http.get(url, query:)`** — not `queryParams:`
- **`MagicRouter.instance.pathParameter('id')`** — not `MagicRoute.params['id']`
- **`extends Policy`** — not `extends MagicPolicy`
- **Outlined icons only** — `Icons.person_outline`, not `Icons.person`
- **Dark mode on everything** — `bg-white dark:bg-gray-800`, `text-gray-900 dark:text-white`
- **4px spacing grid** — common: 4, 8, 12, 16, 20, 24, 32, 48, 64px
- **`scrollPrimary: true`** — required with `overflow-y-auto` for iOS tap-to-top
- **`dart format .`** — not `flutter format` (auto-format hook runs after Edit/Write)

## ANTI-PATTERNS

| Forbidden | Why |
|-----------|-----|
| `as int` on API values | Crashes on `num` — use `(value as num?)?.toInt()` |
| Flutter Material widgets in views | Wind UI only — `WDiv`, `WText`, `WButton`, `WFormInput` |
| Package imports within `lib/` | Use relative imports — `import '../models/monitor.dart'` |
| `extends MagicPolicy` | Use `extends Policy` |
| `response.success` | API is `response.successful` |
| `Http.get(url, queryParams:)` | Parameter is `query:` |
| `Route.back()` | Use `MagicRoute.back()` |
| `MagicRoute.params['id']` | Use `MagicRouter.instance.pathParameter('id')` |
| `get<String>('name')` in CLAUDE.md model example | Actual codebase uses `get<String>('name')` — CLAUDE.md example with `getAttribute` is outdated |
| Building custom widgets before checking Wind | `searchable`, `onSearch`, `onCreateOption` already exist |
| Emoji in product UI | Brand guidelines prohibit |
| Hardcoded colors | Use `bg-primary`, `text-primary`, theme system |
| Touch targets < 44×44 | Accessibility requirement |
| Suppressing types (`as any` equivalent) | No `dynamic` shortcuts — use proper typing |

## COMMANDS

```bash
# Flutter
flutter test                              # All tests
flutter test --coverage                   # With coverage
flutter run -d chrome                     # Run web
dart format .                             # Format (NOT flutter format)
flutter analyze --no-fatal-infos          # Static analysis

# Laravel (backend)
cd back-end && php artisan test           # All backend tests
cd back-end && php artisan test --testsuite=Unit
cd back-end && php artisan test --testsuite=Feature

# Plugin CLI
dart run fluttersdk_magic_notifications:install
dart run fluttersdk_magic_notifications:status
dart run fluttersdk_magic_deeplink:install
```

## COMPLEXITY HOTSPOTS

| File | Lines | Risk |
|------|-------|------|
| `monitor_show_view.dart` | 891 | Timers, multiple notifiers, charts — refactoring candidate |
| `profile_settings_view.dart` | 543 | Dual forms (profile + password) |
| `team_members_view.dart` | 500 | Role-based access, invitations |
| `monitors_index_view.dart` | 479 | Filtering, search, pagination |
| `notification_dropdown.dart` | 464 | Popover, stream subscriptions |
| `alert_controller.dart` | 425 | Multiple notifiers, computed getters |
| `alert_rule_form.dart` | 411 | Dynamic fields by rule type |

## NOTES

- **Backend is a symlink** — `back-end/` → external Laravel repo. No PHP files in main project.
- **`.env` is a Flutter asset** — bundled with app, never put backend secrets in it.
- **Network base URL hardcoded** — `lib/config/network.dart` uses local IP, should use `env()`.
- **CI is Flutter-only** — `.github/workflows/ci.yml` runs analyze + format + test. No Laravel CI.
- **Test duplication** — `test/app/` and `test/unit/` both test enums/models/controllers. Consolidation needed.
- **No mocking library** — Tests use inline mocks, no Mockito/Mocktail.
- **TDD enforced** — RED → GREEN → REFACTOR. Enum/model tests comprehensive, view tests minimal.
- **Alerting docs in Turkish** — `.claude/docs/alerting-system-architecture.md` (906 lines, design complete).
