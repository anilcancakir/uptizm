# i18n Audit & Fix: Broken trans() Keys + Hardcoded Strings + Scanner Skill

## TL;DR

> **Quick Summary**: Fix all broken `trans()` keys referencing non-existent `en.json` entries, convert ~45 hardcoded user-facing strings across 8 files to `trans()` with proper `en.json` keys, then create a reusable `/scan-translations` skill to automate detection in the future.
> 
> **Deliverables**:
> - All broken `trans()` key references corrected across the app
> - All hardcoded user-facing strings wrapped in `trans()` with `en.json` entries
> - `navigation` vs `nav` key duplication resolved
> - New reusable `.Claude/skills/scan-translations/` skill for future audits
> 
> **Estimated Effort**: Medium
> **Parallel Execution**: YES - 2 waves
> **Critical Path**: Task 1 → Task 2 → Task 3 → Task 4 → Task 5 → Task 6

---

## Context

### Original Request
User identified two specific issues:
1. `status_pages_index_view.dart:33` uses `trans('navigation.status_pages')` — the key doesn't exist in `en.json` (correct key is `nav.status_pages`)
2. `status_pages_index_view.dart:48` has hardcoded `'Create Status Page'` — should use `trans()` with an `en.json` key

User asked to fix these issues across the ENTIRE app, and then create a reusable skill/command to automatically scan and fix these in the future.

### Interview Summary
**Key Discussions**:
- Exhaustive search found 556 `trans()` calls across 57 files
- 5 broken trans() keys identified (referencing non-existent en.json entries)
- ~45 hardcoded user-facing strings found across 8 view files
- `navigation` section in en.json duplicates `nav` section — needs unification
- Mock data in dashboard/search are temporary stubs — excluded from scope

**Research Findings**:
- `en.json` has both `"navigation"` (line 117-119) and `"nav"` (line 120-131) sections with overlapping `monitors` key
- Alert views (`alerts_index_view.dart`, `alert_rules_index_view.dart`, `alert_rule_form.dart`, `alert_rule_list_item.dart`, `alert_list_item.dart`) have the most hardcoded strings (~35 combined)
- `alert_controller.dart` is the reference model — it already uses `trans()` throughout
- Social login buttons construct strings manually but `auth.sign_in_with` / `auth.sign_up_with` keys with `:provider` param already exist
- `alerts_index_view.dart` `_buildFilterChip` uses the label string for BOTH display AND logic comparison — needs careful refactoring

### Metis Review
**Identified Gaps** (addressed):
- `navigation.monitors` was initially flagged as broken but actually EXISTS in en.json (line 118) — corrected
- Mock data strings in `dashboard_view.dart` and `search_autocomplete.dart` are development stubs with comments saying "will be replaced with real API data" — excluded from scope
- Alert filter chip logic depends on label strings (`if (label == 'Alerting')`) — must separate identifier from display text during translation
- `teams.create_team` doesn't exist, but `team.create_team` does — fix must point to correct section

---

## Work Objectives

### Core Objective
Ensure 100% of user-facing strings in the Flutter app are translatable via `trans()` with valid `en.json` keys, and establish automated tooling to maintain this standard.

### Concrete Deliverables
- Fixed broken trans() keys in 5 locations across 4 files
- `navigation` section removed from `en.json`, usages migrated to `nav`
- ~45 hardcoded strings converted to `trans()` across 8 files
- ~30 new keys added to `en.json` under appropriate sections
- `.Claude/skills/scan-translations/` skill created for future audits

### Definition of Done
- [ ] Zero broken trans() keys in entire codebase (all referenced keys exist in `en.json`)
- [ ] Zero hardcoded user-facing strings in view/controller files (excluding mock data stubs)
- [ ] `en.json` valid JSON with no duplicate keys
- [ ] `flutter analyze` passes with zero new warnings
- [ ] Scanner skill correctly identifies zero issues on cleaned codebase

### Must Have
- All broken trans() keys fixed
- All hardcoded strings in alerts views, status pages, auth, team selector converted
- Valid en.json after changes
- Reusable scanner skill/command

### Must NOT Have (Guardrails)
- DO NOT translate mock/placeholder data in `dashboard_view.dart` lines 102-132 or lines 170-196 — these are temporary development stubs marked with "Mock data" comments
- DO NOT translate mock data in `search_autocomplete.dart` lines 46-123 — same reason
- DO NOT translate className strings, route paths (`'/monitors'`), format patterns (`'MMM d, HH:mm'`), JSON keys, enum values, or log messages
- DO NOT change string values/meanings during translation wrapping — preserve exact English text as en.json values
- DO NOT add keys to en.json that duplicate existing keys — always check before adding
- DO NOT use `flex-wrap` in any new Wind UI code — use `wrap` display type instead
- DO NOT modify any test files in this task (tests are separate concern)

---

## Verification Strategy

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks in this plan MUST be verifiable WITHOUT any human action.

### Test Decision
- **Infrastructure exists**: YES (Flutter test framework)
- **Automated tests**: NO (this is a text/key replacement task — verification is via static analysis and key matching)
- **Framework**: N/A

