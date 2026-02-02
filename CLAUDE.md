# Uptizm

Advanced uptime monitoring platform that parses response bodies to track deep health metrics. Built with Flutter (Magic Framework) + Laravel back-end.

## ⛔ Development Workflow: STRICT TDD

**This project follows Test-Driven Development. This is NON-NEGOTIABLE.**

### RED → GREEN → REFACTOR

1. **RED**: Write a failing test FIRST that describes the expected behavior
2. **GREEN**: Write the minimum code to make the test pass
3. **REFACTOR**: Clean up while keeping tests green

### Rules

- **No code without a test.** Every new feature, component, model, enum, controller action MUST have a test written BEFORE the implementation.
- **No fix without a test.** Every bug fix MUST first reproduce the bug as a failing test, THEN fix the code to make it pass. If the test for that case doesn't exist yet, write it first.
- **Run tests after every change.** `flutter test` (Flutter), `cd back-end && php artisan test` (Laravel). Never commit with failing tests.
- **Test the actual behavior, not the implementation.** Test what the user sees and what the API returns, not internal details.

### What to Test

| Layer | What | How |
|-------|------|-----|
| Enums | All values, `fromValue()`, `selectOptions` | Unit test |
| Models | `fromMap()`/`toMap()`, typed getters, computed props | Unit test |
| Components | Renders correctly, handles edge cases (null, empty, error) | Widget test |
| Controllers | Actions return correct widgets, state changes | Unit test |
| API responses | Null fields, wrapped data, error states | Widget test |
| Views | Key elements render, form validation works | Widget test |

### Example: Bug Fix TDD Flow

```
1. Bug: ResponsePreview crashes when status_code is null
2. Write test: expect widget renders "0" when status_code is null → RED (crashes)
3. Fix: change `as int` to `(as num?)?.toInt() ?? 0` → GREEN
4. Run full suite: `flutter test` → all pass
```

## Quick Reference

| Command | Description |
|---------|-------------|
| `flutter run -d chrome` | Run web app |
| `flutter run -d ios` | Run iOS app |
| `flutter run -d android` | Run Android app |
| `flutter test` | Run Flutter tests |
| `cd back-end && php artisan serve` | Run Laravel API |
| `cd back-end && php artisan test` | Run Laravel tests |

## Claude Code Skills (For LLMs)

**IMPORTANT:** When working on this codebase, activate the appropriate skills to access framework-specific guidance. Skills are located in `.claude/skills/`.

### Available Skills

| Skill | When to Activate | What It Provides |
|-------|------------------|------------------|
| **wind-ui** | Writing Flutter UI code with Wind widgets, composing className strings, debugging Wind parsing | Complete Wind widget API, utility class reference, state prefixes, responsive patterns, theme configuration |
| **magic-framework** | Using Magic facades, creating models/controllers, routing, auth, events, validation, service providers | 15 Facades reference, Eloquent ORM, IoC container, MVC patterns, event system, testing guidelines |
| **flutter-design** | Implementing Flutter themes, using ThemeData/ColorScheme, styling widgets, Material 3 patterns | ThemeData setup, color access patterns, typography scale, spacing system, BoxDecoration recipes, shadow scales |
| **mobile-app-design-mastery** | Designing mobile UI, making layout decisions, establishing visual hierarchy, touch targets | Mobile design workflow, spacing/touch scales, mobile typography, platform patterns, anti-patterns |

### Activation Guidelines

**ALWAYS activate when:**
- 🎨 Building/styling Flutter views → Activate `wind-ui` + `flutter-design` + `mobile-app-design-mastery`
- 🏗️ Creating controllers/models/routes → Activate `magic-framework`
- 📝 Writing forms with validation → Activate `wind-ui` (for WForm widgets) + `magic-framework` (for validation rules)
- 🔐 Implementing auth/authorization → Activate `magic-framework`
- 🌐 Making API calls → Activate `magic-framework` (Http facade)
- 🎭 Working with events/listeners → Activate `magic-framework`

**Example Scenarios:**

```
User: "Create a team invitation form"
→ Activate: wind-ui, magic-framework, flutter-design, mobile-app-design-mastery
Reason: Need WForm widgets, validation rules, styling guidance, mobile UX patterns

User: "Add caching to the API calls in TeamController"
→ Activate: magic-framework
Reason: Need Cache facade documentation

User: "Fix the dark mode colors in the sidebar"
→ Activate: wind-ui, flutter-design
Reason: Need dark: prefix patterns and theme color access

User: "Write tests for UserController"
→ Activate: magic-framework
Reason: Need testing setup and MagicApp.reset() patterns
```

