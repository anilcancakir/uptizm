# Announcement Views Implementation

## TL;DR

> **Quick Summary**: Implement 4 placeholder announcement views (index, create, show, edit) following the exact Monitor/Incident patterns, register routes, fix controller navigation, and update Status Page integration.
> 
> **Deliverables**:
> - 4 fully functional announcement views (index, create, show, edit)
> - Route registration in app.dart
> - Controller navigation fix (MagicRoute.back → MagicRoute.to)
> - Status page show view updated with announcement links
> - View tests for all 4 views
> 
> **Estimated Effort**: Medium
> **Parallel Execution**: YES - 3 waves
> **Critical Path**: Task 1 (routes) → Task 2 (controller fix) → Tasks 3-6 (views, parallel) → Task 7 (status page integration) → Task 8 (tests)

---

## Context

### Original Request
User wants the announcement system views implemented. Backend, model, enum, controller, and tests are all done. The 4 view files exist but are placeholder shells showing only centered text. Views must follow the same standard as monitors and incidents views.

### Interview Summary
**Key Discussions**:
- Announcements are nested under status pages (API and routes)
- Must follow exact same Wind UI patterns from incidents/monitors
- Dark mode required
- TDD required per project convention

**Research Findings**:
- **No announcement routes exist** in `lib/routes/app.dart` — must be added
- **AnnouncementController uses MagicRoute.back()** in store/update/destroy — known bug, must fix
- **WFormDatePicker does NOT exist** — must use `showDatePicker()` + WFormInput display pattern
- **AnnouncementType.icon** returns string names, not IconData — views need icon mapping helper
- **Status page show view** has `_buildAnnouncementsCard()` but no links to announcement detail routes
- **Existing view tests** are minimal instantiation checks

### Metis Review
**Identified Gaps** (addressed):
- Route parameter extraction for nested resources — addressed with MagicRouter.instance.pathParameter pattern
- Date validation (endedAt must be after scheduledAt) — addressed in form validation
- WFormDatePicker doesn't exist — using showDatePicker() + formatted display
- Icon string-to-IconData mapping needed — adding helper method in views
- Navigation after create/edit — defaulting to announcement show view

---

## Work Objectives

### Core Objective
Implement all 4 announcement views following the established Monitor/Incident UI patterns, with proper route registration, controller fixes, and status page integration.

### Concrete Deliverables
- `lib/routes/app.dart` — 4 new announcement routes
- `lib/app/controllers/announcement_controller.dart` — MagicRoute.back() → MagicRoute.to() fix
- `lib/resources/views/announcements/announcements_index_view.dart` — Full list view
- `lib/resources/views/announcements/announcement_create_view.dart` — Full create form
- `lib/resources/views/announcements/announcement_show_view.dart` — Full detail view
- `lib/resources/views/announcements/announcement_edit_view.dart` — Full edit form
- `lib/resources/views/status_pages/status_page_show_view.dart` — Updated announcements card with links
- 4 view test files under `test/resources/views/announcements/`

### Definition of Done
- [ ] All 4 views render correctly (not placeholder text)
- [ ] Routes registered and navigable
- [ ] Controller uses explicit MagicRoute.to() navigation
- [ ] Status page show view links to announcement routes
- [ ] `flutter analyze` passes with 0 issues
- [ ] All view tests pass

### Must Have
- Dark mode support (`dark:` variants) on all elements
- Consistent Wind UI classNames matching incident/monitor patterns
- AppPageHeader with back navigation + action buttons
- Form validation on required fields (title, body, type)
- scrollPrimary: true for iOS tap-to-top support
- Proper error and loading states via controller.renderState()

### Must NOT Have (Guardrails)
- NO rich text editor — plain text only for body
- NO per-monitor targeting
- NO approval workflow
- NO bulk operations
- NO `flex-wrap` — use `wrap` display type instead
- NO Container, Text, TextField, Icon widgets — use WDiv, WText, WFormInput, WIcon
- NO MagicRoute.back() — use explicit MagicRoute.to()
- NO package imports within lib/ — use relative imports only
- NO `as int` unsafe casts

