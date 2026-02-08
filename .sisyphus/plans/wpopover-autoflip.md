# WPopover Auto-Flip Boundary Detection

## TL;DR

> **Quick Summary**: Add screen boundary detection to WPopover so it automatically flips alignment when the popover would overflow off-screen. Fix is entirely in the Wind UI plugin — all 5 app usages benefit automatically.
> 
> **Deliverables**:
> - Modified `w_popover.dart` with auto-flip logic
> - New unit tests for the pure flip computation function
> - Extended widget integration tests for boundary scenarios
> - Updated Wind UI skill documentation
> 
> **Estimated Effort**: Medium
> **Parallel Execution**: YES — 2 waves
> **Critical Path**: Task 1 → Task 2 → Task 3 → Task 4

---

## Context

### Original Request
WPopover has NO screen boundary detection. When `PopoverAlignment.bottomRight` is used on mobile screens, the popover overflows off the LEFT edge of the screen, becoming unusable. Confirmed on the Status Pages Show page. Need to add auto-flip logic that detects overflow and switches to the opposite alignment.

### Interview Summary
**Key Discussions**:
- User provided detailed proposed solution: convert static getters to methods, compute overflow in `_buildOverlay`, flip alignment dynamically
- Scope is plugin-only — fix in `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart`
- Must handle all 6 `PopoverAlignment` options
- Must NOT break existing behavior — only flip when overflow would occur
- Tests required (TDD per AGENTS.md)
- Skill docs must be updated after fix

**Research Findings**:
- WSelect already implements vertical-only boundary detection in `_toggleMenu()` (lines 326-341) using `localToGlobal` + `MediaQuery` — same pattern needed for WPopover but for both axes
- **WSelect does NOT use WPopover** (separate overlay system) — WSelect is out of scope
- Existing WPopover tests exist at `fluttersdk_wind/test/widgets/w_popover_test.dart` (377 lines, 12+ test cases)
- 5 app usages confirmed: `status_page_show_view.dart`, `notification_dropdown.dart`, `user_profile_card.dart`, `team_selector.dart`, `search_autocomplete.dart`

### Metis Review
**Identified Gaps** (addressed):
- **`isTopAlignment` offset inversion must use effective alignment**: Lines 392-397 compute `isTopAlignment` from `widget.alignment`. After flip, this MUST use the effective (flipped) alignment or the Y-offset won't invert correctly. → **Incorporated into Task 2 implementation steps.**
- **Offset must be included in overflow calculation**: A popover with `offset: Offset(50, 4)` can overflow even when alignment alone wouldn't. → **Incorporated into Task 1 pure function signature and Task 2.**
- **Center-aligned horizontal overflow limitation**: A centered popover near a screen edge can overflow horizontally but can't "flip" horizontally (it stays centered). → **Documented as known limitation.**
- **Auto-flip is always-on, no opt-in parameter**: Adding `autoFlip` parameter is unnecessary — the behavior only activates when overflow would occur, so it's invisible when there's enough space. No API change needed.
- **Height estimation uses `maxHeight` (conservative)**: Using `widget.maxHeight` (default 400) as height estimate. May flip unnecessarily for small content, but is always safe. Matches WSelect's approach.
- **Existing 12+ tests must pass at every stage**: Baseline verification before any changes.

---

## Work Objectives

### Core Objective
Add intelligent auto-flip boundary detection to WPopover that automatically adjusts popover alignment when the requested alignment would cause the popover to overflow off-screen edges, while preserving all existing behavior when there is sufficient space.

### Concrete Deliverables
- `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart` — modified with auto-flip logic
- `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart` — extended with boundary detection tests
- Wind UI skill documentation — updated with auto-flip behavior description

### Definition of Done
- [ ] All 6 `PopoverAlignment` options handle overflow correctly
- [ ] Horizontal overflow triggers horizontal flip (e.g., bottomRight → bottomLeft)
- [ ] Vertical overflow triggers vertical flip (e.g., bottomLeft → topLeft)
- [ ] Both-axis overflow triggers both flips (e.g., bottomRight → topLeft)
- [ ] No flip occurs when popover fits in requested alignment
- [ ] All existing WPopover tests pass unchanged
- [ ] New unit tests cover the pure flip computation function
- [ ] New widget tests verify flip behavior in constrained screens
- [ ] Wind UI full test suite (`flutter test`) has no new failures
- [ ] Skill docs updated

