# Tests for Safe Numeric Parsing & Boolean Metric Chips

## TL;DR

> **Quick Summary**: Add tests covering the `_toDouble`/`_toInt` safe parsing helpers across 6 test files, plus a new test harness for boolean/status metric chip rendering in `monitor_show_view_test.dart`.
> 
> **Deliverables**:
> - String-value parsing tests added to 5 existing unit test files
> - String-value widget test added to `response_preview_test.dart`
> - Boolean/status chip rendering test harness + tests added to `monitor_show_view_test.dart`
> - Full test suite + linter verification passing
> 
> **Estimated Effort**: Short (~45 min)
> **Parallel Execution**: YES - 3 waves
> **Critical Path**: Wave 1 (unit tests) → Wave 2 (widget tests) → Wave 3 (verification)

---

## Context

### Original Request
Add tests for ALL changes made in the safe numeric parsing PR. Two categories of changes:
1. `_toDouble`/`_toInt` helpers added to 7 files to handle Laravel API returning numeric values as Strings
2. Boolean/status metric chip rendering in `monitor_show_view.dart`

### Interview Summary
**Key Discussions**:
- Add to existing test files where possible, new test files only if needed
- Run existing tests + linter to verify nothing is broken
- Follow existing test patterns (fromMap with raw maps, WindTheme wrapper for widgets)

### Metis Review
**Identified Gaps** (addressed):
- `AnalyticsSummary` is a separate class from `AnalyticsResponse` — test `AnalyticsSummary.fromMap` directly since that's where `_toInt`/`_toDouble` live
- `status_page_edit_view`'s test harness reimplements populate logic without using the real `_toInt` — skip this file (not testable through harness)
- `consecutiveChecks` in `alert_rule.dart` uses inline safe parsing (not `_toDouble`), also needs testing
- Boolean truthy/falsy edge cases are complex — test `'1'`, `'0'`, `'true'`, `'false'`, `''`, `null`, `true`, `false`, `'running'` (arbitrary strings)
- `ResponsePreview` is a widget test (different pattern than unit tests)

---

## Work Objectives

### Core Objective
Ensure all safe numeric parsing helpers (`_toDouble`, `_toInt`, inline variants) are tested with String inputs matching real Laravel API responses, and that the new boolean/status metric chip rendering is verified.

### Concrete Deliverables
- Modified: `test/unit/models/alert_rule_test.dart`
- Modified: `test/unit/models/alert_test.dart`
- Modified: `test/unit/models/monitor_metric_value_test.dart`
- Modified: `test/unit/models/analytics_response_test.dart`
- Modified: `test/app/models/paginated_checks_test.dart`
- Modified: `test/resources/views/components/response_preview_test.dart`
- Modified: `test/resources/views/monitors/monitor_show_view_test.dart`

### Definition of Done
- [ ] All 7 test files pass individually
- [ ] `flutter test` full suite passes with 0 failures
- [ ] `dart analyze` reports no issues

### Must Have
- String value tests for every field using `_toDouble` or `_toInt`
- Tests for `null` and empty string edge cases
- Boolean/status chip tests covering truthy AND falsy values
- Green chip assertion for truthy, red chip assertion for falsy
- All existing tests still passing (zero regressions)

### Must NOT Have (Guardrails)
- DO NOT modify any source files — only test files
- DO NOT refactor `_toDouble`/`_toInt` into shared utility (test-only scope)
- DO NOT add tests for fields not affected by `_toDouble`/`_toInt` changes
- DO NOT assert on exact Wind `className` strings in chip tests — assert on rendered text content and widget tree structure
- DO NOT create new test for `status_page_edit_view` — its harness doesn't exercise the real `_toInt` (documented skip)
- DO NOT test `_toDouble` with exotic inputs like `"NaN"`, `"Infinity"` — not realistic API values
- DO NOT expand scope to refactoring, integration tests, or dark mode styling

---

## Verification Strategy (MANDATORY)

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks in this plan MUST be verifiable WITHOUT any human action.

