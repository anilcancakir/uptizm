# Wind UI Keyboard Actions Integration Plan

**Status:** ✅ COMPLETED
**Priority:** High (iOS UX improvement)
**Methodology:** TDD (Red → Green → Refactor)

## Problem Statement

iOS numeric keyboard lacks a "Done" button, making it impossible for users to dismiss the keyboard without tapping outside the input area. This creates poor UX for forms with numeric inputs (check_interval, timeout, expected_status_code, etc.).

## Solution Overview

Integrate `keyboard_actions` package into Wind UI plugin to provide automatic keyboard toolbar with Done button for all input types, especially numeric.

## Architecture Decision

**Approach:** Add `KeyboardActionsWrapper` widget that can wrap any form content, plus optional `focusNode` parameter to `WFormInput` for integration.

**Why this approach:**
1. Non-breaking change - existing WFormInput API unchanged
2. Flexible - wrapper can be used at form level or individual field level
3. Consistent with keyboard_actions package design patterns
4. Testable - wrapper and focusNode handling can be unit tested independently

## Implementation Phases

### Phase 1: Package Installation & Configuration

**Acceptance Criteria:**
- [ ] `keyboard_actions` package added to Wind UI plugin pubspec.yaml
- [ ] Package exports available from fluttersdk_wind barrel file
- [ ] No breaking changes to existing imports

**Files to modify:**
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/pubspec.yaml`
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/fluttersdk_wind.dart`

### Phase 2: WFormInput FocusNode Enhancement

**Acceptance Criteria:**
- [ ] WFormInput accepts optional `focusNode` parameter
- [ ] FocusNode passed through to underlying WInput
- [ ] Existing behavior unchanged when focusNode not provided
- [ ] Unit test: focusNode is correctly passed through chain

**Files to modify:**
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/w_form_input.dart`

**Test file:**
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/test/widgets/w_form_input_focus_test.dart`

### Phase 3: WKeyboardActions Wrapper Widget

**Acceptance Criteria:**
- [ ] New `WKeyboardActions` widget wraps child content
- [ ] Accepts list of FocusNodes for keyboard action configuration
- [ ] Configurable platform targeting (iOS only, Android only, all)
- [ ] Configurable toolbar color via className
- [ ] nextFocus navigation between fields works
- [ ] Done button dismisses keyboard
- [ ] Unit tests for all configurations

**New file:**
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/w_keyboard_actions.dart`

**Test file:**
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/test/widgets/w_keyboard_actions_test.dart`

**Widget API:**
```dart
class WKeyboardActions extends StatelessWidget {
  /// Child widget (usually a form or column of inputs)
  final Widget child;

  /// FocusNodes for inputs that need keyboard actions
  final List<FocusNode> focusNodes;

  /// Platform targeting: 'ios', 'android', 'all' (default)
  final String platform;

  /// Enable up/down navigation between fields
  final bool nextFocus;

  /// Toolbar background className (Wind utility classes)
  final String? toolbarClassName;

  /// Custom close widget builder
  final Widget Function(FocusNode)? closeWidgetBuilder;

  const WKeyboardActions({
    super.key,
    required this.child,
    required this.focusNodes,
    this.platform = 'all',
    this.nextFocus = true,
    this.toolbarClassName,
    this.closeWidgetBuilder,
  });
}
```

### Phase 4: Usage Documentation & Examples

**Acceptance Criteria:**
- [ ] CHANGELOG.md updated with new feature
- [ ] Example usage in plugin example app
- [ ] Integration guide in README

**Files to modify:**
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/CHANGELOG.md`
- `plugins/fluttersdk_magic/plugins/fluttersdk_wind/example/lib/main.dart`

### Phase 5: Uptizm App Integration

**Acceptance Criteria:**
- [ ] Monitor create/edit forms use WKeyboardActions
- [ ] Numeric inputs (check_interval, timeout) have Done button on iOS
- [ ] Existing form validation still works
- [ ] Widget test verifies keyboard actions integration

**Files to modify:**
- `lib/resources/views/monitors/monitor_create_view.dart`
- `lib/resources/views/monitors/monitor_edit_view.dart`
- `lib/resources/views/components/monitors/monitor_settings_section.dart`

---

## TDD Test Plan

### Phase 2 Tests (WFormInput FocusNode)

```dart
// test/widgets/w_form_input_focus_test.dart

group('WFormInput FocusNode', () {
  testWidgets('passes focusNode to underlying input', (tester) async {
    final focusNode = FocusNode();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: WFormInput(
            focusNode: focusNode,
            label: 'Test',
          ),
        ),
      ),
    );

    // Find TextField and verify it has the focusNode
    final textField = tester.widget<TextField>(find.byType(TextField));
    expect(textField.focusNode, equals(focusNode));
  });

  testWidgets('creates internal focusNode when not provided', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: WFormInput(label: 'Test'),
        ),
      ),
    );

    final textField = tester.widget<TextField>(find.byType(TextField));
    expect(textField.focusNode, isNotNull);
  });
});
```

### Phase 3 Tests (WKeyboardActions)

```dart
// test/widgets/w_keyboard_actions_test.dart