---

## Verification Strategy

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**

### Test Decision
- **Infrastructure exists**: YES (flutter test)
- **Automated tests**: YES (tests-after, matching existing minimal pattern)
- **Framework**: flutter test

### Agent-Executed QA Scenarios (MANDATORY)

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| Flutter Views | Bash (flutter test) | Run widget tests, verify build |
| Code Quality | Bash (flutter analyze) | 0 issues |
| Route Registration | Bash (grep) | Verify routes in app.dart |
| Navigation Fix | Bash (grep) | No MagicRoute.back() in controller |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately):
├── Task 1: Route registration in app.dart
└── Task 2: Controller navigation fix

Wave 2 (After Wave 1):
├── Task 3: announcements_index_view.dart
├── Task 4: announcement_create_view.dart
├── Task 5: announcement_show_view.dart
└── Task 6: announcement_edit_view.dart

Wave 3 (After Wave 2):
├── Task 7: Status page show view integration
└── Task 8: View tests + final verification

Critical Path: Task 1 → Task 3 → Task 7
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | 3, 4, 5, 6 | 2 |
| 2 | None | 3, 4, 5, 6 | 1 |
| 3 | 1, 2 | 7 | 4, 5, 6 |
| 4 | 1, 2 | 7 | 3, 5, 6 |
| 5 | 1, 2 | 7 | 3, 4, 6 |
| 6 | 1, 2 | 7 | 3, 4, 5 |
| 7 | 3, 5 | 8 | None |
| 8 | 3, 4, 5, 6, 7 | None | None |

### Agent Dispatch Summary

| Wave | Tasks | Recommended Agents |
|------|-------|-------------------|
| 1 | 1, 2 | task(category="quick", load_skills=["magic-framework"]) |
| 2 | 3, 4, 5, 6 | task(category="visual-engineering", load_skills=["wind-ui", "magic-framework", "flutter-design"]) |
| 3 | 7, 8 | task(category="quick", load_skills=["wind-ui", "magic-framework"]) |

---

## TODOs

