# TEST KNOWLEDGE BASE

## OVERVIEW

102 test files, ~1400 tests. TDD enforced (RED → GREEN → REFACTOR). No mocking library — inline mocks only.

## STRUCTURE

```
test/
├── app/                    # PRIMARY — mirrors lib/app/
│   ├── enums/              # 8 tests — fromValue(), selectOptions, all values
│   ├── models/             # 8 tests — fromMap(), getters, setters, fillable
│   ├── controllers/        # 6 tests — state notifiers, initial state, actions return Widget
│   └── helpers/            # 3 tests — json_path_resolver, theme_preference, locale
├── unit/                   # LEGACY — overlaps test/app/ (consolidation needed)
│   ├── enums/              # 6 tests
│   ├── models/             # 7 tests
│   └── controllers/        # 3 tests
├── widget/                 # Component/view widget tests
│   ├── components/
│   │   ├── alerts/         # 5 tests — alert_rule_form, severity badge, list items
│   │   ├── analytics/      # 3 tests — date range, metric selector, data table
│   │   └── charts/         # 3 tests — response_time, sparkline, multi_line
│   └── views/
│       ├── alerts/         # 2 tests — alert views
│       └── monitors/       # 2 tests — monitor views
├── resources/views/        # View rendering tests
│   ├── components/         # 7 tests — app_card, app_list, monitors/, navigation
│   ├── layouts/            # 2 tests — app_layout, guest_layout
│   ├── monitors/           # 4 tests — index, create, edit, show
│   ├── dashboard/          # 1 test
│   ├── settings/           # 2 tests — profile, notification prefs
│   ├── teams/              # 2 tests — create, members
│   └── notifications/      # 1 test
├── config/                 # 2 tests — social_auth_config, theme_init
└── integration/            # 1 test — notifications_integration
```

## WHERE TO LOOK

| Task | Location | Pattern |
|------|----------|---------|
| Test new enum | `test/app/enums/{name}_test.dart` | `fromValue()` + `selectOptions` + all values |
| Test new model | `test/app/models/{name}_test.dart` | `fromMap()` + typed getters + fillable |
| Test controller | `test/app/controllers/{name}_test.dart` | State notifiers + action return types |
| Test component | `test/widget/components/{feature}/` | `buildTestApp()` wrapper + `pumpWidget` |
| Test view | `test/resources/views/{feature}/` | Widget tree assertions |

## CONVENTIONS

- **Imports**: Always `package:uptizm/...` for app code (never relative)
- **Widget wrapper**: `buildTestApp(child: widget)` or `WindTheme(data: WindThemeData(), child: MaterialApp(home: Scaffold(body: widget)))`
- **Naming**: `{snake_case}_test.dart`, `group('ClassName')`, `test('descriptive behavior')`
- **Magic cleanup**: `setUp(() { Magic.flush(); })` / `tearDown(() { Magic.flush(); })`
- **No mocking library**: Inline mock classes with `StreamController`, `MagicResponse`
- **Data creation**: Inline maps, no fixtures directory

## TEST PATTERNS

```dart
// Enum test
test('fromValue returns correct type', () {
  expect(MonitorType.fromValue('http'), MonitorType.http);
});
test('selectOptions includes all types', () {
  expect(MonitorType.selectOptions.length, 3);
});

// Model test
test('fromMap creates model with correct attributes', () {
  final model = Monitor.fromMap({'id': 1, 'name': 'Test', 'type': 'http'});
  expect(model.name, 'Test');
  expect(model.type, MonitorType.http);
});

// Controller test
test('index returns correct view widget', () {
  final result = MonitorController.instance.index();
  expect(result, isA<MonitorsIndexView>());
});

// Widget test
testWidgets('renders component correctly', (tester) async {
  await tester.pumpWidget(buildTestApp(child: MyComponent(data: testData)));
  expect(find.text('Expected'), findsOneWidget);
});
```

## COVERAGE GAPS

| Layer | Coverage | Gap |
|-------|----------|-----|
| Enums | ~100% | — |
| Models | ~80% | Some computed properties |
| Controllers | ~50% | Async/API calls untested (no Http mocking) |
| Components | ~50% | Interactions minimal |
| Views | ~20% | Mostly instantiation checks |
| Integration | ~10% | Only notifications |

## ANTI-PATTERNS

- Relative imports for app code — always `package:uptizm/`
- Adding tests to `test/unit/` — use `test/app/` (unit/ is legacy)
- Skipping `fromValue()` + `selectOptions` tests for new enums
- Missing `Magic.flush()` in setUp/tearDown when using controllers
