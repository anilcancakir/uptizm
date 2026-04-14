---
path: "test/**/*.dart"
---

# Testing (TDD with Magic Framework)

## TDD Flow

1. **Red**: Write a failing test that describes the expected behavior
2. **Green**: Write minimum code to make it pass
3. **Refactor**: Clean up while keeping tests green

Every feature branch starts with a test file. No implementation without a failing test first.

## Test File Naming

- Unit tests: `test/unit/{domain}/{class}_test.dart`
- Widget tests: `test/widget/{domain}/{widget}_test.dart` or `test/resources/views/{path}_test.dart`
- Integration: `test/integration/{flow}_test.dart`

## Test Setup

Always use shared `test/test_setup.dart`:

```dart
import '../test_setup.dart';

void main() {
  setUpAll(() async {
    await initMagicForTests();
  });
  // tests...
}
```

`initMagicForTests()` registers: CacheServiceProvider, LocalizationServiceProvider, AuthServiceProvider.
It does NOT register NetworkServiceProvider or DatabaseServiceProvider (causes Timer pending).

## Controller Testing Pattern

```dart
void main() {
  setUpAll(() async {
    await initMagicForTests();
  });

  group('MonitorController', () {
    setUp(() {
      // Reset controller state between tests
      MonitorController.instance.selectedMonitorNotifier.value = null;
    });

    test('initial state has null selected monitor', () {
      expect(MonitorController.instance.selectedMonitorNotifier.value, isNull);
    });
  });
}
```

## Widget Testing Pattern

```dart
Future<void> pumpWithSize(WidgetTester tester, Widget widget) async {
  tester.view.physicalSize = const Size(1440, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(() {
    tester.view.resetPhysicalSize();
    tester.view.resetDevicePixelRatio();
  });

  // Suppress WDiv overflow errors
  final origOnError = FlutterError.onError;
  FlutterError.onError = (details) {
    if (details.toString().contains('overflowed')) return;
    origOnError?.call(details);
  };
  addTearDown(() => FlutterError.onError = origOnError);

  await tester.pumpWidget(widget);
}
```

- Wrap widget in `WindTheme(data: WindThemeData(), child: MaterialApp(home: Scaffold(body: widget)))`
- Views with async onInit (HTTP calls): use `tester.runAsync()` or set data after network call fails
- Verify Wind UI widgets via `find.byType(WDiv)` / `find.byType(WText)`
- Test user interactions: `tester.tap()`, `tester.enterText()`, `tester.pump()`
- trans() with LocalizationServiceProvider active resolves keys to English translations

## What to Test

- Controllers: ValueNotifier state transitions via MagicStateMixin
- Models: `fromMap()` factory with real API response shapes, typed accessor correctness
- Views: renders expected widgets, handles empty/loading/error states, user interactions trigger controller methods
- Enums: label, value, serialization
- Middleware: auth check redirects correctly