### Test Decision
- **Infrastructure exists**: YES
- **Automated tests**: Tests-after (we're adding tests for already-implemented changes)
- **Framework**: flutter_test (built-in)

### Agent-Executed QA Scenarios (MANDATORY — ALL tasks)

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| Unit test files | Bash (`flutter test <file>`) | Run test file, assert 0 failures |
| Widget test files | Bash (`flutter test <file>`) | Run test file, assert 0 failures |
| Full suite | Bash (`flutter test`) | Run all tests, assert 0 failures |
| Linter | Bash (`dart analyze`) | Run analyzer, assert no issues |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately — all independent unit tests):
├── Task 1: alert_rule_test.dart (String _toDouble + inline _toInt)
├── Task 2: alert_test.dart (String _toDouble)
├── Task 3: monitor_metric_value_test.dart (String _toDouble)
├── Task 4: analytics_response_test.dart (AnalyticsSummary String parsing)
└── Task 5: paginated_checks_test.dart (String _toInt)

Wave 2 (Start after Wave 1 — widget tests, may share patterns):
├── Task 6: response_preview_test.dart (widget test with String values)
└── Task 7: monitor_show_view_test.dart (boolean/status chip harness)

Wave 3 (After all tests written):
└── Task 8: Full verification (flutter test + dart analyze)
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | 8 | 2, 3, 4, 5 |
| 2 | None | 8 | 1, 3, 4, 5 |
| 3 | None | 8 | 1, 2, 4, 5 |
| 4 | None | 8 | 1, 2, 3, 5 |
| 5 | None | 8 | 1, 2, 3, 4 |
| 6 | None | 8 | 7 |
| 7 | None | 8 | 6 |
| 8 | 1-7 | None | None (final gate) |

### Agent Dispatch Summary

| Wave | Tasks | Recommended Agents |
|------|-------|-------------------|
| 1 | 1-5 | `category="quick"` — simple additions to existing files |
| 2 | 6, 7 | `category="quick"` — widget tests need WindTheme harness |
| 3 | 8 | `category="quick"` — run commands only |

---

## TODOs