### Skill Integration Notes

- **Wind UI + Magic Framework:** Wind widgets work seamlessly with Magic's `MagicFormData`, `rules()`, and `MagicView`
- **Flutter Design + Wind UI:** Use Wind's utility classes (`bg-primary`, `text-gray-700`) which map to theme colors
- **Mobile Design + Flutter Design:** Mobile Design provides theory; Flutter Design provides implementation syntax
- **All Four Together:** Most view development benefits from all skills activated simultaneously

## Tech Stack

- **Front-end**: Flutter 3.10+ (Dart), single codebase for web, iOS, Android
- **Framework**: Magic Framework (`fluttersdk_magic`) — Laravel-inspired Flutter framework
- **UI**: Wind UI (`fluttersdk_magic` Wind widgets) — Tailwind-like utility classes for Flutter
- **Back-end**: Laravel (PHP) in `back-end/` folder
- **Auth**: Magic Auth + Social Auth (`fluttersdk_magic_social_auth`)

## Internal Plugins (Symlinked)

Magic Framework and Wind UI are our own packages, linked locally via symlink during development. Source code is accessible and editable when needed (bug fixes, new features, API changes).

| Plugin | Path | Description |
|--------|------|-------------|
| Magic Framework | `plugins/fluttersdk_magic/` | Core framework (facades, routing, auth, ORM, providers) |
| Wind UI | `plugins/fluttersdk_magic/plugins/fluttersdk_wind/` | Tailwind-like widget system |
| Social Auth | `plugins/fluttersdk_magic_social_auth/` | OAuth/social login provider |

When a framework-level change is required (e.g., adding a new facade method, fixing a Wind parser bug, or extending a base class), modify the plugin source directly at these paths.

## Architecture

### Flutter (Front-end) — `lib/`

Laravel-inspired MVC architecture via Magic Framework:

```
lib/
├── main.dart                    # App entry, WindTheme, Magic.init()
├── config/                      # Config maps (app, auth, network, social_auth)
├── routes/                      # Route registration (auth.dart, app.dart)
├── app/
│   ├── controllers/             # Controllers with actions returning Widgets
│   ├── models/                  # Eloquent-style models (User, Team)
│   ├── enums/                   # Enums (TeamRole)
│   ├── middleware/               # Route middleware (auth, guest)
│   ├── listeners/               # Event listeners
│   ├── policies/                # Authorization policies
│   ├── providers/               # Service providers (app, event, route)
│   └── streams/                 # Reactive streams
└── resources/views/
    ├── auth/                    # Auth views (login, register, forgot/reset password)
    ├── layouts/                 # Layout widgets (AppLayout, GuestLayout)
    ├── components/              # Reusable UI components
    │   ├── navigation/          # Sidebar, header, nav items, team selector
    │   └── settings/            # Settings cards, form inputs, buttons
    └── teams/                   # Team views (create, settings)
```

### Laravel (Back-end) — `back-end/`

Standard Laravel structure. API routes in `back-end/routes/api/`.

## Conventions

### Magic Framework Patterns

- **Routes**: Register in `lib/routes/` using `MagicRoute.page()` and `MagicRoute.group()`
- **Controllers**: Singleton pattern with `.instance`, actions return Widgets
- **Models**: Extend `Model` with `HasTimestamps, InteractsWithPersistence`. Must define `table`, `resource`, `fillable`. See Model Checklist below.
- **Config**: Map factories in `lib/config/`, loaded via `Magic.init(configFactories:)`
- **Service Providers**: Register in `lib/config/app.dart` providers list
- **Middleware**: `auth` (EnsureAuthenticated), `guest` (RedirectIfAuthenticated)
- **Facades**: Use `Auth`, `Config`, `Route`, `Log`, `Http`, `Lang`, `Cache`, `Event`, `Gate`, `Crypt`, `Vault`, `Pick`, `Storage`, `DB`, `Schema`
- **UI Widgets**: ALWAYS use Wind UI widgets (`WDiv`, `WText`, `WInput`, `WButton`, `WIcon`, etc.) NEVER use Flutter widgets directly (`TextField`, `Container`, `Text`, `IconButton`, `SelectableText`, etc.). Wind UI provides utility-class styling and automatic theme integration.
- **Imports**: ALWAYS use relative imports (`../../`) for files within `lib/`, NOT `package:uptizm/`. Only use `package:` for external dependencies.