- [x] 1. Register Announcement Routes in app.dart

  **What to do**:
  - Add 4 routes nested under status-pages in the existing auth middleware group
  - Route pattern: `/status-pages/:statusPageId/announcements[/create|/:id|/:id/edit]`
  - Extract path parameters using `MagicRouter.instance.pathParameter()`
  - Add import for AnnouncementController
  - All routes use `.transition(RouteTransition.none)` matching existing pattern

  **Route definitions to add** (after status-pages/:id/edit route):
  ```dart
  MagicRoute.page('/status-pages/:statusPageId/announcements', () => AnnouncementController.instance.index(MagicRouter.instance.pathParameter('statusPageId')!)).transition(RouteTransition.none),
  MagicRoute.page('/status-pages/:statusPageId/announcements/create', () => AnnouncementController.instance.create(MagicRouter.instance.pathParameter('statusPageId')!)).transition(RouteTransition.none),
  MagicRoute.page('/status-pages/:statusPageId/announcements/:id', () => AnnouncementController.instance.show(MagicRouter.instance.pathParameter('statusPageId')!, MagicRouter.instance.pathParameter('id')!)).transition(RouteTransition.none),
  MagicRoute.page('/status-pages/:statusPageId/announcements/:id/edit', () => AnnouncementController.instance.edit(MagicRouter.instance.pathParameter('statusPageId')!, MagicRouter.instance.pathParameter('id')!)).transition(RouteTransition.none),
  ```

  **Must NOT do**:
  - Do NOT change any existing routes
  - Do NOT add middleware beyond existing auth group

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Route registration patterns and MagicRouter API

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Task 2)
  - **Blocks**: Tasks 3, 4, 5, 6
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `lib/routes/app.dart` — ALL existing route definitions. Add new routes after the status-pages group (after `/status-pages/:id/edit`). Note: existing routes use `MagicRouter.instance.pathParameter('id')!` pattern.
  
  **API/Type References**:
  - `lib/app/controllers/announcement_controller.dart` — Controller methods: `index(String statusPageId)`, `create(String statusPageId)`, `show(String statusPageId, String id)`, `edit(String statusPageId, String id)`. Must match these signatures.

  **WHY Each Reference Matters**:
  - `app.dart`: Must follow exact route registration pattern (MagicRoute.page, .transition, middleware group). New routes go INSIDE the existing auth group.
  - `announcement_controller.dart`: Controller action signatures determine how route params are passed.

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Routes registered in app.dart
    Tool: Bash (grep)
    Preconditions: None
    Steps:
      1. grep "status-pages/:statusPageId/announcements" lib/routes/app.dart
      2. Assert: 4 lines matching (index, create, show, edit)
      3. grep "AnnouncementController" lib/routes/app.dart
      4. Assert: import exists and 4 controller references
    Expected Result: All 4 announcement routes registered
    Evidence: grep output captured

  Scenario: Build succeeds with new routes
    Tool: Bash (flutter analyze)
    Preconditions: Routes added
    Steps:
      1. Run: flutter analyze lib/routes/app.dart
      2. Assert: 0 issues
    Expected Result: No analysis errors
    Evidence: Analyze output captured
  ```

  **Commit**: YES
  - Message: `feat(routes): register announcement routes nested under status-pages`
  - Files: `lib/routes/app.dart`
  - Pre-commit: `flutter analyze lib/routes/app.dart`

---

- [x] 2. Fix AnnouncementController Navigation (MagicRoute.back → MagicRoute.to)

  **What to do**:
  - Replace 3 instances of `MagicRoute.back()` in AnnouncementController with explicit `MagicRoute.to()`:
    - `store()` (line ~92): After creating → navigate to `/status-pages/$statusPageId/announcements`
    - `update()` (line ~140): After updating → navigate to `/status-pages/$statusPageId/announcements/$id`
    - `destroy()` (line ~178): After deleting → navigate to `/status-pages/$statusPageId/announcements`

  **Must NOT do**:
  - Do NOT change any other controller methods
  - Do NOT change the CRUD logic, only navigation calls

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: MagicRoute navigation patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Task 1)
  - **Blocks**: Tasks 3, 4, 5, 6
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `lib/resources/views/incidents/incident_edit_view.dart` — Shows the correct MagicRoute.to() navigation pattern used after incidents fixes (explicit path instead of back())
  - `lib/app/controllers/announcement_controller.dart` — The 3 MagicRoute.back() calls at lines ~92, ~140, ~178 that need replacing

  **WHY Each Reference Matters**:
  - `incident_edit_view.dart`: This was the same bug we fixed before. Follow the same explicit navigation pattern.
  - `announcement_controller.dart`: Contains the 3 specific lines to change.

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: No MagicRoute.back() calls remain
    Tool: Bash (grep)
    Preconditions: Controller updated
    Steps:
      1. grep "MagicRoute.back" lib/app/controllers/announcement_controller.dart
      2. Assert: 0 matches (no MagicRoute.back remaining)
      3. grep "MagicRoute.to" lib/app/controllers/announcement_controller.dart
      4. Assert: 3+ matches (store, update, destroy all use MagicRoute.to)
    Expected Result: All navigation uses explicit paths
    Evidence: grep output captured

  Scenario: Controller still compiles
    Tool: Bash (flutter analyze)
    Preconditions: Controller updated
    Steps:
      1. Run: flutter analyze lib/app/controllers/announcement_controller.dart
      2. Assert: 0 issues
    Expected Result: No analysis errors
    Evidence: Analyze output captured
  ```

  **Commit**: YES
  - Message: `fix(announcement): replace MagicRoute.back() with explicit MagicRoute.to() navigation`
  - Files: `lib/app/controllers/announcement_controller.dart`
  - Pre-commit: `flutter analyze lib/app/controllers/announcement_controller.dart`