- [ ] 1. Add String value parsing tests to `alert_rule_test.dart`

  **What to do**:
  - Add a new `group('handles String values from API')` inside the existing `group('fromMap')` block
  - Test `thresholdValue`, `thresholdMin`, `thresholdMax` with String values (e.g., `'5000.0000'`)
  - Test `consecutiveChecks` with String value (e.g., `'3'`)
  - Test null and empty string edge cases for both `_toDouble` and `consecutiveChecks`

  **Test cases**:
  ```
  group('handles String values from API', () {
    test('parses threshold values from String', () {
      map with threshold_value: '5000.0000', threshold_min: '100.50', threshold_max: '500.99'
      → expect thresholdValue == 5000.0
      → expect thresholdMin == 100.50
      → expect thresholdMax == 500.99
    });

    test('parses consecutiveChecks from String', () {
      map with consecutive_checks: '3'
      → expect consecutiveChecks == 3
    });

    test('handles null threshold values', () {
      map with threshold_value: null
      → expect thresholdValue == null
    });

    test('handles empty String threshold values', () {
      map with threshold_value: ''
      → expect thresholdValue == null
    });

    test('handles empty String consecutiveChecks defaults to 1', () {
      map with consecutive_checks: ''
      → expect consecutiveChecks == 1
    });
  });
  ```

  **Must NOT do**:
  - Don't modify existing test cases
  - Don't test fields not using `_toDouble`/`_toInt` (e.g., `name`, `type`, `enabled`)

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Simple test additions to an existing file, clear pattern to follow
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Model `fromMap` pattern knowledge

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 2, 3, 4, 5)
  - **Blocks**: Task 8
  - **Blocked By**: None

  **References**:

  **Pattern References** (existing tests to follow):
  - `test/unit/models/alert_rule_test.dart:40-70` — Existing `fromMap` test with numeric values: `threshold_value: 5000.0`. Mirror this pattern but use String values.
  - `test/unit/models/alert_rule_test.dart:101-116` — Range test with `threshold_min: 100.0, threshold_max: 500.0`. Same pattern, String values.
  - `test/unit/models/alert_rule_test.dart:139-143` — Default `consecutiveChecks` test. Add String variant.

  **Source References** (what was changed):
  - `lib/app/models/alert_rule.dart:55-57` — `thresholdValue`, `thresholdMin`, `thresholdMax` using `_toDouble()`
  - `lib/app/models/alert_rule.dart:59-64` — `_toDouble` helper implementation
  - `lib/app/models/alert_rule.dart:72-78` — `consecutiveChecks` inline safe parsing

  **Acceptance Criteria**:

  - [ ] New `group('handles String values from API')` added to `fromMap` group
  - [ ] 5 test cases covering String threshold values, String consecutiveChecks, null, empty string
  - [ ] `flutter test test/unit/models/alert_rule_test.dart` → PASS (all tests, 0 failures)

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All alert_rule tests pass including new String tests
    Tool: Bash
    Preconditions: Test file modified with new group
    Steps:
      1. flutter test test/unit/models/alert_rule_test.dart
      2. Assert: exit code 0
      3. Assert: output contains "All tests passed" or "0 failures"
      4. Assert: test count increased from baseline
    Expected Result: All existing + new tests pass
    Evidence: Command output captured
  ```

  **Commit**: NO (groups with Task 8)

---

- [ ] 2. Add String value parsing tests to `alert_test.dart`

  **What to do**:
  - Add a new `group('handles String values from API')` inside the existing `group('fromMap')` block
  - Test `triggerValue` with String value (e.g., `'6500.50'`)
  - Test null case
  - Test empty string case

  **Test cases**:
  ```
  group('handles String values from API', () {
    test('parses triggerValue from String', () {
      map with trigger_value: '6500.50'
      → expect triggerValue == 6500.50
    });

    test('handles null triggerValue', () {
      map with trigger_value: null
      → expect triggerValue == null
    });

    test('handles empty String triggerValue', () {
      map with trigger_value: ''
      → expect triggerValue == null
    });
  });
  ```

  **Must NOT do**:
  - Don't modify existing tests
  - Don't test `alertRuleId`, `status`, or other fields not using `_toDouble`

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: 3 simple test additions
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Model pattern knowledge

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 3, 4, 5)
  - **Blocks**: Task 8
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `test/unit/models/alert_test.dart:18-40` — Existing `fromMap` test with `trigger_value: 6500.0`. Mirror with String `'6500.50'`.

  **Source References**:
  - `lib/app/models/alert.dart:44` — `triggerValue` using `_toDouble()`
  - `lib/app/models/alert.dart:46-51` — `_toDouble` helper implementation

  **Acceptance Criteria**:

  - [ ] New `group('handles String values from API')` added
  - [ ] 3 test cases covering String, null, empty string for `triggerValue`
  - [ ] `flutter test test/unit/models/alert_test.dart` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All alert tests pass including new String tests
    Tool: Bash
    Preconditions: Test file modified
    Steps:
      1. flutter test test/unit/models/alert_test.dart
      2. Assert: exit code 0
      3. Assert: output contains "All tests passed"
    Expected Result: All tests pass
    Evidence: Command output captured
  ```

  **Commit**: NO (groups with Task 8)

---