### Agent-Executed QA Scenarios (MANDATORY — ALL tasks)

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| **en.json validity** | Bash (python3) | Parse JSON, verify structure |
| **Key existence** | Bash (grep + python3) | Extract all trans() keys, cross-reference with en.json |
| **Static analysis** | Bash (dart analyze) | Zero new warnings/errors |
| **Hardcoded strings** | Bash (grep) | Search for unresolved hardcoded patterns |
| **Scanner skill** | Bash (run skill) | Verify skill finds zero issues on clean codebase |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately):
├── Task 1: Fix broken trans() keys (en.json + dart files)
└── (sequential within wave)

Wave 2 (After Wave 1):
├── Task 2: Convert alerts_index_view.dart hardcoded strings
├── Task 3: Convert alert_rules_index_view.dart hardcoded strings
├── Task 4: Convert alert_rule_form.dart hardcoded strings
└── Task 5: Convert alert_rule_list_item.dart + alert_list_item.dart + other files

Wave 3 (After Wave 2):
└── Task 6: Create /scan-translations skill
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | 2, 3, 4, 5 | None (must complete first) |
| 2 | 1 | 6 | 3, 4, 5 |
| 3 | 1 | 6 | 2, 4, 5 |
| 4 | 1 | 6 | 2, 3, 5 |
| 5 | 1 | 6 | 2, 3, 4 |
| 6 | 2, 3, 4, 5 | None | None (final) |

### Agent Dispatch Summary

| Wave | Tasks | Recommended Agents |
|------|-------|-------------------|
| 1 | 1 | delegate_task(category="quick", load_skills=["magic-framework"]) |
| 2 | 2, 3, 4, 5 | delegate_task(category="quick", load_skills=["wind-ui", "magic-framework"]) — run in parallel |
| 3 | 6 | delegate_task(category="unspecified-high", load_skills=["magic-framework"]) |

---

## TODOs