### Must Have
- Pure computation function for flip logic (testable without widget infrastructure)
- Horizontal AND vertical overflow detection
- Offset included in overflow calculations
- Effective alignment used for offset Y-inversion (not `widget.alignment`)
- Safe fallback when triggerBox is null or has no size (use requested alignment unchanged)

### Must NOT Have (Guardrails)
- **DO NOT modify `WSelect`** — it has its own independent overlay system, entirely out of scope
- **DO NOT modify any app-level files** — all 5 usages must remain unchanged
- **DO NOT change `PopoverAlignment` enum** — no new values (`auto`, `adaptive`, etc.)
- **DO NOT add animation to the flip** — popover appears directly in computed position
- **DO NOT add `autoFlip` parameter or any public API change** — flip is always-on internal behavior
- **DO NOT add `onAlignmentChanged` callback** — YAGNI, no usage needs it
- **DO NOT implement resize/clamp behavior** — only flip, never resize to fit
- **DO NOT use `addPostFrameCallback` for measurement** — calculation must be synchronous in `_buildOverlay`
- **DO NOT fix CLAUDE.md WSelect→WPopover claim** — separate documentation task

---

## Verification Strategy

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks in this plan MUST be verifiable WITHOUT any human action.

### Test Decision
- **Infrastructure exists**: YES — Wind UI has 695+ tests, WPopover has 12+ existing tests
- **Automated tests**: YES (TDD) — RED-GREEN-REFACTOR per AGENTS.md
- **Framework**: `flutter_test` (standard Flutter test framework)
- **Test file**: `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart`

### TDD Workflow Per Task

Each task follows RED-GREEN-REFACTOR:
1. **RED**: Write failing test first
   - Test command: `flutter test test/widgets/w_popover_test.dart` (from Wind plugin root)
   - Expected: FAIL (test exists, implementation doesn't)
2. **GREEN**: Implement minimum code to pass
   - Command: `flutter test test/widgets/w_popover_test.dart`
   - Expected: PASS
3. **REFACTOR**: Clean up while keeping green
   - Command: `flutter test test/widgets/w_popover_test.dart`
   - Expected: PASS (still)

### Agent-Executed QA Scenarios (MANDATORY — ALL tasks)

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| Pure function logic | Bash (flutter test) | Run unit tests, assert all pass |
| Widget behavior | Bash (flutter test) | Run widget tests with constrained screen sizes |
| Regression | Bash (flutter test) | Run full Wind UI test suite |
| Skill docs | Read tool | Verify docs mention auto-flip |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately):
├── Task 1: TDD — Pure flip computation function + unit tests

Wave 2 (After Wave 1):
├── Task 2: TDD — Integrate flip logic into WPopover._buildOverlay + widget tests

Wave 3 (After Wave 2):
├── Task 3: Full regression test run + fix any issues
└── Task 4: Update Wind UI skill documentation (parallel with Task 3)
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | 2 | None (Wave 1 start) |
| 2 | 1 | 3, 4 | None |
| 3 | 2 | None | 4 |
| 4 | 2 | None | 3 |

### Agent Dispatch Summary

| Wave | Tasks | Recommended Agents |
|------|-------|-------------------|
| 1 | 1 | `delegate_task(category="deep", load_skills=["wind-ui"], ...)` |
| 2 | 2 | `delegate_task(category="deep", load_skills=["wind-ui"], ...)` |
| 3 | 3, 4 | Task 3: `quick` category; Task 4: `writing` category |

---

## TODOs