- [ ] 3. Add String value parsing tests to `monitor_metric_value_test.dart`

  **What to do**:
  - Add a new `group('handles String numeric_value from API')` after the existing `'handles integer numeric_value from API'` test
  - Test `numericValue` with String value (e.g., `'150.5'`)
  - Test empty string
  - Test null (already implicitly covered in first test but good to have explicit)

  **Test cases**:
  ```
  group('handles String numeric_value from API', () {
    test('parses String numeric_value', () {
      map with numeric_value: '150.5'
      → expect numericValue == 150.5
    });

    test('parses String integer as double', () {
      map with numeric_value: '75'
      → expect numericValue == 75.0
    });

    test('handles empty String numeric_value', () {
      map with numeric_value: ''
      → expect numericValue == null
    });
  });
  ```

  **Must NOT do**:
  - Don't modify existing tests
  - Don't test `stringValue`, `statusValue`, or other non-`_toDouble` fields

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Simple test additions
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Model pattern knowledge

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 2, 4, 5)
  - **Blocks**: Task 8
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `test/unit/models/monitor_metric_value_test.dart:135-151` — Existing `'handles integer numeric_value from API'` test. Follow this exact map structure, replace `numeric_value: 75` with `numeric_value: '75'`.

  **Source References**:
  - `lib/app/models/monitor_metric_value.dart:35` — `numericValue: _toDouble(map['numeric_value'])`
  - `lib/app/models/monitor_metric_value.dart:51-56` — `_toDouble` helper

  **Acceptance Criteria**:

  - [ ] New `group('handles String numeric_value from API')` added
  - [ ] 3 test cases covering String double, String integer, empty string
  - [ ] `flutter test test/unit/models/monitor_metric_value_test.dart` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All monitor_metric_value tests pass
    Tool: Bash
    Steps:
      1. flutter test test/unit/models/monitor_metric_value_test.dart
      2. Assert: exit code 0
    Expected Result: All tests pass
    Evidence: Command output captured
  ```

  **Commit**: NO (groups with Task 8)

---

- [ ] 4. Add String value parsing tests to `analytics_response_test.dart`

  **What to do**:
  - Add a new `group('AnalyticsSummary handles String values from API')` at the top level (sibling to the existing `group('AnalyticsResponse')`)
  - Test `AnalyticsSummary.fromMap` DIRECTLY — this is where `_toInt` and `_toDouble` live
  - Test `totalChecks` with String (e.g., `'100'`), `uptimePercent` with String (e.g., `'99.5'`), `avgResponseTime` with String (e.g., `'200.5'`)
  - Test the `"0"` edge case (should be `0`, not null-fallback `0`)
  - Test empty strings and nulls

  **Test cases**:
  ```
  group('AnalyticsSummary handles String values from API', () {
    test('parses all summary fields from Strings', () {
      AnalyticsSummary.fromMap({
        'total_checks': '100',
        'uptime_percent': '99.5',
        'avg_response_time': '200.5',
      })
      → expect totalChecks == 100
      → expect uptimePercent == 99.5
      → expect avgResponseTime == 200.5
    });

    test('handles null values with defaults', () {
      AnalyticsSummary.fromMap({
        'total_checks': null,
        'uptime_percent': null,
        'avg_response_time': null,
      })
      → expect totalChecks == 0
      → expect uptimePercent == 0.0
      → expect avgResponseTime == 0.0
    });

    test('handles empty String values with defaults', () {
      AnalyticsSummary.fromMap({
        'total_checks': '',
        'uptime_percent': '',
        'avg_response_time': '',
      })
      → expect totalChecks == 0
      → expect uptimePercent == 0.0
      → expect avgResponseTime == 0.0
    });

    test('handles String "0" correctly', () {
      AnalyticsSummary.fromMap({
        'total_checks': '0',
        'uptime_percent': '0.0',
        'avg_response_time': '0',
      })
      → expect totalChecks == 0
      → expect uptimePercent == 0.0
      → expect avgResponseTime == 0.0
    });
  });
  ```

  **Also**: Add a test to the existing `group('AnalyticsResponse')` that passes String values through `AnalyticsResponse.fromMap` to verify the full parse chain:
  ```
  test('fromMap handles String summary values from API', () {
    map with summary: { 'total_checks': '100', 'uptime_percent': '99.5', 'avg_response_time': '200.5' }
    → expect response.summary.totalChecks == 100
    → expect response.summary.uptimePercent == 99.5
    → expect response.summary.avgResponseTime == 200.5
  });
  ```

  **Must NOT do**:
  - Don't test `monitorId`, `dateFrom`, `dateTo` as Strings — they don't use `_toDouble`/`_toInt`
  - Don't modify existing tests

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Unit test additions, straightforward
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Model pattern knowledge

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 2, 3, 5)
  - **Blocks**: Task 8
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `test/unit/models/analytics_response_test.dart:8-45` — Existing `fromMap` test with numeric summary values. Mirror with String values.

  **Source References**:
  - `lib/app/models/analytics_response.dart:4-44` — `AnalyticsSummary` class with `_toInt` and `_toDouble`
  - `lib/app/models/analytics_response.dart:15-21` — `AnalyticsSummary.fromMap` using `_toInt(map['total_checks']) ?? 0`, `_toDouble(map['uptime_percent']) ?? 0.0`

  **Acceptance Criteria**:

  - [ ] New `group('AnalyticsSummary handles String values from API')` added
  - [ ] Also added String summary test inside existing `AnalyticsResponse` group
  - [ ] 5 total test cases: String parsing, null defaults, empty string defaults, "0" edge case, full chain
  - [ ] `flutter test test/unit/models/analytics_response_test.dart` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All analytics_response tests pass
    Tool: Bash
    Steps:
      1. flutter test test/unit/models/analytics_response_test.dart
      2. Assert: exit code 0
    Expected Result: All tests pass
    Evidence: Command output captured
  ```

  **Commit**: NO (groups with Task 8)