```dart
// ✅ CORRECT: Relative imports within lib/
import '../../../app/enums/monitor_type.dart';
import '../../../app/models/monitor.dart';
import '../../components/tag_input.dart';

// ❌ WRONG: Package imports within lib/
import 'package:uptizm/app/enums/monitor_type.dart';
import 'package:uptizm/app/models/monitor.dart';

// ✅ CORRECT: Package imports for external dependencies
import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
```

### Eloquent ORM (Primary Data Layer)

**Magic Framework has a powerful Eloquent ORM - use it as your PRIMARY data layer:**

#### Model Structure (REQUIRED fields)

Every model MUST have `table`, `resource`, and the `InteractsWithPersistence` mixin:

```dart
class Monitor extends Model with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'monitors';       // ← REQUIRED: DB table name

  @override
  String get resource => 'monitors';    // ← REQUIRED: API resource name

  @override
  List<String> get fillable => ['name', 'url', 'type']; // ← REQUIRED: mass-assignable fields

  // Typed getters use getAttribute() pattern
  String? get name => getAttribute('name') as String?;
  set name(String? value) => setAttribute('name', value);

  // Static helpers
  static Future<Monitor?> find(dynamic id) =>
      InteractsWithPersistence.findById<Monitor>(id, Monitor.new);

  static Monitor fromMap(Map<String, dynamic> map) {
    return Monitor()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }
}
```

**⚠️ Common Model Mistakes:**
- ❌ `String get endpoint => '/monitors'` — Does NOT exist. Use `table` + `resource`
- ❌ `class Monitor extends Model with HasTimestamps` — Missing `InteractsWithPersistence` → save()/delete() won't exist
- ❌ `get<String>('name')` — Use `getAttribute('name') as String?` for typed accessors

```dart
// ✅ GOOD: Eloquent for CRUD operations
final user = await User.find(1);
user?.name = 'Updated';
await user?.save();

final team = Team()..name = 'New Team';
await team.save();

await team.delete();

// ✅ GOOD: Type-safe accessors
print(user?.name);           // String?
print(user?.email);          // String?
print(user?.createdAt);      // Carbon?

// ✅ GOOD: Relationships from API
final currentTeam = User.current.currentTeam; // Team?
final allTeams = User.current.allTeams;       // List<Team>

// ❌ AVOID: Http for simple CRUD
await Http.put('/users/$id', data: {'name': 'Updated'}); // Use Eloquent instead
```

**When to use Http vs Eloquent:**
- Use **Eloquent** for: Single record CRUD, type-safe models, authenticated user
- Use **Http** for: Search/filters, pagination, custom endpoints, bulk operations

### Http Facade API (EXACT signatures)

```dart
// ✅ CORRECT parameter names
Http.get('/monitors', query: {'team_id': 1});          // query: NOT queryParams:
Http.post('/monitors', data: {'name': 'Test'});        // data: for body
Http.put('/monitors/1', data: {'name': 'Updated'});
Http.delete('/monitors/1');

// ❌ WRONG parameter names
Http.get('/monitors', queryParams: {'team_id': 1});    // queryParams does NOT exist
```

### MagicResponse API

```dart
final response = await Http.get('/monitors');

// ✅ CORRECT
response.successful    // bool — true if statusCode 200-299
response.statusCode    // int
response.data          // dynamic

// ❌ WRONG
response.success       // Does NOT exist — use .successful
response.message       // Check if exists before using
```

### Route Navigation API

```dart
// ✅ CORRECT — Always use MagicRoute prefix
MagicRoute.to('/monitors');
MagicRoute.back();
MagicRoute.page('/path', () => Widget());
MagicRoute.group(layout: ..., middleware: [...], routes: () { ... });

// ❌ WRONG — Route class alone does NOT have these methods
Route.to('/monitors');     // Error: 'to' isn't defined for type 'Route'
Route.back();              // Error: 'back' isn't defined for type 'Route'
```

### Policy Pattern

```dart
// ✅ CORRECT: Extend Policy, use Gate.define()
class MonitorPolicy extends Policy {
  @override
  void register() {
    Gate.define('view-monitor', _view);
    Gate.define('update-monitor', _update);
  }

  bool _view(Authenticatable user, dynamic arguments) {
    final monitor = arguments as Monitor?;
    return monitor != null;
  }
}

// ❌ WRONG: MagicPolicy does NOT exist
class MonitorPolicy extends MagicPolicy { ... }
```