---

- [x] 3. Implement announcements_index_view.dart
- [x] 4. Implement announcement_create_view.dart
- [x] 5. Implement announcement_show_view.dart
- [x] 6. Implement announcement_edit_view.dart

  **What to do**:
  - Replace placeholder with full edit form following incident_edit_view.dart pattern
  - Convert to `MagicStatefulView<AnnouncementController>` with `MagicStatefulViewState`
  - Extract statusPageId and id from route params
  - State: MagicFormData form, AnnouncementType? _selectedType, DateTime? _scheduledAt, DateTime? _endedAt, bool _initialized
  - onInit: `controller.loadAnnouncement(statusPageId, id)`, init form
  - Pre-fill pattern: `_initializeFormFromAnnouncement()` called inside ValueListenableBuilder:
    - Only runs once (_initialized flag)
    - Pre-fill title, body from announcement
    - Set _selectedType from announcement.type
    - Set _scheduledAt from announcement.scheduledAt?.toDateTime()
    - Set _endedAt from announcement.endedAt?.toDateTime()
  - Build: ValueListenableBuilder on selectedAnnouncementNotifier → null = loading → form
  - Form: IDENTICAL structure to create view with these differences:
    - Header title: 'Edit Announcement'
    - Back button → `MagicRoute.to('/status-pages/$statusPageId/announcements/${announcement.id}')`
    - Cancel → same as back
    - Added "Ended At" date picker field (only on edit, not create)
    - Submit: controller.update(statusPageId, announcement.id!, title:, body:, type:, scheduledAt:, endedAt:)
    - Info box: `bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-4` with edit info text

  **Must NOT do**:
  - Do NOT change form field structure beyond adding endedAt
  - Do NOT add approval workflow

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`wind-ui`, `magic-framework`, `flutter-design`]
    - `wind-ui`: WFormInput, WSelect patterns
    - `magic-framework`: MagicForm, controller.update(), ValueListenableBuilder
    - `flutter-design`: Form pre-fill pattern, dark mode

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 3, 4, 5)
  - **Blocks**: Task 8
  - **Blocked By**: Tasks 1, 2

  **References**:

  **Pattern References**:
  - `lib/resources/views/incidents/incident_edit_view.dart` — **PRIMARY template**. Copy: ValueListenableBuilder wrapping renderState, _initialized flag pattern, _initializeFormFromIncident() pre-fill method, form layout identical to create, info box section, cancel/back navigation pattern.
  - `lib/resources/views/announcements/announcement_create_view.dart` — The create form built in Task 4. Edit is the same form + pre-fill + endedAt field.

  **API/Type References**:
  - `lib/app/controllers/announcement_controller.dart` — `update(statusPageId, id, {title?, body?, type?, scheduledAt?, endedAt?})`, `selectedAnnouncementNotifier`, `loadAnnouncement(statusPageId, id)`
  - `lib/app/models/announcement.dart` — All getters for pre-fill values

  **WHY Each Reference Matters**:
  - `incident_edit_view.dart`: EXACT edit pattern — pre-fill with _initialized flag, ValueListenableBuilder wrapping, info box, cancel navigation.
  - `announcement_create_view.dart`: Built in Task 4, edit form mirrors it with pre-fill.
  - `announcement_controller.dart`: Need exact update() signature.

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Edit view compiles with pre-fill pattern
    Tool: Bash (flutter analyze + grep)
    Preconditions: View implemented
    Steps:
      1. Run: flutter analyze lib/resources/views/announcements/announcement_edit_view.dart
      2. Assert: 0 issues
      3. grep "_initialized" lib/resources/views/announcements/announcement_edit_view.dart
      4. Assert: Pre-fill guard pattern present
      5. grep "controller.update" lib/resources/views/announcements/announcement_edit_view.dart
      6. Assert: Update call connected
      7. grep "showDatePicker" lib/resources/views/announcements/announcement_edit_view.dart
      8. Assert: Date pickers for scheduledAt + endedAt
    Expected Result: Edit form with pre-fill and update
    Evidence: Output captured
  ```

  **Commit**: YES (groups with 3, 4, 5)
  - Message: `feat(announcements): implement announcement edit view`
  - Files: `lib/resources/views/announcements/announcement_edit_view.dart`
  - Pre-commit: `flutter analyze lib/resources/views/announcements/`

---

- [x] 7. Update Status Page Show View — Announcements Card Integration

  **What to do**:
  - Update `_buildAnnouncementsCard()` in `status_page_show_view.dart` to add navigation links
  - Each announcement card: wrap in WAnchor or add onTap → `MagicRoute.to('/status-pages/$statusPageId/announcements/$announcementId')`
  - Add "View All" footer button in AppCard footer → `MagicRoute.to('/status-pages/$statusPageId/announcements')`
  - Add "Create" button in AppCard headerActions → small icon button → `MagicRoute.to('/status-pages/$statusPageId/announcements/create')`
  - Keep existing card styling (blue theme)

  **Must NOT do**:
  - Do NOT change the existing card visual style
  - Do NOT change how announcements are loaded
  - Do NOT touch other sections of status_page_show_view

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`wind-ui`, `magic-framework`]
    - `wind-ui`: WAnchor, WButton patterns
    - `magic-framework`: MagicRoute.to() navigation

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 3 (sequential after views)
  - **Blocks**: Task 8
  - **Blocked By**: Tasks 3, 5

  **References**:

  **Pattern References**:
  - `lib/resources/views/status_pages/status_page_show_view.dart:809-854` — Current `_buildAnnouncementsCard()` implementation. Shows existing AppCard usage with blue-themed announcement cards. Need to add WAnchor wrapping and footer/headerActions.
  - `lib/resources/views/components/app_card.dart` — AppCard API: has `headerActions` (List<Widget>?) and `footer` (Widget?) params that are currently unused in announcements card.

  **WHY Each Reference Matters**:
  - `status_page_show_view.dart:809-854`: The exact code to modify. Must preserve existing styling while adding navigation.
  - `app_card.dart`: Need to know AppCard supports `headerActions` and `footer` params for adding create button and "View All" link.

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Announcement cards are now navigable
    Tool: Bash (grep)
    Preconditions: Status page show view updated
    Steps:
      1. grep "WAnchor\|MagicRoute.to.*announcements" lib/resources/views/status_pages/status_page_show_view.dart
      2. Assert: Navigation to announcement routes present
      3. grep "headerActions\|footer" lib/resources/views/status_pages/status_page_show_view.dart
      4. Assert: Create button and/or View All link added
      5. flutter analyze lib/resources/views/status_pages/status_page_show_view.dart
      6. Assert: 0 issues
    Expected Result: Announcements card links to detail routes
    Evidence: Output captured
  ```

  **Commit**: YES
  - Message: `feat(status-pages): add navigation links to announcements card`
  - Files: `lib/resources/views/status_pages/status_page_show_view.dart`
  - Pre-commit: `flutter analyze lib/resources/views/status_pages/`