---

- [ ] 5. Add String value parsing tests to `paginated_checks_test.dart`

  **What to do**:
  - Add a new `group('handles String meta values from API')` after the existing tests
  - Test `fromResponse` with String values in `meta`: `current_page: '2'`, `last_page: '5'`, `per_page: '10'`, `total: '50'`
  - Test empty string meta values (should use defaults)

  **Test cases**:
  ```
  group('handles String meta values from API', () {
    test('parses pagination meta from Strings', () {
      response with meta: { current_page: '2', last_page: '5', per_page: '10', total: '50' }
      → expect currentPage == 2
      → expect lastPage == 5
      → expect perPage == 10
      → expect total == 50
    });

    test('handles empty String meta values with defaults', () {
      response with meta: { current_page: '', last_page: '', per_page: '', total: '' }
      → expect currentPage == 1
      → expect lastPage == 1
      → expect perPage == 15
      → expect total == 0
    });
  });
  ```

  **Must NOT do**:
  - Don't modify existing tests
  - Don't test the `checks` data array parsing

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: 2 simple test additions
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Model pattern knowledge

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 2, 3, 4)
  - **Blocks**: Task 8
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `test/app/models/paginated_checks_test.dart:7-41` — Existing `fromResponse` test with int meta values. Copy the exact response structure, replace ints with Strings.
  - `test/app/models/paginated_checks_test.dart:91-103` — Default handling test (missing meta). Follow this for empty string behavior.

  **Source References**:
  - `lib/app/models/paginated_checks.dart:21-26` — `_toInt` helper
  - `lib/app/models/paginated_checks.dart:40-46` — Usage: `_toInt(meta['current_page']) ?? 1`

  **Acceptance Criteria**:

  - [ ] New `group('handles String meta values from API')` added
  - [ ] 2 test cases covering String parsing and empty string defaults
  - [ ] `flutter test test/app/models/paginated_checks_test.dart` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All paginated_checks tests pass
    Tool: Bash
    Steps:
      1. flutter test test/app/models/paginated_checks_test.dart
      2. Assert: exit code 0
    Expected Result: All tests pass
    Evidence: Command output captured
  ```

  **Commit**: NO (groups with Task 8)

---

- [ ] 6. Add String value widget test to `response_preview_test.dart`

  **What to do**:
  - Add a new `testWidgets('handles String status_code and response_time_ms from API')` inside the existing `group('ResponsePreview')`
  - Pass `status_code: '200'` and `response_time_ms: '145'` as Strings instead of ints
  - Assert the widget renders `'200'` and `'145ms'` correctly (same as existing int-based test)
  - Also test with invalid String values: `status_code: 'invalid'` → should render `'0'`

  **Test cases**:
  ```
  testWidgets('handles String status_code and response_time_ms from API', (tester) async {
    build ResponsePreview with response: {
      'status_code': '200',
      'response_time_ms': '145',
      'content_type': 'application/json',
      'body': '{"success": true}',
    }
    → expect find.text('200'), findsOneWidget
    → expect find.text('145ms'), findsOneWidget
  });

  testWidgets('handles invalid String status_code gracefully', (tester) async {
    build ResponsePreview with response: {
      'status_code': 'invalid',
      'response_time_ms': 'abc',
      'content_type': 'application/json',
      'body': '{"ok": true}',
    }
    → expect find.text('0'), findsOneWidget (because _toInt('invalid') == null → ?? 0)
    → expect find.text('0ms'), findsOneWidget
  });
  ```

  **Must NOT do**:
  - Don't modify existing widget tests
  - Don't test `content_type` or `body` as non-String types (not affected by changes)

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Simple widget test additions, pattern exists already
  - **Skills**: [`wind-ui`]
    - `wind-ui`: WindTheme test wrapper pattern

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Task 7)
  - **Blocks**: Task 8
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `test/resources/views/components/response_preview_test.dart:7-12` — `wrapWithTheme` helper: `WindTheme(data: WindThemeData(), child: MaterialApp(home: Scaffold(body: child)))`
  - `test/resources/views/components/response_preview_test.dart:35-53` — Existing test with `status_code: 200, response_time_ms: 145`. Copy exactly, replace ints with Strings.
  - `test/resources/views/components/response_preview_test.dart:115-134` — Existing null handling test. Pattern for graceful degradation.

  **Source References**:
  - `lib/resources/views/components/response_preview.dart:68-69` — `_toInt(response!['status_code']) ?? 0` and `_toInt(response!['response_time_ms']) ?? 0`
  - `lib/resources/views/components/response_preview.dart:204-209` — `_toInt` helper

  **Acceptance Criteria**:

  - [ ] 2 new `testWidgets` added to existing group
  - [ ] String values render identically to int values
  - [ ] Invalid strings fall back to `0`
  - [ ] `flutter test test/resources/views/components/response_preview_test.dart` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All response_preview tests pass including String tests
    Tool: Bash
    Steps:
      1. flutter test test/resources/views/components/response_preview_test.dart
      2. Assert: exit code 0
    Expected Result: All tests pass
    Evidence: Command output captured
  ```

  **Commit**: NO (groups with Task 8)