group('WKeyboardActions', () {
  testWidgets('wraps child with KeyboardActions widget', (tester) async {
    final focusNode = FocusNode();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: WKeyboardActions(
            focusNodes: [focusNode],
            child: TextField(focusNode: focusNode),
          ),
        ),
      ),
    );

    expect(find.byType(KeyboardActions), findsOneWidget);
    expect(find.byType(TextField), findsOneWidget);
  });

  testWidgets('configures platform targeting correctly', (tester) async {
    final focusNode = FocusNode();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: WKeyboardActions(
            focusNodes: [focusNode],
            platform: 'ios',
            child: TextField(focusNode: focusNode),
          ),
        ),
      ),
    );

    final keyboardActions = tester.widget<KeyboardActions>(
      find.byType(KeyboardActions),
    );
    expect(
      keyboardActions.config.keyboardActionsPlatform,
      equals(KeyboardActionsPlatform.IOS),
    );
  });

  testWidgets('enables nextFocus navigation by default', (tester) async {
    final node1 = FocusNode();
    final node2 = FocusNode();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: WKeyboardActions(
            focusNodes: [node1, node2],
            child: Column(
              children: [
                TextField(focusNode: node1),
                TextField(focusNode: node2),
              ],
            ),
          ),
        ),
      ),
    );

    final keyboardActions = tester.widget<KeyboardActions>(
      find.byType(KeyboardActions),
    );
    expect(keyboardActions.config.nextFocus, isTrue);
  });

  testWidgets('applies toolbar color from className', (tester) async {
    final focusNode = FocusNode();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: WKeyboardActions(
            focusNodes: [focusNode],
            toolbarClassName: 'bg-gray-100',
            child: TextField(focusNode: focusNode),
          ),
        ),
      ),
    );

    final keyboardActions = tester.widget<KeyboardActions>(
      find.byType(KeyboardActions),
    );
    // Verify toolbar color is parsed from Wind className
    expect(keyboardActions.config.keyboardBarColor, isNotNull);
  });
});
```

---

## Usage Example (After Implementation)

```dart
class MonitorForm extends StatefulWidget {
  @override
  State<MonitorForm> createState() => _MonitorFormState();
}

class _MonitorFormState extends State<MonitorForm> {
  final _nameFocus = FocusNode();
  final _urlFocus = FocusNode();
  final _intervalFocus = FocusNode();
  final _timeoutFocus = FocusNode();

  @override
  void dispose() {
    _nameFocus.dispose();
    _urlFocus.dispose();
    _intervalFocus.dispose();
    _timeoutFocus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return WKeyboardActions(
      focusNodes: [_nameFocus, _urlFocus, _intervalFocus, _timeoutFocus],
      toolbarClassName: 'bg-gray-100 dark:bg-gray-800',
      child: MagicForm(
        formData: form,
        child: WDiv(
          className: 'flex flex-col gap-4',
          children: [
            WFormInput(
              focusNode: _nameFocus,
              label: 'Name',
              controller: form['name'],
            ),
            WFormInput(
              focusNode: _urlFocus,
              label: 'URL',
              controller: form['url'],
              type: InputType.email, // URL keyboard
            ),
            WFormInput(
              focusNode: _intervalFocus,
              label: 'Check Interval',
              controller: form['check_interval'],
              type: InputType.number, // Numeric keyboard + Done button!
            ),
            WFormInput(
              focusNode: _timeoutFocus,
              label: 'Timeout',
              controller: form['timeout'],
              type: InputType.number,
            ),
          ],
        ),
      ),
    );
  }
}
```

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Breaking existing forms | Non-breaking API - wrapper is opt-in |
| Package compatibility | keyboard_actions has no dependencies, Flutter SDK only |
| Dark mode support | toolbarClassName accepts Wind dark: prefix |
| Test environment | Mock KeyboardActions in tests if needed |

## Timeline Estimate

- Phase 1: Package setup (~5 min)
- Phase 2: FocusNode enhancement + tests (~15 min)
- Phase 3: WKeyboardActions widget + tests (~30 min)
- Phase 4: Documentation (~10 min)
- Phase 5: Uptizm integration (~20 min)

**Total: ~1.5 hours**

---

## Sources

- [keyboard_actions pub.dev](https://pub.dev/packages/keyboard_actions)
- [Flutter iOS numeric keyboard issue #82694](https://github.com/flutter/flutter/issues/82694)
- [Adding Done Button to iOS Numpad](https://dinkomarinac.dev/adding-a-done-button-to-ios-numpad-in-flutter)