- [ ] 1. Fix Broken trans() Keys + Unify navigation/nav Sections

  **What to do**:
  
  **Step A: Fix 5 broken trans() keys in dart files:**
  
  | File | Line | Current (broken) | Fix To |
  |------|------|-----------------|--------|
  | `lib/app/controllers/analytics_controller.dart` | 59 | `trans('errors.network')` | `trans('errors.network_error')` |
  | `lib/resources/views/monitors/monitor_alerts_view.dart` | 44 | `trans('errors.monitor_not_found')` | `trans('monitors.not_found')` |
  | `lib/resources/views/status_pages/status_pages_index_view.dart` | 33 | `trans('navigation.status_pages')` | `trans('nav.status_pages')` |
  | `lib/resources/views/monitors/monitors_index_view.dart` | 76 | `trans('navigation.monitors')` | `trans('nav.monitors')` |
  | `lib/resources/views/teams/team_create_view.dart` | 53 | `trans('teams.create_team')` | `trans('team.create_team')` |
  
  **Step B: Add missing key to en.json:**
  Add `"load_failed": "Failed to load data"` under the `"errors"` section in `assets/lang/en.json`.
  
  Current `errors` section (line 517-519):
  ```json
  "errors": {
    "network_error": "Network error. Please check your connection and try again."
  }
  ```
  Change to:
  ```json
  "errors": {
    "network_error": "Network error. Please check your connection and try again.",
    "load_failed": "Failed to load data"
  }
  ```
  
  **Step C: Remove `navigation` section from en.json:**
  The `navigation` section (lines 117-119) only has `"monitors": "Monitors"` which duplicates `nav.monitors` (line 122). After fixing `monitors_index_view.dart` to use `nav.monitors`, remove the entire `navigation` section:
  ```json
  // REMOVE this block (lines 117-119):
  "navigation": {
    "monitors": "Monitors"
  },
  ```

  **Must NOT do**:
  - Do not change any trans() keys that are NOT broken
  - Do not add keys that already exist in en.json
  - Do not modify the `nav` section structure

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Simple find-and-replace in 5 dart files + minor en.json edits
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Needed for understanding `trans()` function and Magic Framework conventions

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 1 (solo — foundational)
  - **Blocks**: Tasks 2, 3, 4, 5
  - **Blocked By**: None

  **References**:

  **Pattern References:**
  - `lib/app/controllers/alert_controller.dart` — Reference for proper `trans()` usage in controllers (e.g., line 276: `trans(enabled ? 'alerts.rule_enabled' : 'alerts.rule_disabled')`)
  - `assets/lang/en.json:117-131` — The `navigation` (117-119) vs `nav` (120-131) sections showing the duplication

  **API/Type References:**
  - `assets/lang/en.json:517-519` — Current `errors` section to extend with `load_failed`

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All trans() keys exist in en.json
    Tool: Bash (grep + python3)
    Preconditions: All edits complete
    Steps:
      1. Run: grep -rn "trans('" lib/ --include="*.dart" | grep -oP "(?<=trans\(')[^']+(?=')" | sort -u > /tmp/used_keys.txt
      2. Run: python3 -c "
         import json
         with open('assets/lang/en.json') as f:
             data = json.load(f)
         def flatten(d, prefix=''):
             keys = []
             for k,v in d.items():
                 key = f'{prefix}.{k}' if prefix else k
                 if isinstance(v, dict):
                     keys.extend(flatten(v, key))
                 else:
                     keys.append(key)
             return keys
         all_keys = set(flatten(data))
         with open('/tmp/used_keys.txt') as f:
             used = {line.strip() for line in f if line.strip()}
         # Exclude dynamic keys (variables, ternaries)
         static_used = {k for k in used if not any(c in k for c in ['$', '{', '}'])}
         missing = static_used - all_keys
         if missing:
             print(f'BROKEN KEYS FOUND: {missing}')
             exit(1)
         else:
             print(f'ALL {len(static_used)} STATIC KEYS VALID')
         "
      3. Assert: exit code 0, output contains "ALL" and "KEYS VALID"
    Expected Result: Zero broken keys
    Evidence: Terminal output captured

  Scenario: en.json is valid JSON and navigation section removed
    Tool: Bash (python3)
    Preconditions: en.json edited
    Steps:
      1. python3 -c "import json; d=json.load(open('assets/lang/en.json')); assert 'navigation' not in d, 'navigation section still exists'; assert 'load_failed' in d.get('errors',{}), 'errors.load_failed missing'; print('VALID: navigation removed, errors.load_failed added')"
      2. Assert: exit code 0
    Expected Result: JSON valid, navigation gone, new key present
    Evidence: Terminal output

  Scenario: No references to navigation.* keys remain in codebase
    Tool: Bash (grep)
    Preconditions: Dart files edited
    Steps:
      1. grep -rn "navigation\." lib/ --include="*.dart" | grep "trans(" 
      2. Assert: output is empty (zero matches)
    Expected Result: Zero trans() calls using navigation.* prefix
    Evidence: Terminal output
  ```

  - [ ] en.json valid JSON after edits
  - [ ] `navigation` section removed from en.json
  - [ ] `errors.load_failed` key added to en.json
  - [ ] Zero trans() calls referencing `navigation.*` keys
  - [ ] `dart analyze lib/` passes with zero new errors

  **Commit**: YES
  - Message: `fix(i18n): correct broken trans() keys and unify navigation/nav sections`
  - Files: `assets/lang/en.json`, `lib/app/controllers/analytics_controller.dart`, `lib/resources/views/monitors/monitor_alerts_view.dart`, `lib/resources/views/status_pages/status_pages_index_view.dart`, `lib/resources/views/monitors/monitors_index_view.dart`, `lib/resources/views/teams/team_create_view.dart`

---

- [ ] 2. Convert Hardcoded Strings: alerts_index_view.dart

  **What to do**:
  
  **Step A: Add new keys to `en.json` under `alerts` section:**
  Add the following keys to the existing `"alerts"` section in `assets/lang/en.json`:
  ```json
  "alerts_history_title": "Alerts History",
  "alerts_history_subtitle": "View and manage triggered alerts",
  "manage_rules": "Manage Rules",
  "status_alerting": "Alerting",
  "status_resolved": "Resolved"
  ```
  Note: `"no_alerts"` and `"no_alerts_desc"` already exist in en.json.
  Note: `common.all` already exists in en.json.
  
  **Step B: Replace hardcoded strings in `lib/resources/views/alerts/alerts_index_view.dart`:**
  
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 35 | `title: 'Alerts History'` | `title: trans('alerts.alerts_history_title')` |
  | 36 | `subtitle: 'View and manage triggered alerts'` | `subtitle: trans('alerts.alerts_history_subtitle')` |
  | 49 | `WText('Manage Rules')` | `WText(trans('alerts.manage_rules'))` |
  | 67 | `_buildFilterChip('All', ...)` | See Step C below |
  | 69 | `_buildFilterChip('Alerting', ...)` | See Step C below |
  | 73 | `_buildFilterChip('Resolved', ...)` | See Step C below |
  | 101 | `'No alerts'` | `trans('alerts.no_alerts')` |
  
  **Step C: CRITICAL — Refactor `_buildFilterChip` to separate display text from logic:**
  
  The current code uses the label string for BOTH display AND comparison logic (`if (label == 'Alerting')`). This will BREAK when the label is translated. Refactor as follows:
  
  Current pattern:
  ```dart
  _buildFilterChip('All', statusFilter == null),
  _buildFilterChip('Alerting', statusFilter == AlertStatus.alerting),
  _buildFilterChip('Resolved', statusFilter == AlertStatus.resolved),
  ```
  
  Change call sites to pass AlertStatus? directly:
  ```dart
  _buildFilterChip(trans('common.all'), null, statusFilter == null),
  _buildFilterChip(trans('alerts.status_alerting'), AlertStatus.alerting, statusFilter == AlertStatus.alerting),
  _buildFilterChip(trans('alerts.status_resolved'), AlertStatus.resolved, statusFilter == AlertStatus.resolved),
  ```
  
  Refactor method signature:
  ```dart
  Widget _buildFilterChip(String label, AlertStatus? filterValue, bool isSelected) {
    return WAnchor(
      onTap: () {
        if (onStatusFilterChanged == null) return;
        onStatusFilterChanged!(isSelected ? null : filterValue);
      },
      child: WDiv(
        // ... rest stays same
      ),
    );
  }
  ```

  **Must NOT do**:
  - Do not change styling/className strings
  - Do not modify widget structure (only text content)
  - Do not break the filter chip tap behavior

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Straightforward string replacements with one method signature refactor
  - **Skills**: [`wind-ui`, `magic-framework`]
    - `wind-ui`: Understanding WText, WButton, WDiv patterns
    - `magic-framework`: trans() function usage

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 3, 4, 5)
  - **Blocks**: Task 6
  - **Blocked By**: Task 1

  **References**:
  
  **Pattern References:**
  - `lib/resources/views/monitors/monitors_index_view.dart:239-244` — Example of correctly translated filter tabs using `trans('common.all')`, `trans('monitor_status.active')`, etc.
  
  **API/Type References:**
  - `lib/app/enums/alert_status.dart` — AlertStatus enum values used in filter chip logic
  - `assets/lang/en.json:617-648` — Existing `alerts` section to extend

  **Acceptance Criteria**:

  ```
  Scenario: No hardcoded user-facing strings in alerts_index_view.dart
    Tool: Bash (grep)
    Preconditions: File edited
    Steps:
      1. grep -n "WText('[A-Z]" lib/resources/views/alerts/alerts_index_view.dart
      2. grep -n "title: '[A-Z]" lib/resources/views/alerts/alerts_index_view.dart
      3. grep -n "subtitle: '[A-Z]" lib/resources/views/alerts/alerts_index_view.dart
      4. Assert: all three commands produce empty output
    Expected Result: Zero hardcoded strings
    Evidence: Terminal output

  Scenario: Filter chips work with translated strings
    Tool: Bash (dart analyze)
    Preconditions: File edited
    Steps:
      1. dart analyze lib/resources/views/alerts/alerts_index_view.dart
      2. Assert: exit code 0, no errors
    Expected Result: Clean analysis
    Evidence: Terminal output
  ```

  - [ ] Zero hardcoded user-facing strings in file
  - [ ] Filter chip logic uses AlertStatus? parameter, not string comparison
  - [ ] New en.json keys added under `alerts` section
  - [ ] `dart analyze` clean on this file

  **Commit**: YES (groups with Tasks 3, 4, 5)
  - Message: `feat(i18n): convert all hardcoded strings to trans() in alert and status page views`
  - Files: `assets/lang/en.json`, `lib/resources/views/alerts/alerts_index_view.dart`

---

- [ ] 3. Convert Hardcoded Strings: alert_rules_index_view.dart

  **What to do**:
  
  **Step A: Add new keys to `en.json` under `alerts` section:**
  ```json
  "rules_title": "Alert Rules",
  "rules_subtitle": "Configure conditions for automated alerts",
  "add_rule": "Add Rule",
  "no_rules_text": "No alert rules"
  ```
  Note: `alerts.no_rules` already exists with value "No alert rules configured" — check if `emptyText` here should reuse it or have a shorter version. Use `alerts.no_rules` if value matches closely enough.
  
  **Step B: Replace hardcoded strings in `lib/resources/views/alerts/alert_rules_index_view.dart`:**
  
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 35 | `title: 'Alert Rules'` | `title: trans('alerts.alert_rules')` (key already exists!) |
  | 36 | `subtitle: 'Configure conditions for automated alerts'` | `subtitle: trans('alerts.rules_subtitle')` |
  | 50 | `WText('Add Rule', ...)` | `WText(trans('alerts.add_rule'), ...)` |
  | 66 | `emptyText: 'No alert rules'` | `emptyText: trans('alerts.no_rules')` |

  **Must NOT do**:
  - Do not change AppList widget structure
  - Do not modify the onAddRule/onEditRule/onDeleteRule callbacks

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: 4 simple string replacements
  - **Skills**: [`wind-ui`, `magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 2, 4, 5)
  - **Blocks**: Task 6
  - **Blocked By**: Task 1

  **References**:
  
  **Pattern References:**
  - `lib/resources/views/status_pages/status_pages_index_view.dart:33-34` — Example of AppPageHeader with trans() title/subtitle
  - `assets/lang/en.json:617-648` — Existing `alerts` section

  **Acceptance Criteria**:

  ```
  Scenario: No hardcoded strings in alert_rules_index_view.dart
    Tool: Bash (grep)
    Steps:
      1. grep -n "WText('[A-Z]" lib/resources/views/alerts/alert_rules_index_view.dart
      2. grep -n "title: '[A-Z]" lib/resources/views/alerts/alert_rules_index_view.dart
      3. grep -n "emptyText: '[A-Z]" lib/resources/views/alerts/alert_rules_index_view.dart
      4. Assert: empty output
    Expected Result: Zero hardcoded strings
    Evidence: Terminal output
  ```

  - [ ] Zero hardcoded user-facing strings
  - [ ] New keys added or existing keys reused
  - [ ] `dart analyze` clean

  **Commit**: YES (groups with Tasks 2, 4, 5)