---

- [ ] 7. Add boolean/status metric chip rendering tests to `monitor_show_view_test.dart`

  **What to do**:
  - Add a new `group('Boolean/status metric chip rendering')` at the bottom of the existing test file
  - Create a focused test harness widget that replicates the `_buildMetricRow` logic from `monitor_show_view.dart` lines 684-729. The harness takes a `MonitorCheck` with `parsedMetrics` and a list of metric `mappings`, and renders the chip grid.
  - **Why a harness**: `_buildMetricRow` is a private instance method on a view with heavy controller dependencies. Extracting the chip rendering logic into a test harness is cleaner than standing up the full `MonitorShowView`.
  - Test the RENDERING output: green indicator for truthy, red indicator for falsy, correct label text

  **Harness design**:
  ```dart
  /// Test harness that replicates the metric chip rendering logic from MonitorShowView._buildMetricRow
  class MetricChipTestHarness extends StatelessWidget {
    final Map<String, dynamic>? parsedMetrics;
    final List<Map<String, dynamic>> mappings;

    const MetricChipTestHarness({
      super.key,
      required this.parsedMetrics,
      required this.mappings,
    });

    @override
    Widget build(BuildContext context) {
      // Replicate the exact logic from monitor_show_view.dart L684-765
      // For each mapping, determine if isStatusType, compute boolValue/displayValue,
      // render appropriate chip (green/red for status, normal for numeric)
    }
  }
  ```

  **CRITICAL**: The harness must replicate the EXACT logic from `monitor_show_view.dart:691-729`:
  - `isStatusType = metricType == 'status' || rawValue is bool || rawValue == 'true' || rawValue == 'false'`
  - `boolValue = rawValue == true || rawValue == 'true' || rawValue == '1' || (rawValue is String && rawValue.isNotEmpty && rawValue != 'false' && rawValue != '0')`
  - Green chip: `bg-green-50` with green dot and green label text
  - Red chip: `bg-red-50` with red dot and red label text

  **Test cases** (assert on WIDGET TREE, not classNames):
  ```
  group('Boolean/status metric chip rendering', () {
    // Helper to pump widget with WindTheme wrapper

    testWidgets('renders green chip for truthy boolean (true)', (tester) async {
      mappings: [{ 'label': 'Healthy', 'path': 'is_healthy', 'type': 'status', 'unit': '' }]
      parsedMetrics: { 'is_healthy': true }
      → expect find.text('Healthy'), findsOneWidget
      → verify green indicator present (find green dot Container by color or find text with green semantics)
    });

    testWidgets('renders red chip for falsy boolean (false)', (tester) async {
      parsedMetrics: { 'is_healthy': false }
      → expect find.text('Healthy'), findsOneWidget
      → verify red indicator present
    });

    testWidgets('renders green chip for String "true"', (tester) async {
      mappings: [{ 'label': 'DB Connected', 'path': 'db', 'type': 'numeric', 'unit': '' }]
      parsedMetrics: { 'db': 'true' }
      → isStatusType becomes true (rawValue == 'true')
      → expect green chip with 'DB Connected' label
    });

    testWidgets('renders red chip for String "false"', (tester) async {
      parsedMetrics: { 'db': 'false' }
      → isStatusType becomes true (rawValue == 'false')
      → expect red chip with 'DB Connected' label
    });

    testWidgets('renders green chip for String "1"', (tester) async {
      mappings: [{ 'label': 'Active', 'path': 'active', 'type': 'status', 'unit': '' }]
      parsedMetrics: { 'active': '1' }
      → expect green chip
    });

    testWidgets('renders red chip for String "0"', (tester) async {
      parsedMetrics: { 'active': '0' }
      → expect red chip
    });

    testWidgets('renders red chip for empty String', (tester) async {
      parsedMetrics: { 'active': '' }
      → expect red chip (empty string is falsy)
    });

    testWidgets('renders red chip for null value', (tester) async {
      parsedMetrics: { 'active': null }
      → expect red chip (null is falsy, but also note: isStatusType check needs metricType == 'status' for null rawValue)
    });

    testWidgets('renders green chip for arbitrary truthy String', (tester) async {
      mappings: [{ 'label': 'Service', 'path': 'svc', 'type': 'status', 'unit': '' }]
      parsedMetrics: { 'svc': 'running' }
      → 'running' is a non-empty string that isn't 'false' or '0' → truthy
      → expect green chip with 'Service' label
    });

    testWidgets('renders numeric chip (not status) for numeric value with non-status type', (tester) async {
      mappings: [{ 'label': 'CPU', 'path': 'cpu', 'type': 'numeric', 'unit': '%' }]
      parsedMetrics: { 'cpu': 75 }
      → isStatusType is false (type != 'status', rawValue is not bool/String 'true'/'false')
      → expect normal chip with 'CPU' and '75 %' text
    });
  });
  ```

  **Assertion strategy for green/red chips**:
  Since we can't easily assert on Wind `className` strings in tests, use these approaches:
  1. Find the label text (`find.text('Healthy')`) and verify it appears in the widget tree — confirms chip rendered
  2. Use `find.byWidgetPredicate` to find WDiv widgets containing specific text patterns
  3. Count the number of label occurrences to ensure it's in the status chip section (1 instance = chip rendered)
  4. For green vs red distinction: The harness can use `Key` attributes on the outer WDiv chip, keyed by `'metric-$path-$boolValue'` to allow precise assertions. OR: Simply verify the widget tree has the label text and a small colored dot (2x2 WDiv) — finding both means the chip rendered.

  **Must NOT do**:
  - Don't render the full `MonitorShowView` — too many controller dependencies
  - Don't assert on exact `className` values (fragile)
  - Don't test dark mode styling
  - Don't add tests for non-status numeric chips beyond the one regression guard test

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Widget test with harness, clear pattern from existing test file
  - **Skills**: [`wind-ui`, `magic-framework`]
    - `wind-ui`: WindTheme test wrapper, WDiv/WText widget patterns
    - `magic-framework`: MonitorCheck.fromMap pattern

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Task 6)
  - **Blocks**: Task 8
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `test/resources/views/monitors/monitor_show_view_test.dart:69-147` — Existing widget test with WindTheme wrapper and ValueListenableBuilder. Use same `MaterialApp(home: WindTheme(...))` pattern.
  - `test/resources/views/monitors/monitor_show_view_test.dart:232-249` — `MonitorCheck.fromMap` with `parsedMetrics`. Use this exact structure for test data.
  - `test/resources/views/status_pages/status_page_edit_view_test.dart:186-307` — Existing test harness pattern. Shows how to build a focused harness widget for complex views.

  **Source References** (THE EXACT LOGIC TO REPLICATE):
  - `lib/resources/views/monitors/monitor_show_view.dart:684-729` — `_buildMetricRow` method with status chip logic. Lines 691-696 define `isStatusType`. Lines 701-708 define `boolValue`. Lines 710-728 render green/red chip.
  - `lib/resources/views/monitors/monitor_show_view.dart:730-765` — Non-status chip rendering (for the regression guard test).

  **Acceptance Criteria**:

  - [ ] `MetricChipTestHarness` widget created in the test file
  - [ ] New `group('Boolean/status metric chip rendering')` added
  - [ ] ~10 test cases covering: `true`, `false`, `'true'`, `'false'`, `'1'`, `'0'`, `''`, `null`, `'running'`, numeric non-status regression
  - [ ] Green chips verified for truthy values
  - [ ] Red chips verified for falsy values
  - [ ] Numeric chip verified for non-status metric
  - [ ] `flutter test test/resources/views/monitors/monitor_show_view_test.dart` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All monitor_show_view tests pass including boolean chip tests
    Tool: Bash
    Steps:
      1. flutter test test/resources/views/monitors/monitor_show_view_test.dart
      2. Assert: exit code 0
      3. Assert: output shows all tests passed
    Expected Result: All existing + new tests pass
    Evidence: Command output captured
  ```

  **Commit**: NO (groups with Task 8)

---

- [ ] 8. Full verification: run all tests + linter

  **What to do**:
  - Run `flutter test` to verify ALL tests pass (zero regressions)
  - Run `dart analyze` to verify zero linter issues
  - If any test fails, diagnose and fix before declaring done
  - Commit all changes with a descriptive message

  **Must NOT do**:
  - Don't modify source files to fix tests — only fix test files
  - Don't skip failing tests

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Run two commands, verify output
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Project test/lint command conventions

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 3 (final gate)
  - **Blocks**: None (final task)
  - **Blocked By**: Tasks 1-7

  **References**:

  **Documentation References**:
  - `AGENTS.md:Commands section` — `flutter test` for all tests, `dart analyze` for lint (NOT `dart format` — format is NOT required for this PR)

  **Acceptance Criteria**:

  - [ ] `flutter test` → exit code 0, "All tests passed" or equivalent
  - [ ] `dart analyze` → exit code 0, "No issues found"
  - [ ] All 7 modified test files listed in git diff

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Full test suite passes with zero regressions
    Tool: Bash
    Steps:
      1. flutter test
      2. Assert: exit code 0
      3. Assert: output contains "All tests passed" or "0 failures"
      4. Assert: test count >= previous count + new tests added
    Expected Result: Zero failures across all test files
    Evidence: Full command output captured

  Scenario: Linter reports zero issues
    Tool: Bash
    Steps:
      1. dart analyze
      2. Assert: exit code 0
      3. Assert: output contains "No issues found"
    Expected Result: Clean analysis
    Evidence: Command output captured
  ```

  **Commit**: YES
  - Message: `test: add coverage for safe numeric parsing helpers and boolean metric chips`
  - Files: All 7 modified test files
  - Pre-commit: `flutter test && dart analyze`