### Validation Rules (Available in Magic)

```dart
// ✅ These rules EXIST
Required()
Email()
Min(3)        // min length or value
Max(255)      // max length or value
Between(1, 100)
Confirmed()
In(['admin', 'member'])

// ❌ These rules do NOT exist
Numeric()     // Use InputType.number on the WFormInput instead
Alpha()       // Not available
```

### Custom Validation Rules for Numeric Inputs

**IMPORTANT:** Built-in `Min()` and `Max()` rules check **string length** for form values, not numeric ranges. For numeric validation on `InputType.number` fields, create custom rules.

**Pattern to follow:**

1. Create custom rules in `lib/app/validation/rules/`:
   - `MinNumeric(min)` - validates numeric minimum
   - `MaxNumeric(max)` - validates numeric maximum
   - `BetweenNumeric(min, max)` - validates numeric range

2. Custom rule template:
```dart
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

class MinNumeric extends Rule {
  final num min;
  MinNumeric(this.min);

  @override
  bool passes(String attribute, dynamic value, Map<String, dynamic> data) {
    if (value == null) return true; // Let Required handle null
    final stringValue = value.toString();
    if (stringValue.isEmpty) return true; // Let Required handle empty
    final numValue = num.tryParse(stringValue);
    if (numValue == null) return false; // Not a valid number
    return numValue >= min;
  }

  @override
  String message() => 'validation.min_numeric';

  @override
  Map<String, dynamic> params() => {'min': min};
}
```

3. Add translation keys to `assets/lang/en.json`:
```json
"validation": {
  "min_numeric": "The :attribute must be at least :min.",
  "max_numeric": "The :attribute must not exceed :max.",
  "between_numeric": "The :attribute must be between :min and :max."
}
```

4. Add field attribute names:
```json
"attributes": {
  "check_interval": "Check Interval",
  "timeout": "Timeout"
}
```

5. Use in forms:
```dart
import '../../../app/validation/rules/between_numeric.dart';

WFormInput(
  controller: form['check_interval'],
  type: InputType.number,
  validator: rules([
    Required(),
    BetweenNumeric(10, 300),
  ], field: 'check_interval'),
)
```

**✅ CORRECT pattern:**
- Custom rule with numeric parsing
- Translation keys with `:attribute`, `:min`, `:max` params
- Attribute names in translation file
- Use `rules()` function with proper field name

**❌ WRONG patterns:**
- ❌ `Min(10)` on numeric input — checks string length (2 chars) not value (10)
- ❌ Custom inline validator function — bypasses translation system
- ❌ Hardcoded error messages — not translatable

### Wind UI Patterns

**CRITICAL: ALWAYS use Wind UI widgets, NEVER use Flutter widgets directly in views**

- Use Wind widgets: `WDiv`, `WText`, `WButton`, `WInput`, `WSelect`, `WCheckbox`, `WImage`, `WSvg`, `WIcon`, `WPopover`, `WFormInput`, `WFormSelect`, `WFormCheckbox`, `WAnchor`
- Compose `className` strings with Tailwind-like utility classes
- Responsive prefixes: `sm:`, `md:`, `lg:`, `xl:`, `2xl:`
- Dark mode: `dark:` prefix
- States: `hover:`, `focus:`, `disabled:`, `error:`, `checked:`
- Theme toggle: `context.windTheme.toggleTheme()`

**Widget Replacements (NEVER use Flutter widgets):**
```dart
// ❌ WRONG: Flutter widgets
TextField(...)                              // Use WInput
SelectableText(...)                         // Use WText(selectable: true)
IconButton(icon: Icon(...), ...)            // Use WButton(child: WIcon(...))
Container(...)                              // Use WDiv
Text(...)                                   // Use WText
ElevatedButton(...)                         // Use WButton
Row(...), Column(...)                       // Use WDiv with flex classes

// ✅ CORRECT: Wind UI widgets
WInput(
  value: text,
  onChanged: (v) => setState(() => text = v),
  type: InputType.multiline,  // For multiline textarea
  className: 'px-3 py-2 rounded-lg border ...',
)

WText(
  'Selectable text content',
  selectable: true,  // For selectable text
  className: 'font-mono text-sm text-gray-900',
)

WButton(
  onTap: () => doAction(),
  className: 'p-2 rounded-lg hover:bg-gray-100',
  child: WIcon(Icons.close, className: 'text-gray-600'),
)

WDiv(
  className: 'flex flex-row gap-2 items-center',  // Replaces Row
  children: [...],
)
```