---

- [ ] 4. Convert Hardcoded Strings: alert_rule_form.dart + alert_rule_list_item.dart + alert_list_item.dart

  **What to do**:
  
  **Step A: Add new keys to `en.json` under `alerts` section:**
  ```json
  "fill_required_fields": "Please fill in all required fields",
  "basic_info": "Basic Information",
  "rule_name": "Rule Name",
  "rule_name_placeholder": "e.g., High Response Time",
  "rule_name_required": "Rule name is required",
  "alert_type": "Alert Type",
  "severity": "Severity",
  "consecutive_checks": "Consecutive Failed Checks",
  "consecutive_checks_placeholder": "1",
  "consecutive_checks_hint": "Number of consecutive failures before triggering alert",
  "threshold_config": "Threshold Configuration",
  "metric_key": "Metric Key",
  "metric_key_placeholder": "e.g., response_time",
  "metric_key_hint": "The metric to monitor (e.g., response_time, uptime_percentage)",
  "operator": "Operator",
  "threshold_value": "Threshold Value",
  "threshold_value_placeholder": "5000",
  "minimum": "Minimum",
  "maximum": "Maximum",
  "save_rule": "Save Alert Rule",
  "condition_monitor_down": "Monitor down",
  "unnamed_rule": "Unnamed Rule",
  "team_level": "Team Level",
  "fallback_name": "Alert",
  "duration_label": "Duration:"
  ```
  
  **Step B: Replace hardcoded strings in `lib/resources/views/components/alerts/alert_rule_form.dart`:**
  
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 80 | `Magic.toast('Please fill in all required fields')` | `Magic.toast(trans('alerts.fill_required_fields'))` |
  | 121 | `title: 'Basic Information'` | `title: trans('alerts.basic_info')` |
  | 128 | `label: 'Rule Name'` | `label: trans('alerts.rule_name')` |
  | 129 | `placeholder: 'e.g., High Response Time'` | `placeholder: trans('alerts.rule_name_placeholder')` |
  | 132 | `return 'Rule name is required'` | `return trans('alerts.rule_name_required')` |
  | 156 | `label: 'Alert Type'` | `label: trans('alerts.alert_type')` |
  | 187 | `label: 'Severity'` | `label: trans('alerts.severity')` |
  | 219 | `label: 'Consecutive Failed Checks'` | `label: trans('alerts.consecutive_checks')` |
  | 222 | hint text | `hint: trans('alerts.consecutive_checks_hint')` |
  | 246 | `title: 'Threshold Configuration'` | `title: trans('alerts.threshold_config')` |
  | 253 | `label: 'Metric Key'` | `label: trans('alerts.metric_key')` |
  | 254 | `placeholder: 'e.g., response_time'` | `placeholder: trans('alerts.metric_key_placeholder')` |
  | 255-256 | hint text | `hint: trans('alerts.metric_key_hint')` |
  | 276 | `label: 'Operator'` | `label: trans('alerts.operator')` |
  | 310 | `label: 'Threshold Value'` | `label: trans('alerts.threshold_value')` |
  | 311 | `placeholder: '5000'` | `placeholder: trans('alerts.threshold_value_placeholder')` |
  | 312-313 | hint text (dynamic with operator value) | Keep hint as-is OR use trans with param. If hint includes `_selectedOperator.value`, keep dynamic: `hint: trans('alerts.threshold_value_hint', {'operator': _selectedOperator.value})` — BUT only if you add the key. Simpler: leave dynamic hints as-is if they contain variable interpolation. |
  | 339 | `label: 'Minimum'` | `label: trans('alerts.minimum')` |
  | 360 | `label: 'Maximum'` | `label: trans('alerts.maximum')` |
  | 397 | `WText('Cancel')` | `WText(trans('common.cancel'))` |
  | 407 | `WText('Save Alert Rule')` | `WText(trans('alerts.save_rule'))` |
  
  NOTE on line 312-313: The hint is `'Alert when metric ${_selectedOperator.value} this value'` — this contains Dart variable interpolation. Add a trans key `"threshold_value_hint": "Alert when metric :operator this value"` and use `trans('alerts.threshold_value_hint', {'operator': _selectedOperator.value})`.
  
  **Step C: Replace hardcoded strings in `lib/resources/views/components/alerts/alert_rule_list_item.dart`:**
  
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 22 | `return 'Monitor down'` | `return trans('alerts.condition_monitor_down')` |
  | 50 | `rule.name ?? 'Unnamed Rule'` | `rule.name ?? trans('alerts.unnamed_rule')` |
  | 65 | `'Team Level'` | `trans('alerts.team_level')` |
  
  **Step D: Replace hardcoded strings in `lib/resources/views/components/alerts/alert_list_item.dart`:**
  
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 59 | `alertRule.name ?? 'Alert'` | `alertRule.name ?? trans('alerts.fallback_name')` |
  | 71 | `isAlerting ? 'Alerting' : 'Resolved'` | `isAlerting ? trans('alerts.status_alerting') : trans('alerts.status_resolved')` |
  | 100 | `'Duration: ${_formatDuration(...)}'` | `'${trans('alerts.duration_label')} ${_formatDuration(...)}'` |

  **Must NOT do**:
  - Do not change the Form widget structure or validation logic
  - Do not modify TextEditingController bindings
  - Do not change the `_getConditionText()` return for threshold/anomaly cases (they return formatted metric values, not user-facing labels)
  - Do not change `_formatDuration()` method logic

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Many replacements but all mechanical find-and-replace
  - **Skills**: [`wind-ui`, `magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 2, 3, 5)
  - **Blocks**: Task 6
  - **Blocked By**: Task 1

  **References**:
  
  **Pattern References:**
  - `lib/resources/views/components/monitors/monitor_basic_info_section.dart:41-48` — Example of form section with trans() labels/hints
  - `lib/app/controllers/alert_controller.dart:192` — Example of `Magic.toast(trans(...))` pattern
  - `lib/resources/views/monitors/monitor_show_view.dart:249` — Example of `WText(trans('common.edit'))` pattern

  **API/Type References:**
  - `lib/resources/views/components/app_card.dart` — AppCard component accepts `title` parameter
  - `assets/lang/en.json:617-648` — Existing `alerts` section to extend

  **Acceptance Criteria**:

  ```
  Scenario: No hardcoded user-facing strings in alert form files
    Tool: Bash (grep)
    Steps:
      1. grep -n "WText('[A-Z]" lib/resources/views/components/alerts/alert_rule_form.dart
      2. grep -n "label: '[A-Z]" lib/resources/views/components/alerts/alert_rule_form.dart
      3. grep -n "WText('[A-Z]" lib/resources/views/components/alerts/alert_rule_list_item.dart
      4. grep -n "WText('[A-Z]" lib/resources/views/components/alerts/alert_list_item.dart
      5. Assert: all produce empty output
    Expected Result: Zero hardcoded strings in all 3 files
    Evidence: Terminal output

  Scenario: Alert rule form still compiles
    Tool: Bash (dart analyze)
    Steps:
      1. dart analyze lib/resources/views/components/alerts/
      2. Assert: exit code 0
    Expected Result: Clean analysis
    Evidence: Terminal output
  ```

  - [ ] Zero hardcoded strings in all 3 alert component files
  - [ ] All new en.json keys under `alerts` section
  - [ ] `dart analyze` clean on all 3 files

  **Commit**: YES (groups with Tasks 2, 3, 5)

---

- [ ] 5. Convert Hardcoded Strings: Remaining Files (status_pages, social_login, team_selector, monitor_alerts_view)

  **What to do**:
  
  **Step A: Add new keys to `en.json`:**
  Under `status_pages` section, add:
  ```json
  "create_button": "Create Status Page"
  ```
  
  Under `alerts` section (if not already added), add:
  ```json
  "status_alerting": "Alerting",
  "status_resolved": "Resolved"
  ```
  (These may already be added by Task 2 — check before duplicating)
  
  **Step B: Replace hardcoded strings:**
  
  **`lib/resources/views/status_pages/status_pages_index_view.dart`:**
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 48 | `WText('Create Status Page')` | `WText(trans('status_pages.create_button'))` |
  
  **`lib/resources/views/components/auth/social_login_buttons.dart`:**
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 28-30 | `_label()` method with `'Sign in'` / `'Sign up'` string concat | Refactor to use existing `auth.sign_in_with` / `auth.sign_up_with` with `:provider` param |
  
  Refactor `_label()` method:
  ```dart
  // BEFORE:
  String _label(String provider) {
    final action = mode == SocialAuthMode.signIn ? 'Sign in' : 'Sign up';
    return '$action with $provider';
  }
  
  // AFTER:
  String _label(String provider) {
    return mode == SocialAuthMode.signIn
        ? trans('auth.sign_in_with', {'provider': provider})
        : trans('auth.sign_up_with', {'provider': provider});
  }
  ```
  
  **`lib/resources/views/components/navigation/team_selector.dart`:**
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 281 | `team.isPersonalTeam ? 'Personal' : 'Team'` | `team.isPersonalTeam ? trans('teams.personal') : trans('teams.team')` |
  
  Note: `teams.personal` and `teams.team` already exist in en.json (lines 474-475). This is a duplicate at line 281 that missed the trans() wrapping — lines 109-110 already use trans() correctly for the same strings.
  
  **`lib/resources/views/monitors/monitor_alerts_view.dart`:**
  | Line | Current | Replace With |
  |------|---------|-------------|
  | 82 | `monitor.name ?? 'Unnamed Monitor'` | `monitor.name ?? trans('monitors.unnamed')` |
  
  Note: `monitors.unnamed` already exists in en.json (line 185) with value "Unnamed Monitor".

  **Must NOT do**:
  - Do not modify the social login button structure/styling
  - Do not change the PopoverController behavior in team selector
  - Do not add duplicate keys to en.json

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Small changes across multiple files
  - **Skills**: [`wind-ui`, `magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 2, 3, 4)
  - **Blocks**: Task 6
  - **Blocked By**: Task 1

  **References**:
  
  **Pattern References:**
  - `lib/resources/views/components/navigation/team_selector.dart:108-110` — Already correct trans() usage for `teams.personal`/`teams.team` at top of same file (lines 109-110 use trans, line 281 doesn't)
  - `assets/lang/en.json:51-52` — Existing `auth.sign_in_with` and `auth.sign_up_with` with `:provider` param

  **API/Type References:**
  - `assets/lang/en.json:474-475` — `teams.personal` = "Personal", `teams.team` = "Team"
  - `assets/lang/en.json:185` — `monitors.unnamed` = "Unnamed Monitor"

  **Acceptance Criteria**:

  ```
  Scenario: No hardcoded user-facing strings in remaining files
    Tool: Bash (grep)
    Steps:
      1. grep -n "'Create Status Page'" lib/resources/views/status_pages/status_pages_index_view.dart
      2. grep -n "'Sign in'" lib/resources/views/components/auth/social_login_buttons.dart
      3. grep -n "'Personal'" lib/resources/views/components/navigation/team_selector.dart
      4. grep -n "'Unnamed Monitor'" lib/resources/views/monitors/monitor_alerts_view.dart
      5. Assert: all produce empty output
    Expected Result: Zero hardcoded strings remain
    Evidence: Terminal output

  Scenario: Social login uses parameterized trans() keys
    Tool: Bash (grep)
    Steps:
      1. grep -n "auth.sign_in_with" lib/resources/views/components/auth/social_login_buttons.dart
      2. Assert: at least 1 match found
    Expected Result: Uses auth.sign_in_with trans key
    Evidence: Terminal output
  ```

  - [ ] Zero hardcoded strings in all 4 files
  - [ ] Social login uses `auth.sign_in_with` / `auth.sign_up_with` with provider param
  - [ ] Team selector line 281 uses `trans('teams.personal')` / `trans('teams.team')`
  - [ ] `dart analyze` clean on all modified files

  **Commit**: YES (groups with Tasks 2, 3, 4)
  - Message: `feat(i18n): convert all hardcoded strings to trans() in alert and status page views`
  - Files: All modified files from Tasks 2-5

---

- [ ] 6. Create `/scan-translations` Reusable Skill

  **What to do**:
  
  Create a new skill at `.Claude/skills/scan-translations/` that can be activated to automatically scan the entire Flutter app for:
  1. **Broken trans() keys** — `trans()` calls referencing keys that don't exist in `en.json`
  2. **Hardcoded user-facing strings** — String literals in UI contexts that should be wrapped in `trans()`
  
  **Step A: Create skill instruction file:**
  Create `.Claude/skills/scan-translations/instructions.md` with:
  
  - Skill name, description, trigger phrases
  - Step-by-step scanning algorithm:
    1. Parse `assets/lang/en.json` to build set of all valid keys (flattened dot notation)
    2. Use grep to find all `trans('...')` calls across `lib/` — extract keys
    3. Cross-reference: flag any trans() key NOT in the valid set
    4. Use grep/ast-grep to find string literals in UI widget contexts:
       - `WText('...')`, `WText("...")`
       - `label: '...'`, `label: "..."`
       - `title: '...'`, `title: "..."`
       - `subtitle: '...'`, `subtitle: "..."`
       - `hint: '...'`, `hint: "..."`
       - `placeholder: '...'`, `placeholder: "..."`
       - `hintText: '...'`, `hintText: "..."`
       - `emptyText: '...'`, `emptyText: "..."`
       - `Magic.toast('...')`
       - `child: WText('...')`
    5. EXCLUDE patterns (NOT user-facing):
       - Strings already wrapped in `trans()`: `trans('...')`
       - className strings: `className: '...'`
       - Route paths: `MagicRoute.to('...')`
       - Format strings: `DateFormat('...')`
       - CSS/styling: strings containing `bg-`, `text-`, `flex`, `px-`, `py-`, `rounded-`, `border-`, `dark:`, `hover:`
       - Empty strings: `''`, `""`
       - Single characters: `'•'`, `'/'`
       - Variable interpolations: strings starting with `$`
       - Icon references: `Icons.`
       - Asset paths: `assets/`
       - Log messages: `Log.debug(`, `Log.error(`
       - Keys/identifiers: strings containing only lowercase, underscores, dots (like JSON keys)
       - Comments: `//` lines
       - Numeric strings: `'1'`, `'200'`
       - Enum value strings: inside switch/case
       - Mock data within blocks commented as "Mock data"
    6. Report findings grouped by file with:
       - File path, line number
       - Current string or trans key
       - Issue type: `BROKEN_KEY` or `HARDCODED_STRING`
       - Suggested fix (key name for hardcoded, correct key for broken)
       - Confidence: HIGH/MEDIUM/LOW
  
  **Step B: Create the skill resource files:**
  The skill should include a reference of the en.json structure so it can suggest appropriate section placements for new keys.
  
  **Step C: Register the skill in `.Claude/skills/scan-translations/` with proper metadata.**

  The skill MUST:
  - Work on the entire `lib/` directory recursively
  - Handle both `.dart` files only
  - Cross-reference against `assets/lang/en.json` specifically
  - Output a clear, actionable report
  - Flag dynamic trans() calls (where key is a variable) as INFO, not errors
  - Be idempotent: running on a clean codebase should report zero issues

  **Must NOT do**:
  - Do not auto-fix files — the skill is a SCANNER/REPORTER only
  - Do not modify any existing skills in `.Claude/skills/`
  - Do not generate false positives for the patterns listed in the exclusion list

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Building a new skill with complex pattern matching logic requires careful design
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Understanding trans() function and Magic Framework conventions

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 3 (final, solo)
  - **Blocks**: None
  - **Blocked By**: Tasks 2, 3, 4, 5

  **References**:
  
  **Pattern References:**
  - `.Claude/skills/wind-ui/` — Example of existing skill structure and format
  - `.Claude/skills/magic-framework/` — Example of existing skill with code references
  - `assets/lang/en.json` — The translation source file the skill must parse

  **Documentation References:**
  - `AGENTS.md` — Project conventions including trans() usage requirements

  **Acceptance Criteria**:

  ```
  Scenario: Skill files exist with correct structure
    Tool: Bash (ls)
    Steps:
      1. ls -la .Claude/skills/scan-translations/
      2. Assert: instructions.md exists
    Expected Result: Skill directory and instructions file exist
    Evidence: Terminal output

  Scenario: Skill correctly reports zero issues on cleaned codebase
    Tool: Bash (grep + python3 validation)
    Preconditions: Tasks 1-5 complete, all hardcoded strings fixed
    Steps:
      1. Run the scanning algorithm described in the skill instructions against the codebase
      2. Extract all trans() keys and cross-reference with en.json
      3. Search for hardcoded strings in UI contexts with exclusion patterns
      4. Assert: zero BROKEN_KEY issues found
      5. Assert: zero HIGH-confidence HARDCODED_STRING issues found (MEDIUM/LOW may exist for edge cases)
    Expected Result: Clean bill of health
    Evidence: Terminal output with scan results
  ```

  - [ ] `.Claude/skills/scan-translations/instructions.md` exists and is comprehensive
  - [ ] Skill documentation covers all scanning patterns (broken keys + hardcoded strings)
  - [ ] Exclusion patterns are well-documented (className, routes, format strings, etc.)
  - [ ] Running the scan algorithm on the cleaned codebase produces zero critical issues

  **Commit**: YES
  - Message: `feat(tooling): add /scan-translations skill for i18n audit automation`
  - Files: `.Claude/skills/scan-translations/instructions.md`

---

## Commit Strategy

| After Task | Message | Files | Verification |
|------------|---------|-------|--------------|
| 1 | `fix(i18n): correct broken trans() keys and unify navigation/nav sections` | en.json + 5 dart files | Key cross-reference script |
| 2+3+4+5 | `feat(i18n): convert all hardcoded strings to trans() in alert and status page views` | en.json + ~8 dart files | grep for hardcoded patterns |
| 6 | `feat(tooling): add /scan-translations skill for i18n audit automation` | .Claude/skills/scan-translations/ | Skill validation |

---

## Success Criteria

### Verification Commands
```bash
# 1. All trans() keys valid
grep -rn "trans('" lib/ --include="*.dart" | grep -oP "(?<=trans\(')[^']+(?=')" | sort -u > /tmp/used_keys.txt
python3 -c "
import json
with open('assets/lang/en.json') as f:
    data = json.load(f)
def flatten(d, prefix=''):
    keys = []
    for k,v in d.items():
        key = f'{prefix}.{k}' if prefix else k
        if isinstance(v, dict): keys.extend(flatten(v, key))
        else: keys.append(key)
    return keys
all_keys = set(flatten(data))
with open('/tmp/used_keys.txt') as f:
    used = {l.strip() for l in f if l.strip()}
static = {k for k in used if '\$' not in k and '{' not in k}
missing = static - all_keys
print(f'Missing: {missing}' if missing else f'ALL {len(static)} KEYS VALID')
exit(1 if missing else 0)
"  # Expected: ALL N KEYS VALID

# 2. en.json valid
python3 -c "import json; json.load(open('assets/lang/en.json')); print('VALID')"  # Expected: VALID

# 3. No navigation.* references remain
grep -rn "navigation\." lib/ --include="*.dart" | grep "trans("  # Expected: empty

# 4. Static analysis clean
dart analyze lib/  # Expected: No issues found

# 5. Skill exists
ls .Claude/skills/scan-translations/instructions.md  # Expected: file exists
```

### Final Checklist
- [ ] All "Must Have" present
- [ ] All "Must NOT Have" absent (no mock data translated, no className translated)
- [ ] Zero broken trans() keys
- [ ] Zero hardcoded user-facing strings in target files
- [ ] `navigation` section removed from en.json
- [ ] `en.json` valid JSON
- [ ] Scanner skill created and validated
- [ ] All dart files pass `dart analyze`