---

## Commit Strategy

| After Task | Message | Files | Verification |
|------------|---------|-------|--------------|
| 8 (all tasks) | `test: add coverage for safe numeric parsing helpers and boolean metric chips` | 7 test files | `flutter test && dart analyze` |

---

## Documented Skips

### `status_page_edit_view_test.dart` — INTENTIONALLY SKIPPED
**Reason**: The test harness (`StatusPageEditViewTestHarness`) reimplements the populate logic at `test/resources/views/status_pages/status_page_edit_view_test.dart:222-244`. It does `m.id ?? m.get('id')?.toString()` — NOT the real view's `_toInt()` helper. Adding String values to the test data would only test the harness code, not the actual `_toInt` in the real view. The `_toInt` pattern is identical to the one tested in Tasks 4-6, so coverage is effectively achieved through those tests.

---

## Success Criteria

### Verification Commands
```bash
# Individual file checks (each must pass)
flutter test test/unit/models/alert_rule_test.dart
flutter test test/unit/models/alert_test.dart
flutter test test/unit/models/monitor_metric_value_test.dart
flutter test test/unit/models/analytics_response_test.dart
flutter test test/app/models/paginated_checks_test.dart
flutter test test/resources/views/components/response_preview_test.dart
flutter test test/resources/views/monitors/monitor_show_view_test.dart

# Full suite
flutter test          # Expected: All tests passed, 0 failures

# Linter
dart analyze          # Expected: No issues found
```

### Final Checklist
- [ ] All "Must Have" tests present
- [ ] All "Must NOT Have" guardrails respected
- [ ] Zero source file modifications
- [ ] All 7 test files pass individually
- [ ] Full test suite passes
- [ ] Linter clean
- [ ] `status_page_edit_view` skip documented