#### WForm Widgets (Form Validation)

Wind UI provides three form-integrated widgets that wrap base widgets with Flutter's `FormField`:

**WFormInput** - Text input with validation
```dart
WFormInput(
  label: 'Email',                    // Optional label above input
  hint: 'We never share your email', // Optional help text below
  controller: emailController,
  type: InputType.email,
  validator: rules([Required(), Email()], field: 'email'),
  autovalidateMode: AutovalidateMode.onUserInteraction, // Real-time validation
  labelClassName: 'text-sm font-medium text-gray-700 dark:text-gray-300',
  className: '''
    border rounded-lg px-3 py-2
    focus:border-primary focus:ring-2 focus:ring-primary/20
    error:border-red-500  // Auto-activates on validation failure
  ''',
  errorClassName: 'text-red-500 text-xs mt-1',
  showError: true, // Show error message below (default: true)
  prefix: Icon(Icons.email), // Optional leading icon
  suffix: Icon(Icons.check),  // Optional trailing icon
)
```

**WFormSelect** - Single select with validation
```dart
WFormSelect<String>(
  label: 'Country',
  hint: 'Select your country',
  value: selectedCountry,
  options: [
    SelectOption(value: 'us', label: 'United States'),
    SelectOption(value: 'uk', label: 'United Kingdom'),
  ],
  onChange: (v) => setState(() => selectedCountry = v),
  validator: (v) => v == null ? 'Country is required' : null,
  searchable: true, // Enable search for large lists
  onSearch: (query) async => await fetchCountries(query), // Async search
  className: 'border rounded-lg error:border-red-500',
  menuClassName: 'bg-white dark:bg-gray-800 rounded-xl shadow-xl',
)
```

**WFormMultiSelect** - Multi-select with validation
```dart
WFormMultiSelect<String>(
  label: 'Tags',
  values: selectedTags, // List<String>
  options: tagOptions,
  onMultiChange: (values) => setState(() => selectedTags = values),
  validator: (values) {
    if (values == null || values.isEmpty) return 'Select at least one tag';
    if (values.length > 5) return 'Maximum 5 tags allowed';
    return null;
  },
  searchable: true,
  className: 'border rounded-lg error:border-red-500',
)
```

**WFormCheckbox** - Checkbox with validation
```dart
WFormCheckbox(
  value: agreedToTerms,
  onChanged: (v) => setState(() => agreedToTerms = v),
  labelText: 'I agree to Terms of Service',
  hint: 'You must accept to continue',
  validator: (v) => v != true ? 'You must accept terms' : null,
  className: 'w-5 h-5 rounded border checked:bg-primary error:border-red-500',
)
```

**Key Features:**
- **Automatic error state:** `error:` prefixed classes auto-activate on validation failure
- **Label/hint/error display:** Built-in, no manual `Column` wrapping needed
- **FormFieldState sync:** Works with `Form.validate()`, `Form.save()`, `Form.reset()`
- **Priority:** Error message takes priority over hint text
- **Magic integration:** Use with `MagicFormData` and `rules()` validator

**Form Usage Pattern:**
```dart
final form = MagicFormData({'email': '', 'role': 'member'});

MagicForm(
  formData: form,
  child: Column(
    children: [
      WFormInput(
        controller: form['email'],
        validator: rules([Required(), Email()], field: 'email'),
      ),
      WFormSelect<String>(
        value: 'member',
        options: TeamRole.selectOptions,
        onChange: (v) => form.set('role', v),
      ),
      WButton(
        onTap: () {
          if (form.validate()) {
            // All fields valid
            submitForm(form.get('email'), form.get('role'));
          }
        },
        child: WText('Submit'),
      ),
    ],
  ),
)
```

### Naming

- Files: `snake_case.dart`
- Classes: `PascalCase`
- Variables/functions: `camelCase`
- Routes: kebab-case paths (`/status-pages`, `/teams/settings`)
- Views folder mirrors route structure

## Design Guidelines

See `brand.md` for full brand identity. Quick reference:

- **Primary**: `#009E60` (Shamrock Green)
- **Font**: Inter (display/body), monospace for technical values
- **Style**: Clean, minimal, data-focused. Cards with rounded-2xl, soft shadow, 1px border
- **Dark mode**: Always implement both light (`bg-background-light`) and dark (`dark:bg-background-dark`) variants
- **Icons**: Material Symbols Outlined
- **Status**: Green dot = Up, Red = Down, Amber = Degraded
- **Responsive**: Desktop sidebar (240px) → Tablet collapsed sidebar (64px) → Mobile bottom nav

### WindTheme Colors (defined in main.dart)

Primary color with full scale (50-900) is configured in `WindThemeData`. Use `bg-primary`, `text-primary`, `bg-primary/10` etc.

## Testing

- **Flutter**: `flutter test` — widget tests and unit tests
- **Laravel**: `cd back-end && php artisan test` — PHPUnit/Pest

## Gotchas

- Wind UI className strings are parsed at runtime — typos won't cause compile errors but will silently fail
- Magic Framework uses Laravel naming conventions in Dart (e.g., `fillable`, `casts`, service providers)
- `.env` is bundled as a Flutter asset — don't put secrets meant only for back-end there
- Route transitions default to platform defaults; use `.transition(RouteTransition.none)` for instant navigation
- Back-end API routes are in `back-end/routes/api/v1.php`, not the root `api.php`
- **MagicResponse uses `.successful` NOT `.success`** — this is a frequent mistake
- **Http.get uses `query:` NOT `queryParams:`** — named parameter differs from typical conventions
- **Navigation is `MagicRoute.to()` NOT `Route.to()`** — the `Route` class exists but doesn't have navigation methods
- **Policies extend `Policy` NOT `MagicPolicy`** — MagicPolicy doesn't exist
- **Models need `InteractsWithPersistence` mixin** for save/delete to work — without it, only read operations work
- **Models need `table` + `resource` getters** — `endpoint` is NOT a valid override
- **`Numeric()` validation rule doesn't exist** — use `InputType.number` on the input widget for numeric input
- **Route params** — Use `MagicRouter.instance.pathParameter('id')` to get `:id` from route `/monitors/:id`. Do NOT use `ModalRoute.of(context)?.settings.name` — it returns empty string
- **`dart format` NOT `flutter format`** — `flutter format` was removed, use `dart format` instead

## Common Mistakes (Learn from These!)

### ❌ Don't Build Custom Components Before Checking Framework
**Mistake**: Built a custom 280-line searchable select component with overlay, debouncing, and async search.
**Lesson**: Wind UI's `WSelect` already has `searchable: true` and `onSearch` callback for async API calls. ALWAYS check `plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/` before building custom UI components.
**Action**: Search framework widgets first: `ls plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/` or read widget source files.

### ❌ Don't Use Config for API URLs
**Mistake**: Used `Config.get('app.apiUrl')` which doesn't exist, resulting in `http://localhost:8000/api/v1null/timezones`.
**Lesson**: Http facade uses `network.drivers.api.base_url` from `lib/config/network.dart` automatically. Use **relative paths** only.
**Correct**: `Http.get('/timezones')` → Auto-prepends `http://localhost:8000/api/v1`
**Wrong**: `Http.get('${Config.get('app.apiUrl')}/timezones')` → null concatenation error
**Action**: Check how other controllers use Http facade: `grep -r "Http.get" lib/app/controllers/` to see the pattern.

### ❌ Don't Use Http for Simple CRUD
**Mistake**: Used `Http.put('/users/$id', data: {...})` for simple user update instead of Eloquent.
**Lesson**: Magic Framework has powerful Eloquent ORM. Use it as PRIMARY data layer for single-record CRUD operations.
**Correct**:
```dart
final user = await User.find(userId);
user?.name = 'Updated';
await user?.save();
```
**Wrong**:
```dart
await Http.put('/users/$userId', data: {'name': 'Updated'});
```
**Benefits**: Type-safe accessors, automatic serialization, cleaner code, less error handling
**Action**: Use Eloquent for CRUD, Http for search/filters/pagination: `grep -r "User.find\|Team.find" lib/app/controllers/`

### ✅ Before Building Any Component
1. Check Wind UI widgets: `ls plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/`
2. Read widget source if it exists: Check for props like `searchable`, `onSearch`, `onLoadMore`
3. Check existing app components: `ls lib/resources/views/components/`
4. Only build custom if framework + app don't have it