- [ ] 1. TDD: Pure Flip Computation Function + Unit Tests

  **What to do**:
  
  **RED phase — Write failing tests first:**
  1. Open `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart`
  2. Add a new test group `'Auto-Flip Boundary Detection'` with a sub-group `'computeEffectiveAlignment (pure function)'`
  3. Write unit tests for the following scenarios (all should FAIL initially since function doesn't exist):
     - `bottomLeft` with plenty of space → returns `bottomLeft` unchanged
     - `bottomRight` where popover would overflow left edge → returns `bottomLeft` (horizontal flip)
     - `bottomLeft` where popover would overflow below screen → returns `topLeft` (vertical flip)
     - `bottomRight` where popover overflows both left AND bottom → returns `topLeft` (double flip)
     - `topLeft` where popover would overflow above screen → returns `bottomLeft` (vertical flip)
     - `topRight` with plenty of space → returns `topRight` unchanged
     - `bottomCenter` where popover overflows below → returns `topCenter` (vertical flip only)
     - `topCenter` where popover overflows above → returns `bottomCenter` (vertical flip)
     - Trigger near right edge with `bottomLeft` and offset `Offset(50, 4)` where offset pushes popover off right edge → flips to `bottomRight`
     - Trigger at center of large screen with any alignment → returns requested alignment unchanged (no flip needed)
  4. Run `flutter test test/widgets/w_popover_test.dart` → expect failures for new tests
  
  **GREEN phase — Implement the pure function:**
  5. In `w_popover.dart`, add a static/top-level or private method to `_WPopoverState`:
     ```dart
     /// Computes the effective alignment, flipping if the popover would overflow screen bounds.
     ///
     /// Parameters:
     /// - [requested]: The alignment the consumer specified
     /// - [triggerPosition]: Global position of trigger's top-left corner (from localToGlobal)
     /// - [triggerSize]: Size of the trigger widget
     /// - [popoverSize]: Estimated size of the popover (width from className parse or trigger, height from maxHeight)
     /// - [screenSize]: Screen dimensions from MediaQuery
     /// - [offset]: The gap offset applied between trigger and popover
     ///
     /// Returns the [requested] alignment if it fits, or a flipped variant if overflow detected.
     PopoverAlignment _computeEffectiveAlignment({
       required PopoverAlignment requested,
       required Offset triggerPosition,
       required Size triggerSize,
       required Size popoverSize,
       required Size screenSize,
       required Offset offset,
     })
     ```
  6. Implement the flip logic:
     - **Vertical check**: For `bottom*` alignments: `triggerPosition.dy + triggerSize.height + offset.dy + popoverSize.height > screenSize.height` → flip to `top*`. For `top*` alignments: `triggerPosition.dy - offset.dy - popoverSize.height < 0` → flip to `bottom*`.
     - **Horizontal check**: For `*Left` (content extends rightward): `triggerPosition.dx + offset.dx + popoverSize.width > screenSize.width` → flip to `*Right`. For `*Right` (content extends leftward from trigger's right edge): `triggerPosition.dx + triggerSize.width - offset.dx - popoverSize.width < 0` → flip to `*Left`. For `*Center`: no horizontal flip (known limitation).
     - **Both-axis flip**: Apply vertical and horizontal independently.
     - **Fallback**: If flipped direction also overflows, prefer the side with more available space.
  7. Run `flutter test test/widgets/w_popover_test.dart` → all tests PASS
  
  **REFACTOR phase:**
  8. Clean up the function — ensure clear variable names, add dartdoc comments
  9. Run tests again → still PASS

  **Must NOT do**:
  - Do NOT integrate into `_buildOverlay` yet — that's Task 2
  - Do NOT modify the existing `_targetAnchor`, `_followerAnchor`, `_overlayAlignment` getters yet
  - Do NOT touch any files outside the Wind UI plugin
  - Do NOT add any public API changes to WPopover

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Needs thorough understanding of coordinate math, edge cases, and careful TDD execution with multiple test scenarios
  - **Skills**: [`wind-ui`]
    - `wind-ui`: Needed for understanding WPopover's architecture, PopoverAlignment enum, and Wind test patterns
  - **Skills Evaluated but Omitted**:
    - `magic-framework`: Not relevant — this is a pure Wind UI widget task, no facades or controllers involved
    - `flutter-design`: Not relevant — no visual design work, purely logic
    - `mobile-app-design-mastery`: Not relevant — no mobile design patterns needed
    - `frontend-ui-ux`: Not relevant — no UI/UX decisions, purely computation
    - `playwright`: Not relevant — no browser testing
    - `git-master`: Not needed — no commits in this task
    - `dev-browser`: Not relevant — no browser automation

  **Parallelization**:
  - **Can Run In Parallel**: NO (first task, starts Wave 1)
  - **Parallel Group**: Wave 1 (solo)
  - **Blocks**: Task 2
  - **Blocked By**: None

  **References**:

  **Pattern References** (existing code to follow):
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart:272-303` — Current static alignment getters (`_targetAnchor`, `_followerAnchor`, `_overlayAlignment`) that show the 6-alignment switch pattern
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_select.dart:326-341` — WSelect's `_toggleMenu()` boundary detection pattern using `localToGlobal` + `MediaQuery` — the same approach to use for WPopover but extended to both axes
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart:7-28` — `PopoverAlignment` enum definition with all 6 values

  **Test References** (testing patterns to follow):
  - `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart:1-13` — Existing test file with `wrapWithTheme` helper and test structure
  - `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart:16-30` — Example test pattern (group nesting, `testWidgets`, `pumpWidget`)

  **API/Type References** (contracts to implement against):
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart:10-28` — `PopoverAlignment` enum — the input and output type of the computation function
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart:357-431` — `_buildOverlay` method where triggerBox, parsedWidth, offset, and maxHeight are already available — shows what data the function will receive in Task 2

  **Acceptance Criteria**:

  > **AGENT-EXECUTABLE VERIFICATION ONLY** — No human action permitted.

  **TDD (tests):**
  - [ ] New test group `'Auto-Flip Boundary Detection'` added to existing test file
  - [ ] Minimum 10 unit test cases covering: no-flip, horizontal-flip, vertical-flip, double-flip, center-alignment, offset-inclusive
  - [ ] `flutter test test/widgets/w_popover_test.dart` → PASS (all new + all 12 existing tests)

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: All existing tests still pass (regression baseline)
    Tool: Bash (flutter test)
    Preconditions: Wind UI plugin at plugins/magic/plugins/fluttersdk_wind/
    Steps:
      1. cd to Wind UI plugin root
      2. Run: flutter test test/widgets/w_popover_test.dart
      3. Assert: exit code 0
      4. Assert: output contains "All tests passed" or "0 failures"
    Expected Result: All 12+ existing tests pass, plus all new tests pass
    Failure Indicators: Any test failure, exit code != 0
    Evidence: Terminal output captured to .sisyphus/evidence/task-1-test-results.txt

  Scenario: Pure function correctly returns unchanged alignment when no overflow
    Tool: Bash (flutter test)
    Preconditions: Tests written per RED phase
    Steps:
      1. Run flutter test with --name filter for "no overflow" test cases
      2. Assert: test passes — function returns requested alignment
    Expected Result: Alignment unchanged when popover fits
    Evidence: Test output

  Scenario: Pure function flips horizontal when left-edge overflow detected
    Tool: Bash (flutter test)
    Preconditions: Tests written per RED phase
    Steps:
      1. Run test for bottomRight on narrow screen (trigger at right edge, popover extends left beyond x=0)
      2. Assert: function returns bottomLeft
    Expected Result: Horizontal flip from right to left
    Evidence: Test output

  Scenario: Pure function flips vertical when bottom overflow detected
    Tool: Bash (flutter test)
    Preconditions: Tests written per RED phase
    Steps:
      1. Run test for bottomLeft near bottom of screen
      2. Assert: function returns topLeft
    Expected Result: Vertical flip from bottom to top
    Evidence: Test output
  ```

  **Evidence to Capture:**
  - [ ] Terminal output of `flutter test test/widgets/w_popover_test.dart` saved to `.sisyphus/evidence/task-1-test-results.txt`

  **Commit**: YES
  - Message: `feat(wind): add pure flip computation function for WPopover boundary detection`
  - Files: `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart`, `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart`
  - Pre-commit: `flutter test test/widgets/w_popover_test.dart`

---

- [ ] 2. TDD: Integrate Flip Logic into WPopover._buildOverlay + Widget Tests

  **What to do**:

  **RED phase — Write failing widget integration tests first:**
  1. In the same test file, add a sub-group `'Auto-Flip Widget Integration'` under the `'Auto-Flip Boundary Detection'` group
  2. Write widget tests that create WPopover in constrained screen sizes:
     - Test: Trigger at bottom-right of a 400x300 screen, `bottomRight` alignment, `w-56` (224px) popover — assert popover content is still findable (flip happened, didn't overflow)
     - Test: Trigger at bottom of a 400x300 screen, `bottomLeft` alignment — assert content visible (vertical flip to topLeft)
     - Test: Trigger at top-left of a 400x300 screen, `topLeft` alignment — assert content visible (vertical flip to bottomLeft)
     - Test: Trigger at center of a 800x600 screen, `bottomLeft` alignment — assert content visible (no flip needed, baseline)
     - Test: Open/close cycle after flip works correctly (overlay hide/show not broken by flip logic)
  3. Use `tester.binding.setSurfaceSize(Size(400, 300))` to constrain screen size in tests, and position trigger using `Align` or `Positioned` within a `Stack`
  4. Run tests → expect failures

  **GREEN phase — Integrate the pure function into `_buildOverlay`:**
  5. Convert static getters `_targetAnchor`, `_followerAnchor`, `_overlayAlignment` into methods that accept a `PopoverAlignment` parameter:
     ```dart
     Alignment _targetAnchorFor(PopoverAlignment alignment) { ... }
     Alignment _followerAnchorFor(PopoverAlignment alignment) { ... }
     Alignment _overlayAlignmentFor(PopoverAlignment alignment) { ... }
     ```
  6. In `_buildOverlay`, after getting `triggerBox` and `parsedWidth` (before constructing `CompositedTransformFollower`):
     ```dart
     // Compute effective alignment with boundary detection
     PopoverAlignment effectiveAlignment = widget.alignment;
     if (triggerBox != null && triggerBox.hasSize) {
       final triggerPosition = triggerBox.localToGlobal(Offset.zero);
       final screenSize = MediaQuery.sizeOf(context);
       final popoverWidth = parsedWidth ?? triggerWidth;
       final popoverHeight = widget.maxHeight;
       effectiveAlignment = _computeEffectiveAlignment(
         requested: widget.alignment,
         triggerPosition: triggerPosition,
         triggerSize: triggerBox.size,
         popoverSize: Size(popoverWidth, popoverHeight),
         screenSize: screenSize,
         offset: widget.offset,
       );
     }
     ```
  7. **CRITICAL**: Update the `isTopAlignment` computation (currently line 392-397) to use `effectiveAlignment` instead of `widget.alignment`:
     ```dart
     // BEFORE (broken after flip):
     final bool isTopAlignment = widget.alignment == PopoverAlignment.topLeft || ...
     // AFTER (correct):
     final bool isTopAlignment = effectiveAlignment == PopoverAlignment.topLeft ||
         effectiveAlignment == PopoverAlignment.topCenter ||
         effectiveAlignment == PopoverAlignment.topRight;
     ```
  8. Pass `effectiveAlignment` to the new methods:
     ```dart
     return CompositedTransformFollower(
       link: _layerLink,
       targetAnchor: _targetAnchorFor(effectiveAlignment),
       followerAnchor: _followerAnchorFor(effectiveAlignment),
       offset: effectiveOffset,
       child: Align(
         alignment: _overlayAlignmentFor(effectiveAlignment),
         // ... rest unchanged
       ),
     );
     ```
  9. Run tests → all PASS

  **REFACTOR phase:**
  10. Clean up: remove the old static getters (now replaced by parameterized methods)
  11. Add dartdoc comments explaining the auto-flip behavior
  12. Run tests → still PASS

  **Must NOT do**:
  - Do NOT modify any app-level files (the 5 usages)
  - Do NOT add any public parameter to WPopover
  - Do NOT change `PopoverAlignment` enum
  - Do NOT add animation to the position change
  - Do NOT modify WSelect
  - Do NOT use `addPostFrameCallback` — keep calculation synchronous

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Integration with existing widget architecture requires careful understanding of `CompositedTransformFollower`, `OverlayPortal`, coordinate systems, and the critical `isTopAlignment` offset inversion point
  - **Skills**: [`wind-ui`]
    - `wind-ui`: Needed for understanding WPopover internals, WindParser, and Wind widget test patterns
  - **Skills Evaluated but Omitted**:
    - `magic-framework`: Not relevant — pure Wind UI widget work
    - `flutter-design`: Not relevant — no visual design changes
    - `mobile-app-design-mastery`: Not relevant — no design patterns
    - `frontend-ui-ux`: Not relevant — no UI/UX decisions
    - `playwright`: Not relevant — no browser testing
    - `git-master`: Not needed for implementation, commit at end
    - `dev-browser`: Not relevant

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 2 (after Wave 1)
  - **Blocks**: Tasks 3, 4
  - **Blocked By**: Task 1

  **References**:

  **Pattern References** (existing code to follow):
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart:357-431` — Current `_buildOverlay` method — the integration point. Shows exactly where to insert the flip computation (after line 363 where triggerBox is obtained, before line 399 where CompositedTransformFollower is constructed)
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart:272-303` — Current static getters to convert to parameterized methods
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart:389-397` — **CRITICAL**: The `isTopAlignment` + `effectiveOffset` computation that MUST use `effectiveAlignment` after this change
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_select.dart:326-341` — WSelect's boundary detection pattern for reference (simpler vertical-only version of what we're building)

  **Test References** (testing patterns to follow):
  - `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart:6-13` — `wrapWithTheme` helper for widget tests
  - `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart:32-49` — Pattern for testing popover open/close with tap + pumpAndSettle

  **API/Type References**:
  - `flutter:widgets/MediaQuery.sizeOf(context)` — Screen size access in overlay context
  - `flutter:rendering/RenderBox.localToGlobal(Offset.zero)` — Trigger global position
  - `flutter:widgets/CompositedTransformFollower` — The follower widget that receives the computed anchors

  **Acceptance Criteria**:

  > **AGENT-EXECUTABLE VERIFICATION ONLY**

  **TDD (tests):**
  - [ ] Widget integration tests added for constrained screen scenarios
  - [ ] Minimum 5 widget test cases: flip-horizontal, flip-vertical, flip-both, no-flip-baseline, open-close-after-flip
  - [ ] `flutter test test/widgets/w_popover_test.dart` → PASS (all existing + Task 1 + Task 2 tests)

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Static getters replaced with parameterized methods
    Tool: Bash (grep)
    Preconditions: w_popover.dart modified
    Steps:
      1. grep for "get _targetAnchor" in w_popover.dart
      2. Assert: NOT found (old getter removed)
      3. grep for "_targetAnchorFor" in w_popover.dart
      4. Assert: found (new method exists)
      5. grep for "_computeEffectiveAlignment" in w_popover.dart
      6. Assert: found (flip function called in _buildOverlay)
    Expected Result: Old getters replaced, new methods and flip call present
    Evidence: grep output

  Scenario: isTopAlignment uses effectiveAlignment (not widget.alignment)
    Tool: Bash (grep)
    Preconditions: w_popover.dart modified
    Steps:
      1. grep for "effectiveAlignment == PopoverAlignment.top" in w_popover.dart
      2. Assert: found (effectiveAlignment used for isTopAlignment check)
      3. grep for "widget.alignment == PopoverAlignment.top" in _buildOverlay section
      4. Assert: NOT found in offset computation area (old pattern removed from _buildOverlay)
    Expected Result: Offset Y-inversion uses flipped alignment
    Evidence: grep output

  Scenario: All tests pass including new widget integration tests
    Tool: Bash (flutter test)
    Preconditions: Wind UI plugin root
    Steps:
      1. Run: flutter test test/widgets/w_popover_test.dart
      2. Assert: exit code 0
      3. Assert: test count increased (12+ existing + ~10 from Task 1 + ~5 from Task 2)
    Expected Result: All 27+ tests pass
    Evidence: Terminal output saved to .sisyphus/evidence/task-2-test-results.txt
  ```

  **Evidence to Capture:**
  - [ ] Terminal output of full popover test run saved to `.sisyphus/evidence/task-2-test-results.txt`
  - [ ] grep evidence of correct effectiveAlignment usage

  **Commit**: YES
  - Message: `feat(wind): integrate auto-flip boundary detection into WPopover overlay`
  - Files: `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart`, `plugins/magic/plugins/fluttersdk_wind/test/widgets/w_popover_test.dart`
  - Pre-commit: `flutter test test/widgets/w_popover_test.dart`

---

- [ ] 3. Full Regression Test Run

  **What to do**:
  1. Run the complete Wind UI test suite from the plugin root: `flutter test` (all 695+ tests)
  2. If any NEW failures appear (not the 3 known failures mentioned in CLAUDE.md), investigate and fix
  3. Verify the 3 known failures are the same ones as before (not caused by our changes)
  4. If fixes are needed, run the suite again to confirm

  **Must NOT do**:
  - Do NOT modify tests to make them pass (unless our changes genuinely broke something)
  - Do NOT skip failing tests
  - Do NOT modify app-level code

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Simple task — run test suite, compare results, fix if needed
  - **Skills**: [`wind-ui`]
    - `wind-ui`: Needed to understand Wind UI test infrastructure and known failures
  - **Skills Evaluated but Omitted**:
    - All others: Not relevant for a test execution task

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Task 4)
  - **Parallel Group**: Wave 3
  - **Blocks**: None
  - **Blocked By**: Task 2

  **References**:

  **Test References**:
  - Wind UI CLAUDE.md states: "695+ tests, 3 known failures" — baseline to compare against
  - `plugins/magic/plugins/fluttersdk_wind/test/` — Full test directory

  **Acceptance Criteria**:

  ```
  Scenario: Full Wind UI test suite regression check
    Tool: Bash (flutter test)
    Preconditions: Tasks 1 and 2 complete, Wind UI plugin root
    Steps:
      1. cd to Wind UI plugin root
      2. Run: flutter test
      3. Count total tests, passes, failures
      4. Assert: No NEW failures beyond the 3 known ones
      5. If new failures exist: read failure output, identify cause, fix, re-run
    Expected Result: Same pass/fail ratio as before our changes (3 known failures max)
    Failure Indicators: New test failures not in the known 3
    Evidence: Terminal output saved to .sisyphus/evidence/task-3-regression-results.txt
  ```

  **Evidence to Capture:**
  - [ ] Full test suite output saved to `.sisyphus/evidence/task-3-regression-results.txt`

  **Commit**: NO (only if fixes were needed — then YES with message `fix(wind): resolve regression from WPopover auto-flip integration`)

---

- [ ] 4. Update Wind UI Skill Documentation

  **What to do**:
  1. Find the Wind UI skill documentation. Based on AGENTS.md, skills are in `.Claude/skills/`. The Wind UI skill is loaded as `wind-ui`.
  2. Locate the WPopover section in the skill documentation
  3. Add documentation about the auto-flip behavior:
     - Explain that `alignment` is a *preferred* alignment
     - When the popover would overflow screen bounds, it automatically flips to the opposite side
     - Horizontal overflow → horizontal flip (e.g., `bottomRight` → `bottomLeft`)
     - Vertical overflow → vertical flip (e.g., `bottomLeft` → `topLeft`)
     - Both axes can flip independently
     - No flip occurs when there is sufficient space (existing behavior preserved)
     - Known limitation: Center-aligned popovers (bottomCenter, topCenter) only flip vertically, not horizontally
     - Height estimation uses `maxHeight` parameter (conservative — may flip earlier than strictly necessary for small content)
  4. Also update the "Gotchas" section if it exists — add a note about the auto-flip behavior

  **Must NOT do**:
  - Do NOT rewrite the entire skill doc — only add/update the WPopover section
  - Do NOT document WSelect changes (none were made)
  - Do NOT add information about `autoFlip` parameter (it doesn't exist)

  **Recommended Agent Profile**:
  - **Category**: `writing`
    - Reason: Documentation update — technical writing task
  - **Skills**: [`wind-ui`]
    - `wind-ui`: Needed to understand current skill doc structure and WPopover documentation
  - **Skills Evaluated but Omitted**:
    - All others: Not relevant for a documentation task

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Task 3)
  - **Parallel Group**: Wave 3
  - **Blocks**: None
  - **Blocked By**: Task 2

  **References**:

  **Documentation References**:
  - AGENTS.md states: "After plugin changes, update corresponding skill in `.Claude/skills/`"
  - The Wind UI skill is referenced as `wind-ui` in skill loading
  - Current WPopover documentation in the skill should describe the existing API

  **Source References**:
  - `plugins/magic/plugins/fluttersdk_wind/lib/src/widgets/w_popover.dart` — The modified source to document

  **Acceptance Criteria**:

  ```
  Scenario: Skill docs updated with auto-flip information
    Tool: Read tool + grep
    Preconditions: Skill doc file located
    Steps:
      1. Find Wind UI skill file
      2. Read it
      3. Assert: Contains mention of "auto-flip" or "boundary detection" or "screen overflow"
      4. Assert: Contains mention that alignment is "preferred" and may be flipped
      5. Assert: Contains known limitation about Center-aligned popovers
    Expected Result: Documentation accurately describes new behavior
    Evidence: File content verification

  Scenario: Skill docs don't contain incorrect claims
    Tool: Read tool
    Preconditions: Skill doc updated
    Steps:
      1. Read updated skill doc
      2. Assert: Does NOT claim WSelect uses WPopover
      3. Assert: Does NOT mention autoFlip parameter
      4. Assert: Does NOT describe resize/clamp behavior
    Expected Result: No misinformation in docs
    Evidence: File content verification
  ```

  **Commit**: YES
  - Message: `docs(wind): document WPopover auto-flip boundary detection in skill`
  - Files: Wind UI skill documentation file(s)
  - Pre-commit: None (docs only)

---

## Task Dependency Graph

| Task | Depends On | Reason |
|------|------------|--------|
| Task 1: Pure flip function + unit tests | None | Starting point — pure computation with no widget dependencies |
| Task 2: Integrate into _buildOverlay + widget tests | Task 1 | Requires the pure function from Task 1 to integrate into the widget |
| Task 3: Full regression test run | Task 2 | Must run full suite only after all code changes are complete |
| Task 4: Update skill documentation | Task 2 | Must document the final implementation, needs code to be stable |

## Parallel Execution Graph

```
Wave 1 (Start Immediately):
└── Task 1: TDD — Pure flip computation function + unit tests

Wave 2 (After Wave 1):
└── Task 2: TDD — Integrate flip logic into WPopover._buildOverlay + widget tests

Wave 3 (After Wave 2):
├── Task 3: Full regression test run
└── Task 4: Update Wind UI skill documentation

Critical Path: Task 1 → Task 2 → Task 3
Parallel Speedup: ~15% (Wave 3 runs 2 tasks in parallel)
```

---

## Commit Strategy

| After Task | Message | Files | Verification |
|------------|---------|-------|--------------|
| 1 | `feat(wind): add pure flip computation function for WPopover boundary detection` | `w_popover.dart`, `w_popover_test.dart` | `flutter test test/widgets/w_popover_test.dart` |
| 2 | `feat(wind): integrate auto-flip boundary detection into WPopover overlay` | `w_popover.dart`, `w_popover_test.dart` | `flutter test test/widgets/w_popover_test.dart` |
| 3 | Only if fixes needed: `fix(wind): resolve regression from WPopover auto-flip integration` | Affected files | `flutter test` (full suite) |
| 4 | `docs(wind): document WPopover auto-flip boundary detection in skill` | Skill doc files | N/A (docs only) |

---

## Known Limitations (Document, Don't Fix)

1. **Center-aligned horizontal overflow**: `bottomCenter` and `topCenter` popovers can overflow horizontally if the trigger is near a screen edge. Only vertical flip is applied for Center alignments. Horizontal clamping would require offset adjustment, which is out of scope.
2. **Conservative height estimation**: Uses `maxHeight` (default 400) for height check. A 2-item menu (80px actual height) may flip unnecessarily if the space below is 300px. This is safe but sometimes premature.
3. **No notification of actual alignment**: Consumers don't know if a flip occurred. None of the 5 current usages need this.
4. **Rebuild during open popover**: If `_buildOverlay` re-runs while popover is open (parent rebuild, orientation change), the flip recalculates. This could cause the popover to visually shift if screen state changed.

---

## Success Criteria

### Verification Commands
```bash
# From plugins/magic/plugins/fluttersdk_wind/
flutter test test/widgets/w_popover_test.dart  # All popover tests pass (existing + new)
flutter test                                     # Full suite: no new failures beyond 3 known
```

### Final Checklist
- [ ] Pure flip function exists and is tested with 10+ unit test cases
- [ ] `_buildOverlay` uses `_computeEffectiveAlignment` to compute effective alignment
- [ ] `isTopAlignment` uses `effectiveAlignment`, not `widget.alignment`
- [ ] Static getters (`_targetAnchor` etc.) replaced with parameterized methods
- [ ] Offset is included in overflow calculations
- [ ] All 12+ existing WPopover tests pass unchanged
- [ ] All new tests pass
- [ ] Full Wind UI suite has no new failures
- [ ] Wind UI skill docs updated with auto-flip behavior
- [ ] No app-level files modified
- [ ] No WSelect files modified
- [ ] No `PopoverAlignment` enum changes
- [ ] No new public API parameters added to WPopover