---

- [x] 8. View Tests + Final Verification

  **What to do**:
  - Create 4 view test files following existing minimal pattern:
    - `test/resources/views/announcements/announcements_index_view_test.dart`
    - `test/resources/views/announcements/announcement_create_view_test.dart`
    - `test/resources/views/announcements/announcement_show_view_test.dart`
    - `test/resources/views/announcements/announcement_edit_view_test.dart`
  - Each test: instantiation check matching existing pattern
  - Run full flutter analyze on all changed files
  - Run flutter test on new test files
  - Verify no MagicRoute.back() remains in controller

  **Test pattern** (from existing view tests):
  ```dart
  import 'package:flutter_test/flutter_test.dart';
  import 'package:uptizm/resources/views/announcements/announcements_index_view.dart';

  void main() {
    test('can be instantiated', () {
      const view = AnnouncementsIndexView();
      expect(view, isA<AnnouncementsIndexView>());
    });
  }
  ```
  
  **NOTE**: The views are now StatefulWidgets (MagicStatefulView), so the `const` constructor may not work. Check if other MagicStatefulView tests use `const` or not, and adapt accordingly.

  **Must NOT do**:
  - Do NOT write integration tests
  - Do NOT change existing tests

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Test patterns for MagicStatefulView

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 3 (final)
  - **Blocks**: None (final task)
  - **Blocked By**: Tasks 3, 4, 5, 6, 7

  **References**:

  **Pattern References**:
  - `test/resources/views/monitors/monitors_index_view_test.dart` — Existing test pattern: simple instantiation check with `const view = X(); expect(view, isA<X>());`

  **WHY Each Reference Matters**:
  - Test files follow project's minimal instantiation pattern. Don't over-engineer.

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All tests pass
    Tool: Bash (flutter test)
    Preconditions: All views implemented, test files created
    Steps:
      1. Run: flutter test test/resources/views/announcements/
      2. Assert: All tests pass (4 test files, 0 failures)
      3. Run: flutter analyze
      4. Assert: 0 issues across entire project
    Expected Result: Clean test run and analysis
    Evidence: Test output + analyze output captured

  Scenario: No MagicRoute.back() in controller
    Tool: Bash (grep)
    Preconditions: Final verification
    Steps:
      1. grep -r "MagicRoute.back" lib/app/controllers/announcement_controller.dart
      2. Assert: 0 matches
    Expected Result: Controller fully migrated to explicit navigation
    Evidence: grep output
  ```

  **Commit**: YES
  - Message: `test(announcements): add view instantiation tests and final verification`
  - Files: `test/resources/views/announcements/*.dart`
  - Pre-commit: `flutter test test/resources/views/announcements/ && flutter analyze`

---

## Commit Strategy

| After Task | Message | Files | Verification |
|------------|---------|-------|--------------|
| 1 | `feat(routes): register announcement routes nested under status-pages` | `lib/routes/app.dart` | `flutter analyze` |
| 2 | `fix(announcement): replace MagicRoute.back() with explicit MagicRoute.to()` | `lib/app/controllers/announcement_controller.dart` | `flutter analyze` |
| 3-6 | `feat(announcements): implement announcement views (index, create, show, edit)` | `lib/resources/views/announcements/*.dart` | `flutter analyze` |
| 7 | `feat(status-pages): add navigation links to announcements card` | `lib/resources/views/status_pages/status_page_show_view.dart` | `flutter analyze` |
| 8 | `test(announcements): add view instantiation tests` | `test/resources/views/announcements/*.dart` | `flutter test && flutter analyze` |

---

## Success Criteria

### Verification Commands
```bash
flutter analyze                                    # Expected: 0 issues
flutter test test/resources/views/announcements/   # Expected: All pass
grep "MagicRoute.back" lib/app/controllers/announcement_controller.dart  # Expected: 0 matches
grep "announcements" lib/routes/app.dart           # Expected: 4 route matches
grep "Announcements Index\|Announcement Create\|Announcement Show\|Announcement Edit" lib/resources/views/announcements/  # Expected: 0 matches (all placeholders replaced)
```

### Final Checklist
- [ ] All 4 announcement views fully implemented (no placeholder text)
- [ ] Routes registered in app.dart
- [ ] Controller uses MagicRoute.to() (no back())
- [ ] Status page links to announcement routes
- [ ] All views have dark mode support
- [ ] All views use scrollPrimary: true
- [ ] All views use AppPageHeader
- [ ] All forms use MagicForm/MagicFormData
- [ ] All input classNames match existing patterns (px-3 py-3 rounded-lg text-sm)
- [ ] All tests pass
- [ ] flutter analyze: 0 issues