### ✅ Before Using Http Facade
1. Use relative paths: `/endpoint` not `${baseUrl}/endpoint`
2. Check pattern in other controllers: `grep "Http.get\|Http.post" lib/app/controllers/`
3. Base URL is in `lib/config/network.dart` under `network.drivers.api.base_url`

### ❌ Don't Build Custom Form Inputs
**Mistake**: Created custom validated input widgets with manual error display and state management.
**Lesson**: Wind UI's `WFormInput`, `WFormSelect`, `WFormCheckbox` provide built-in validation, label, hint, and error display.
**Correct**: Use WForm widgets for all form fields requiring validation
**Wrong**: Building custom StatefulWidget with TextFormField and manual error Text widgets
**Action**: Check WForm widgets: `ls plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/w_form*`

### ✅ Before Building Form Fields
1. Check if WForm widgets cover your use case: WFormInput, WFormSelect, WFormMultiSelect, WFormCheckbox
2. Use `label`, `hint`, `errorClassName` props instead of manual Column wrapping
3. Use `error:` prefix in className for automatic error styling
4. Use `autovalidateMode` for real-time validation feedback
5. For search/async: WFormSelect has `searchable: true` and `onSearch` callback
6. Pattern: `grep "WFormInput\|WFormSelect\|WFormCheckbox" lib/resources/views/` to see existing usage

### ❌ Don't Guess Framework API Names — VERIFY FIRST
**Mistake**: Used `response.success`, `Http.get(url, queryParams:)`, `Route.back()`, `MagicRoute.params`, `MagicPolicy`, `Numeric()` — none of which exist.
**Lesson**: Magic Framework has its own API surface that differs from what you'd expect from Laravel or standard Dart. Names are close but not identical.
**Action**: Before using ANY facade method or property, verify it exists:
```bash
# Check Http facade signature
grep -A 5 "static Future.*get\b" plugins/fluttersdk_magic/lib/src/facades/http.dart
# Check MagicResponse properties
grep "get " plugins/fluttersdk_magic/lib/src/network/magic_response.dart
# Check Policy base class
grep "class.*Policy" lib/app/policies/team_policy.dart
# Check available validation rules
ls plugins/fluttersdk_magic/lib/src/validation/rules/
# Check route navigation methods
grep "static.*void\|static.*Future" plugins/fluttersdk_magic/lib/src/routing/
```

**Quick reference of CORRECT names:**
| Wrong (guessed) | Correct (actual) |
|------------------|------------------|
| `response.success` | `response.successful` |
| `Http.get(url, queryParams:)` | `Http.get(url, query:)` |
| `Route.back()` | `MagicRoute.back()` |
| `Route.to('/path')` | `MagicRoute.to('/path')` |
| `MagicRoute.params['id']` | `MagicRouter.instance.pathParameter('id')` |
| `extends MagicPolicy` | `extends Policy` |
| `Numeric()` validator | Use `InputType.number` on the input widget |
| `String get endpoint` | `String get table` + `String get resource` |

### ❌ Don't Forget Model Mixins
**Mistake**: Created `class Monitor extends Model with HasTimestamps` without `InteractsWithPersistence`, causing `save()` and `delete()` to not exist.
**Lesson**: `InteractsWithPersistence` provides the Eloquent CRUD methods. Without it, the model is read-only.
**Correct**:
```dart
class Monitor extends Model with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'monitors';
  @override
  String get resource => 'monitors';
  @override
  List<String> get fillable => ['name', 'url'];
}
```
**Wrong**:
```dart
class Monitor extends Model with HasTimestamps {
  @override
  String get endpoint => '/monitors'; // endpoint doesn't exist
}
```
**Action**: Always copy structure from an existing model: `cat lib/app/models/team.dart | head -30`

### ✅ Before Creating a New Model
1. Copy the structure from `lib/app/models/team.dart` or `lib/app/models/user.dart`
2. Ensure these mixins: `HasTimestamps, InteractsWithPersistence`
3. Ensure these overrides: `table`, `resource`, `fillable`
4. Use `getAttribute('field')` pattern for typed getters (not `get<T>('field')`)
5. Add `fromMap()` static factory with `setRawAttributes(map, sync: true)`
6. Add `find()` static using `InteractsWithPersistence.findById<T>(id, T.new)`

### ✅ Before Creating a New Policy
1. Copy structure from `lib/app/policies/team_policy.dart`
2. Extend `Policy` (NOT `MagicPolicy`)
3. Override `register()` method
4. Use `Gate.define('ability-name', _handler)` to register abilities
5. Handler signature: `bool _handler(Authenticatable user, dynamic arguments)`
6. Register in `lib/app/providers/app_service_provider.dart`: `MonitorPolicy().register()`

### ✅ Before Using Any Facade
1. **NEVER guess method/property names** — always verify in plugin source
2. Check the facade file: `cat plugins/fluttersdk_magic/lib/src/facades/<name>.dart`
3. Check the response class: `cat plugins/fluttersdk_magic/lib/src/network/magic_response.dart`
4. Look at existing usage in controllers: `grep -r "Http\.\|MagicRoute\.\|Gate\." lib/app/controllers/`
5. When in doubt, read the source — it's in `plugins/fluttersdk_magic/lib/src/`

### ❌ Don't Use `as int` on API Response Fields
**Mistake**: `response['status_code'] as int` crashes when value is null.
**Lesson**: API responses may have null or missing fields. Always use null-safe casting.
**Correct**: `(response['status_code'] as num?)?.toInt() ?? 0`
**Wrong**: `response['status_code'] as int` — throws `TypeError: null: type 'Null' is not a subtype of type 'int'`

### ❌ Don't Forget to Unwrap `response.data`
**Mistake**: Used `response.data` directly, but Laravel wraps responses in `{"data": {...}}`.
**Lesson**: `response.data` returns the full JSON body. If Laravel returns `{"data": {"status_code": 200}}`, the actual payload is at `response.data['data']`.
**Correct**:
```dart
final payload = response.data is Map && response.data.containsKey('data')
    ? response.data['data']
    : response.data;
```
**Wrong**: `_testFetchResponse = response.data;` — gives `{"data": {...}}` instead of the inner object

### ❌ Don't Build Custom Tag Input Components
**Mistake**: Built a 123-line custom `TagInput` widget using Flutter's `Chip` + `TextField`.
**Lesson**: `WFormMultiSelect` with `onCreateOption` + `searchable: true` already provides tag-like input with create-on-type, chip display, and remove.
**Correct**:
```dart
WFormMultiSelect<String>(
  values: _tags,
  options: _tagOptions,
  onMultiChange: (tags) => setState(() => _tags = tags),
  searchable: true,
  onCreateOption: (query) async {
    final opt = SelectOption(value: query, label: query);
    setState(() => _tagOptions.add(opt)); // MUST persist in state
    return opt;
  },
)
```
**Critical**: `onCreateOption` must add the new option to a state list. If `options` is always `[]`, the created option disappears immediately.

### ❌ Don't Use Inconsistent Input Heights
**Mistake**: WSelect used `py-2` while WInput used `py-3` in the same row, causing visual misalignment.
**Lesson**: All inputs and selects in the same context must use identical padding.
**Standard**: `px-3 py-3 rounded-lg text-sm` for all form inputs and selects throughout the app.

### ❌ Don't Use Wind `overflow-auto` / `overflow-scroll` for Scrollable Areas
**Mistake**: Used `max-h-[300px] overflow-auto` on a WDiv expecting CSS-like overflow scroll behavior.
**Lesson**: Wind's `overflow-auto` does NOT produce a scrollable container in Flutter. Flutter scroll requires dedicated scroll widgets. Wind layout classes (`w-full`, `max-h-*`) work for sizing, but scroll behavior must use Flutter widgets.
**Correct**:
```dart
WDiv(
  className: 'p-4 rounded-lg w-full bg-gray-900 max-h-[300px]',
  child: SingleChildScrollView(  // ← Flutter scroll widget
    child: WText(content, className: 'font-mono text-xs text-white'),
  ),
)
```
**Wrong**:
```dart
WDiv(
  className: 'p-4 rounded-lg w-full bg-gray-900 max-h-[300px] overflow-auto', // ← Won't scroll
  child: WText(content),
)
```
**Rule**: For any scrollable content, always use `SingleChildScrollView`, `ListView`, or `CustomScrollView`. Never rely on Wind overflow classes for scroll behavior.

### ✅ Before Processing API Responses
1. Check if response wraps data in `{"data": {...}}` — Laravel standard
2. Use `(value as num?)?.toInt() ?? default` for numeric fields, never `as int`
3. Use `value as String?` for optional string fields
4. Test with actual API response shape before assuming field names
