# Status Page Custom Metrics Integration

## TL;DR

> **Quick Summary**: Add the ability for users to select and display custom monitoring metrics (numeric, string, status) on their public status pages. When adding a monitor to a status page, users can pick specific metric mappings to show as badges under each monitor on the public page.
> 
> **Deliverables**:
> - Backend migration for `status_page_monitor_metrics` table
> - Backend API changes to accept/return metric selections in `attachMonitors` flow
> - Backend `PublicStatusPageController` fetches and passes latest metric values to Blade
> - Blade view renders tip-based metric badges under each monitor card
> - Flutter create/edit views show metric checkboxes when a monitor is selected
> - Flutter controller sends metric selections alongside monitor data
> - TDD tests for all layers
> 
> **Estimated Effort**: Medium
> **Parallel Execution**: YES - 2 waves
> **Critical Path**: Task 1 (migration) → Task 2 (backend API) → Task 3 (public page) → Task 5 (Flutter views)

---

## Context

### Original Request
User wants custom monitoring metrics (defined via JSON path extraction on monitors) to be selectable and displayable on public status pages. Currently, status pages only show monitor up/down status and 90-day uptime charts. The metric mapping system already exists for individual monitors — this integrates it into the status page feature.

### Interview Summary
**Key Discussions**:
- **Selection model**: User selects which metrics to show per-monitor via checkboxes (not all-or-nothing)
- **UI flow**: Checkboxes appear immediately when a monitor is added — single step
- **Display**: Badges under each monitor on public page, tip-based styling
- **Custom labels**: NOT needed — use original metric labels from the mapping definition
- **Storage**: Normalized separate table `status_page_monitor_metrics` (not JSON column on pivot)

**Research Findings**:
- Existing `status_page_monitor` pivot has `display_order` and `custom_label` columns
- Metric mappings stored as JSON on `monitors.metric_mappings` with `{label, path, type, unit, up_when}`
- `monitor_metric_values` is a TimescaleDB hypertable with 90-day retention
- `MetricExtractor` service runs via `ProcessCheckResult` job after each check
- `StatusPageResource` currently does NOT include `metric_mappings` for attached monitors
- Existing `StatusMetricBadge` only handles `status` type — NOT numeric or string
- Flutter sends `display_name`/`sort_order` but backend expects `custom_label`/`display_order` (field name mismatch exists)
- Public page cached for 300 seconds via `Cache::remember`

### Metis Review
**Identified Gaps** (addressed):
- **Orphaned metric selections**: When metric mapping removed from monitor, selections become orphaned → Silently skip missing metrics on public page, show "N/A"
- **StatusPageResource missing metric_mappings**: Edit view needs to know available metrics for pre-selected monitors → Add `metric_mappings` to resource monitor map
- **N+1 query risk on public page**: Must use batch `DISTINCT ON` query, not per-monitor lookups
- **Cache invalidation**: Admin changes metric selections but public page stays cached → Add `Cache::forget` after metric changes
- **Null metric values**: Extraction can produce null values → Handle gracefully in Blade rendering
- **Field name mismatch**: Flutter `display_name`/`sort_order` vs backend `custom_label`/`display_order` → Document as known issue, use correct backend field names for new metric fields

---

## Work Objectives

### Core Objective
Enable users to select specific custom metrics per-monitor on status pages and display them as typed badges on the public page.

### Concrete Deliverables
- `back-end/database/migrations/xxxx_create_status_page_monitor_metrics_table.php`
- `back-end/app/Models/StatusPageMonitorMetric.php`
- Updated `back-end/app/Http/Controllers/Api/V1/StatusPageController.php` (attachMonitors accepts metric_keys)
- Updated `back-end/app/Http/Controllers/PublicStatusPageController.php` (batch-fetches latest metric values)
- Updated `back-end/app/Http/Resources/Api/V1/StatusPageResource.php` (includes metric_mappings + selected_metrics)
- Updated `back-end/resources/views/status-page/show.blade.php` (metric badge rendering)
- Updated `back-end/resources/views/layouts/status-page.blade.php` (metric badge CSS)
- Updated `lib/app/controllers/status_page_controller.dart` (sends metric_keys per monitor)
- Updated `lib/resources/views/status_pages/status_page_create_view.dart` (metric checkboxes)
- Updated `lib/resources/views/status_pages/status_page_edit_view.dart` (metric checkboxes + pre-selection)
- Backend feature tests
- Flutter widget tests

### Definition of Done
- [ ] `php artisan migrate` runs without errors
- [ ] `php artisan test --filter=StatusPageMonitorMetric` passes
- [ ] Public status page at `/status/{slug}` renders metric badges under monitors
- [ ] Public page without metrics renders normally (regression)
- [ ] Flutter create view shows metric checkboxes when monitor has mappings
- [ ] Flutter edit view pre-selects previously saved metrics
- [ ] `flutter test test/resources/views/status_pages/` passes
- [ ] `dart format .` passes

### Must Have
- Metric selection per-monitor via checkboxes in Flutter create/edit views
- All 3 metric types rendered on public page (numeric=value+unit, status=colored dot+text, string=plain text)
- Batch query for latest metric values (no N+1)
- Cache invalidation after admin metric changes
- Graceful handling of missing/null metric values ("N/A")
- `metric_mappings` included in `StatusPageResource` monitor data

### Must NOT Have (Guardrails)
- Drag-and-drop metric reorder UI (out of scope)
- Metric value history / sparkline charts on public page
- Custom threshold coloring for numeric metrics (e.g., "red if CPU > 90%")
- Custom metric labels per status page (use original label)
- Admin preview of public page within Flutter app
- Websocket / live metric updates on public page
- Metric display on Flutter status page index/list view
- Modifications to `monitor_metric_values` table schema
- Per-monitor queries inside Blade `@foreach` loops
- Any limit on number of metrics per monitor (user controls this)

---

## Verification Strategy

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks are verifiable by the executing agent using commands and tools.

### Test Decision
- **Infrastructure exists**: YES (both `php artisan test` and `flutter test` configured)
- **Automated tests**: TDD (project-wide TDD enforced)
- **Framework**: PHPUnit (backend) + Flutter test (frontend)

### Agent-Executed QA Scenarios (MANDATORY — ALL tasks)

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| **Backend migration** | Bash (php artisan) | Run migrate, check table exists |
| **Backend API** | Bash (curl) | Send requests, assert JSON fields |
| **Public page** | Playwright | Navigate, assert DOM elements |
| **Flutter UI** | flutter test | Widget tests with assertions |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately):
├── Task 1: Backend migration + model (no dependencies)
└── Task 4: Blade CSS for metric badges (no dependencies — CSS only)

Wave 2 (After Wave 1):
├── Task 2: Backend API changes (depends: 1)
├── Task 3: Public page controller + Blade rendering (depends: 1, 4)
└── Task 5: Flutter create/edit views + controller (depends: 2)

Wave 3 (After Wave 2):
└── Task 6: Integration tests + final verification (depends: 2, 3, 5)

Critical Path: Task 1 → Task 2 → Task 5
Parallel Speedup: ~30% faster than sequential
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | 2, 3 | 4 |
| 2 | 1 | 5, 6 | 3, 4 |
| 3 | 1, 4 | 6 | 2 |
| 4 | None | 3 | 1 |
| 5 | 2 | 6 | 3 |
| 6 | 2, 3, 5 | None | None (final) |

### Agent Dispatch Summary

| Wave | Tasks | Recommended Agents |
|------|-------|-------------------|
| 1 | 1, 4 | category="quick" (migration), category="quick" (CSS) |
| 2 | 2, 3, 5 | category="unspecified-high" (API), category="unspecified-high" (public page), category="visual-engineering" (Flutter UI) |
| 3 | 6 | category="unspecified-high" (integration tests) |

---

## TODOs

- [x] 1. Backend: Create `status_page_monitor_metrics` migration + model

  **What to do**:
  - Create migration `create_status_page_monitor_metrics_table` with:
    - `id` bigIncrements
    - `status_page_id` foreign → `status_pages.id` (cascadeOnDelete)
    - `monitor_id` foreign → `monitors.id` (cascadeOnDelete)
    - `metric_key` string(255) — the JSON path (e.g., `data.cpu`)
    - `display_order` integer default 0
    - `timestamps`
    - Unique composite index on `[status_page_id, monitor_id, metric_key]`
  - Create `StatusPageMonitorMetric` Eloquent model:
    - `$fillable`: `status_page_id`, `monitor_id`, `metric_key`, `display_order`
    - Relationships: `belongsTo(StatusPage)`, `belongsTo(Monitor)`
  - Add `selectedMetrics()` relationship to `StatusPage` model:
    - `hasMany(StatusPageMonitorMetric::class)`
  - RED: Write test that table exists after migration, model can create/query records
  - GREEN: Run migration, implement model
  - REFACTOR: Ensure index naming follows project conventions

  **Must NOT do**:
  - Do NOT modify `monitor_metric_values` table
  - Do NOT add columns to `status_page_monitor` pivot table
  - Do NOT add any JSON columns — this is a normalized table

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Standard migration + model creation, well-defined schema
  - **Skills**: [`git-master`]
    - `git-master`: Atomic commit after migration
  - **Skills Evaluated but Omitted**:
    - `frontend-ui-ux`: No UI work in this task

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Task 4)
  - **Blocks**: Tasks 2, 3
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `back-end/database/migrations/2026_02_06_175454_create_status_pages_table.php` — Follow same migration pattern for foreign keys and cascades; this migration also creates the `status_page_monitor` pivot table which is the sibling structure
  - `back-end/app/Models/StatusPage.php` — Follow same model pattern (fillable, relationships, scopes)

  **API/Type References**:
  - `back-end/app/Models/MonitorMetricValue.php` — The `metric_key` column type and usage pattern; this is what `metric_key` in our new table references conceptually

  **Test References**:
  - `back-end/tests/Feature/` — Follow existing feature test patterns for migration verification

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file created: `back-end/tests/Feature/StatusPageMonitorMetricTest.php`
  - [ ] Test covers: migration creates table with correct columns and indexes
  - [ ] Test covers: model can create, read, and delete records
  - [ ] Test covers: cascade delete when status page is deleted
  - [ ] Test covers: cascade delete when monitor is deleted
  - [ ] Test covers: unique constraint prevents duplicate (status_page_id, monitor_id, metric_key)
  - [ ] `php artisan test --filter=StatusPageMonitorMetricTest` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Migration creates table with correct schema
    Tool: Bash (php artisan)
    Preconditions: Database accessible
    Steps:
      1. Run: php artisan migrate
      2. Run: php artisan tinker --execute="Schema::hasTable('status_page_monitor_metrics')"
      3. Assert: Returns true
      4. Run: php artisan tinker --execute="Schema::getColumnListing('status_page_monitor_metrics')"
      5. Assert: Contains ['id', 'status_page_id', 'monitor_id', 'metric_key', 'display_order', 'created_at', 'updated_at']
    Expected Result: Table exists with all columns
    Evidence: Command output captured

  Scenario: Unique constraint prevents duplicates
    Tool: Bash (php artisan tinker)
    Preconditions: Migration applied, at least one status page and monitor exist
    Steps:
      1. Create first record: StatusPageMonitorMetric::create([...])
      2. Assert: Record created successfully
      3. Try creating duplicate with same (status_page_id, monitor_id, metric_key)
      4. Assert: QueryException thrown with unique constraint violation
    Expected Result: Duplicate prevented
    Evidence: Exception output captured
  ```

  **Commit**: YES
  - Message: `feat(status-page): add status_page_monitor_metrics migration and model`
  - Files: `back-end/database/migrations/*_create_status_page_monitor_metrics_table.php`, `back-end/app/Models/StatusPageMonitorMetric.php`, `back-end/app/Models/StatusPage.php`, `back-end/tests/Feature/StatusPageMonitorMetricTest.php`
  - Pre-commit: `php artisan test --filter=StatusPageMonitorMetricTest`

---

- [x] 2. Backend: Update API to accept and return metric selections

  **What to do**:
  - Update `StatusPageController::attachMonitors()` to:
    - Accept optional `metric_keys` array per monitor in the request: `monitors.*.metric_keys` (array of strings)
    - Validate `metric_keys.*` are strings (max 255)
    - After syncing the pivot, sync `status_page_monitor_metrics` table:
      - Delete existing records for this (status_page_id, monitor_id)
      - Insert new records for each metric_key in the array
      - Wrap in `DB::transaction()`
    - Add `Cache::forget("status_page_{$statusPage->slug}")` after successful sync
  - Update `StatusPageController::detachMonitor()` to:
    - Also delete related `status_page_monitor_metrics` records (cascade handles this, but verify)
  - Update `StatusPageResource` to include:
    - `metric_mappings` from each monitor (add to the select query: `monitors.metric_mappings`)
    - `selected_metrics` array per monitor (the metric_keys currently selected for this status page)
  - Update the monitor eager loading in `index()` and `show()`:
    - Add `monitors.metric_mappings` to the select list
  - RED: Write tests for new API behavior
  - GREEN: Implement the changes
  - REFACTOR: Ensure transaction rollback on failure

  **Must NOT do**:
  - Do NOT create a separate endpoint for metrics — keep it in `attachMonitors`
  - Do NOT modify the `status_page_monitor` pivot table structure
  - Do NOT change the existing `monitors.*.display_order` or `monitors.*.custom_label` behavior

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Multi-file backend changes with validation, transactions, resource updates
  - **Skills**: [`git-master`]
    - `git-master`: Atomic commit after API changes
  - **Skills Evaluated but Omitted**:
    - `frontend-ui-ux`: No UI work
    - `playwright`: No browser testing needed for API

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Task 3)
  - **Parallel Group**: Wave 2
  - **Blocks**: Tasks 5, 6
  - **Blocked By**: Task 1

  **References**:

  **Pattern References**:
  - `back-end/app/Http/Controllers/Api/V1/StatusPageController.php:134-169` — Current `attachMonitors()` method showing `syncWithoutDetaching` pattern and validation; ADD metric_keys validation and DB::transaction wrapping here
  - `back-end/app/Http/Controllers/Api/V1/StatusPageController.php:36-37` — Current monitor eager loading query; ADD `monitors.metric_mappings` to the select

  **API/Type References**:
  - `back-end/app/Http/Resources/Api/V1/StatusPageResource.php` — Current resource transformation; ADD `metric_mappings` and `selected_metrics` to monitor map
  - `back-end/app/Models/StatusPageMonitorMetric.php` — The model created in Task 1 for querying selected metrics

  **Test References**:
  - `back-end/tests/Feature/StatusPageMonitorMetricTest.php` — Extend or create sibling test for API behavior

  **WHY Each Reference Matters**:
  - `attachMonitors()` is the single point where metric selections must be persisted — understanding its current `syncWithoutDetaching` pattern is critical to not breaking existing monitor attachment
  - `StatusPageResource` determines what Flutter sees — without adding `metric_mappings` + `selected_metrics`, the edit view cannot show available/selected metrics

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test: `attachMonitors` with `metric_keys` per monitor stores records in `status_page_monitor_metrics`
  - [ ] Test: `attachMonitors` without `metric_keys` works as before (backward compatible)
  - [ ] Test: `attachMonitors` replaces previous metric selections (not appends)
  - [ ] Test: `detachMonitor` cleans up metric records (via cascade)
  - [ ] Test: `StatusPageResource` includes `metric_mappings` and `selected_metrics` per monitor
  - [ ] Test: Cache is invalidated after `attachMonitors`
  - [ ] `php artisan test --filter=StatusPage` → PASS (all status page tests)

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Attach monitors with metric selections
    Tool: Bash (curl)
    Preconditions: Server running, status page exists with ID 1, monitor with metric_mappings exists with ID 1
    Steps:
      1. POST /api/v1/status-pages/1/monitors with body:
         {"monitors":[{"monitor_id":1,"display_order":0,"custom_label":null,"metric_keys":["data.cpu","data.memory"]}]}
      2. Assert: HTTP status 200
      3. Assert: response.data.monitors[0].selected_metrics contains ["data.cpu","data.memory"]
      4. GET /api/v1/status-pages/1
      5. Assert: response.data.monitors[0].metric_mappings is array (not null)
      6. Assert: response.data.monitors[0].selected_metrics is array with 2 items
    Expected Result: Metrics stored and returned in API
    Evidence: Response bodies captured

  Scenario: Attach monitors without metric_keys (backward compatible)
    Tool: Bash (curl)
    Preconditions: Server running, status page exists
    Steps:
      1. POST /api/v1/status-pages/1/monitors with body:
         {"monitors":[{"monitor_id":1,"display_order":0,"custom_label":"My API"}]}
      2. Assert: HTTP status 200
      3. Assert: response.data.monitors[0].selected_metrics is empty array []
    Expected Result: No metrics selected, no errors
    Evidence: Response body captured
  ```

  **Commit**: YES
  - Message: `feat(status-page): accept and return metric selections in API`
  - Files: `back-end/app/Http/Controllers/Api/V1/StatusPageController.php`, `back-end/app/Http/Resources/Api/V1/StatusPageResource.php`, `back-end/tests/Feature/StatusPageApiMetricsTest.php`
  - Pre-commit: `php artisan test --filter=StatusPage`

---

- [x] 3. Backend: Render metric badges on public status page

  **What to do**:
  - Update `PublicStatusPageController::show()`:
    - After building `$uptimeData`, fetch selected metrics for all monitors on this page
    - Query `status_page_monitor_metrics` to get all selected metric_keys grouped by monitor_id
    - Batch-fetch latest metric values from `monitor_metric_values` using a single optimized query:
      ```sql
      SELECT DISTINCT ON (monitor_id, metric_key) 
        monitor_id, metric_key, metric_label, numeric_value, string_value, status_value, unit, recorded_at
      FROM monitor_metric_values
      WHERE monitor_id IN (...) AND metric_key IN (...)
      ORDER BY monitor_id, metric_key, recorded_at DESC
      ```
    - Build `$metricsData[$monitorId]` array with the fetched values
    - Also fetch `metric_mappings` from monitors to get type info: `$monitor->metric_mappings`
    - Update the monitor eager loading to include `monitors.metric_mappings`
    - Pass `$metricsData` to the Blade view via `compact()`
  - Update `show.blade.php`:
    - Between `.uptime-chart` and `.monitor-meta`, add a metrics section:
      ```blade
      @if(!empty($metricsData[$monitor->id]))
        <div class="metrics-grid">
          @foreach($metricsData[$monitor->id] as $metric)
            <div class="metric-badge metric-{{ $metric['type'] }}">
              <span class="metric-label">{{ $metric['label'] }}</span>
              <span class="metric-value">{{ $metric['display_value'] }}</span>
            </div>
          @endforeach
        </div>
      @endif
      ```
    - Render by type:
      - `numeric`: Show `{value}{unit}` (e.g., "42.5 MB")
      - `status`: Show colored dot + text (UP/DOWN/UNKNOWN), reuse `.status-badge` pattern
      - `string`: Show plain text value
    - Handle null/missing values: Show "N/A" with muted styling
  - RED: Write test for public page rendering with metrics
  - GREEN: Implement controller + Blade changes
  - REFACTOR: Ensure no N+1 queries

  **Must NOT do**:
  - Do NOT query metric values inside `@foreach` loops — ALL pre-fetched in controller
  - Do NOT add charts or sparklines — latest value only
  - Do NOT modify `monitor_metric_values` table

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Backend controller query optimization + Blade template changes + CSS styling
  - **Skills**: [`playwright`]
    - `playwright`: For verifying public page renders correctly with metrics
  - **Skills Evaluated but Omitted**:
    - `frontend-ui-ux`: This is Blade/CSS, not Flutter Wind UI

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Task 2)
  - **Parallel Group**: Wave 2
  - **Blocks**: Task 6
  - **Blocked By**: Tasks 1, 4

  **References**:

  **Pattern References**:
  - `back-end/app/Http/Controllers/PublicStatusPageController.php:14-82` — CRITICAL: Current `show()` method with Cache::remember, monitor eager loading, `$uptimeData` building pattern. The metric fetching MUST follow this exact pattern (fetch inside cache closure, single batch query, build associative array keyed by monitor_id)
  - `back-end/resources/views/status-page/show.blade.php:26-62` — Current monitor card structure. Metrics go between line 42 (end of `.uptime-chart`) and line 44 (start of `.monitor-meta`)
  - `back-end/app/Http/Controllers/Api/V1/MonitorMetricController.php` — How the admin API fetches latest status metrics using `MAX(id)` grouping. Public page should use similar but batch approach with `DISTINCT ON`

  **API/Type References**:
  - `back-end/app/Models/MonitorMetricValue.php` — Table columns: `monitor_id`, `metric_key`, `metric_label`, `numeric_value`, `string_value`, `status_value`, `unit`, `recorded_at`
  - `back-end/app/Models/StatusPageMonitorMetric.php` — (Task 1) Query selected metric_keys per monitor per status page

  **Documentation References**:
  - `back-end/resources/views/layouts/status-page.blade.php:10-250` — CSS variable system (`--success`, `--warning`, `--error`, `--neutral`). New `.metric-badge` styles MUST use these CSS variables, not hardcoded colors

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test: Public page with metrics renders badge HTML
  - [ ] Test: Public page without metrics renders normally (no `.metrics-grid`)
  - [ ] Test: Numeric metric shows value + unit
  - [ ] Test: Status metric shows colored dot + text
  - [ ] Test: Missing metric value shows "N/A"
  - [ ] `php artisan test --filter=PublicStatusPage` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Public page renders metric badges for monitors with selected metrics
    Tool: Playwright (playwright skill)
    Preconditions: Server running, published status page with slug "test-metrics", monitors with metric_mappings and recent metric values
    Steps:
      1. Navigate to: http://localhost:8000/status/test-metrics
      2. Wait for: .card visible (timeout: 10s)
      3. Assert: .metrics-grid exists inside first .card
      4. Assert: .metric-badge elements count > 0
      5. Assert: .metric-label text is not empty
      6. Assert: .metric-value text is not empty
      7. Screenshot: .sisyphus/evidence/task-3-public-metrics.png
    Expected Result: Metric badges visible under monitor cards
    Evidence: .sisyphus/evidence/task-3-public-metrics.png

  Scenario: Public page without metrics shows no metrics section
    Tool: Playwright (playwright skill)
    Preconditions: Published status page with no metric selections
    Steps:
      1. Navigate to: http://localhost:8000/status/no-metrics-slug
      2. Wait for: .card visible (timeout: 10s)
      3. Assert: .metrics-grid does NOT exist
      4. Assert: .monitor-meta still exists (uptime + response time)
      5. Screenshot: .sisyphus/evidence/task-3-no-metrics.png
    Expected Result: Normal render without metrics section
    Evidence: .sisyphus/evidence/task-3-no-metrics.png

  Scenario: Null metric value displays N/A
    Tool: Playwright (playwright skill)
    Preconditions: Monitor has selected metric but no recorded values yet
    Steps:
      1. Navigate to published status page
      2. Assert: .metric-value text contains "N/A" for that metric
      3. Assert: .metric-badge has .metric-na class (muted styling)
    Expected Result: Graceful fallback for missing data
    Evidence: Screenshot captured
  ```

  **Commit**: YES
  - Message: `feat(status-page): render custom metric badges on public page`
  - Files: `back-end/app/Http/Controllers/PublicStatusPageController.php`, `back-end/resources/views/status-page/show.blade.php`, `back-end/tests/Feature/PublicStatusPageMetricsTest.php`
  - Pre-commit: `php artisan test --filter=PublicStatusPage`

---

- [x] 4. Backend: Add metric badge CSS to status page layout

  **What to do**:
  - Add CSS to `back-end/resources/views/layouts/status-page.blade.php` `<style>` block:
    - `.metrics-grid`: Flex wrap layout with gap, margin-top between uptime chart and meta
    - `.metric-badge`: Inline-flex, pill-shaped, small font, border, padding
    - `.metric-label`: Uppercase, tiny, muted color (like `.text-muted`)
    - `.metric-value`: Bold, slightly larger, main color
    - `.metric-numeric .metric-value`: Default text-main color
    - `.metric-status.metric-up .metric-value`: `var(--success)` color + small dot
    - `.metric-status.metric-down .metric-value`: `var(--error)` color + small dot
    - `.metric-status.metric-unknown .metric-value`: `var(--neutral)` color
    - `.metric-string .metric-value`: Default text-main color
    - `.metric-na .metric-value`: `var(--text-muted)` color, italic
  - Follow existing CSS variable system (`--success`, `--warning`, `--error`, `--neutral`, `--text-main`, `--text-muted`)
  - Ensure dark mode works via the existing `@media (prefers-color-scheme: dark)` block
  - Keep responsive — badges should wrap on mobile

  **Must NOT do**:
  - Do NOT use Tailwind classes — public page uses vanilla CSS with CSS variables
  - Do NOT import external CSS frameworks
  - Do NOT change existing styles

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: CSS-only addition, well-defined scope, follows existing patterns
  - **Skills**: [`frontend-ui-ux`]
    - `frontend-ui-ux`: CSS design quality

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Task 1)
  - **Blocks**: Task 3
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `back-end/resources/views/layouts/status-page.blade.php:10-250` — CRITICAL: The entire existing CSS structure. Follow CSS variable usage (`var(--success)`, `var(--text-muted)`, etc.), follow `.status-badge` pattern (lines 85-128) for pill styling, follow card pattern for consistent spacing. Dark mode uses `@media (prefers-color-scheme: dark)` at lines 24-33

  **WHY Each Reference Matters**:
  - The layout file is the ONLY place CSS lives for public pages — no external CSS, no Tailwind. All new styles must be added here following exact conventions

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: CSS classes exist in layout
    Tool: Bash (grep)
    Steps:
      1. grep "metrics-grid" back-end/resources/views/layouts/status-page.blade.php
      2. Assert: Found
      3. grep "metric-badge" back-end/resources/views/layouts/status-page.blade.php
      4. Assert: Found
      5. grep "metric-numeric" back-end/resources/views/layouts/status-page.blade.php
      6. Assert: Found
      7. grep "metric-status" back-end/resources/views/layouts/status-page.blade.php
      8. Assert: Found
    Expected Result: All CSS classes present
    Evidence: Grep output captured
  ```

  **Commit**: YES (groups with Task 3)
  - Message: `style(status-page): add metric badge CSS to public page layout`
  - Files: `back-end/resources/views/layouts/status-page.blade.php`
  - Pre-commit: N/A (CSS only)

---

- [x] 5. Flutter: Add metric selection UI to status page create/edit views

  **What to do**:
  - Update `StatusPageController`:
    - Modify `store()` and `update()` to accept metric_keys per monitor
    - In `attachMonitors()` API call, include `metric_keys` array per monitor
    - When loading a status page for edit, parse `selected_metrics` from the API response
  - Update `StatusPageCreateView` (`_buildMonitorsSection()`):
    - When a monitor is added to `_selectedMonitors`, also store its `metric_mappings` from the Monitor model
    - Below the custom label `WInput`, add a metric selection area:
      - If monitor has `metric_mappings`, show a collapsible section "Custom Metrics"
      - Display each metric mapping as a `WCheckbox` with label: `"{mapping.label} ({mapping.path})"` and type badge
      - Store selected metric_keys in `_selectedMonitors[index]['metric_keys']` as `List<String>`
    - If monitor has no `metric_mappings`, show nothing (no empty section)
    - Update `_handleSubmit()` to include `metric_keys` in the monitors list
  - Update `StatusPageEditView`:
    - In `_populateForm()`, read `selected_metrics` from the API response per monitor
    - Pre-check the checkboxes for previously selected metrics
    - Ensure metric_mappings are available (they should be in the API response after Task 2)
  - Ensure `MonitorController.instance.loadMonitors()` includes metric_mappings in the response (check if the existing monitors endpoint already returns this field)
  - RED: Write widget tests for metric checkboxes appearing/disappearing
  - GREEN: Implement the UI changes
  - REFACTOR: Extract metric checkbox section into a small helper widget if needed

  **Must NOT do**:
  - Do NOT create a separate component file for metric checkboxes (inline in the view unless complex)
  - Do NOT add custom metric labels — use mapping.label directly
  - Do NOT add drag-and-drop reorder for metrics
  - Do NOT use `Container`/`Text`/`Row`/`Column` — Wind UI only
  - Do NOT use package imports within lib/ — relative imports

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
    - Reason: Flutter UI changes with Wind UI components, checkbox interactions, state management
  - **Skills**: [`frontend-ui-ux`]
    - `frontend-ui-ux`: Wind UI styling and interaction patterns
  - **Skills Evaluated but Omitted**:
    - `playwright`: This is Flutter widget testing, not browser
    - `git-master`: Standard commit, no complex git needed

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 2 (after Task 2)
  - **Blocks**: Task 6
  - **Blocked By**: Task 2

  **References**:

  **Pattern References**:
  - `lib/resources/views/status_pages/status_page_create_view.dart:464-580` — CRITICAL: Current `_selectedMonitors` list builder with `ReorderableListView`. Metric checkboxes go BELOW the custom label `WInput` (after line 549) and ABOVE the close button. Follow exact Wind UI className patterns for WDiv/WCheckbox
  - `lib/resources/views/status_pages/status_page_create_view.dart:650-662` — Monitor `onChange` handler where `_selectedMonitors.add(...)` happens. MUST also store `monitor.metricMappings` here
  - `lib/resources/views/status_pages/status_page_create_view.dart:79-103` — `_handleSubmit()` method. MUST include `metric_keys` in the monitors map
  - `lib/resources/views/components/metric_mapping_editor.dart` — Shows how metric mappings are displayed in the monitor form. Follow label + type display pattern but use WCheckbox instead of editable inputs

  **API/Type References**:
  - `lib/app/models/metric_mapping.dart` — MetricMapping model with `label`, `path`, `type`, `unit` fields. Use `MetricMapping.fromMap()` to parse mappings from monitor data
  - `lib/app/models/monitor.dart` — `metricMappings` getter returns `List<Map<String, dynamic>>?`. Parse each into `MetricMapping`
  - `lib/app/controllers/status_page_controller.dart` — `store()` and `attachMonitors()` methods; add `metric_keys` to the API payload

  **Documentation References**:
  - `.claude/rules/views.md` — Wind UI widget rules, dark mode requirements, styling recipes
  - `lib/resources/views/AGENTS.md` — View conventions, form patterns, scrolling

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test: Monitor with metric_mappings shows metric checkboxes
  - [ ] Test: Monitor without metric_mappings shows no metric section
  - [ ] Test: Checking a metric adds its key to `metric_keys` in state
  - [ ] Test: Unchecking removes it
  - [ ] Test: Form submission includes metric_keys per monitor
  - [ ] `flutter test test/resources/views/status_pages/` → PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Monitor with metrics shows checkboxes in create view
    Tool: flutter test (widget test)
    Preconditions: Test monitor with metric_mappings defined
    Steps:
      1. Pump StatusPageCreateView with mocked MonitorController providing monitors with metric_mappings
      2. Select a monitor from dropdown
      3. Assert: WCheckbox widgets appear for each metric mapping
      4. Assert: Labels show "CPU Usage (data.cpu)" format
      5. Tap checkbox for first metric
      6. Assert: _selectedMonitors[0]['metric_keys'] contains that metric_key
    Expected Result: Checkboxes functional
    Evidence: Test output

  Scenario: Monitor without metrics shows no metric section
    Tool: flutter test (widget test)
    Preconditions: Test monitor WITHOUT metric_mappings
    Steps:
      1. Pump StatusPageCreateView
      2. Select a monitor with empty metric_mappings
      3. Assert: No WCheckbox widgets for metrics
      4. Assert: Custom label input still exists
    Expected Result: Clean UI without metrics area
    Evidence: Test output

  Scenario: Edit view pre-selects saved metrics
    Tool: flutter test (widget test)
    Preconditions: Status page with monitor that has 2 selected_metrics
    Steps:
      1. Pump StatusPageEditView with mocked API response including selected_metrics
      2. Wait for form population
      3. Assert: 2 checkboxes are checked
      4. Assert: Others are unchecked
    Expected Result: Previous selections restored
    Evidence: Test output
  ```

  **Evidence to Capture:**
  - [ ] Test output for `flutter test test/resources/views/status_pages/`
  - [ ] `dart format .` passes

  **Commit**: YES
  - Message: `feat(status-page): add metric selection checkboxes to create/edit views`
  - Files: `lib/resources/views/status_pages/status_page_create_view.dart`, `lib/resources/views/status_pages/status_page_edit_view.dart`, `lib/app/controllers/status_page_controller.dart`, `test/resources/views/status_pages/`
  - Pre-commit: `flutter test test/resources/views/status_pages/ && dart format .`

---

- [x] 6. Integration: End-to-end verification and final tests

  **What to do**:
  - Write a backend feature test that exercises the full flow:
    1. Create a status page
    2. Attach a monitor with metric_keys
    3. Verify metric selections are stored in `status_page_monitor_metrics`
    4. Hit the public page URL and verify metric badges render
    5. Remove a monitor and verify cascade cleanup
  - Write a Flutter integration test (if applicable) or extend widget tests:
    1. Verify that metric selections round-trip through create → API → edit (pre-select)
  - Verify regression: existing status pages without metrics still render correctly
  - Verify the full public page with and without metrics via Playwright
  - Run full test suites: `php artisan test` and `flutter test`
  - Run `dart format .` and `flutter analyze --no-fatal-infos`

  **Must NOT do**:
  - Do NOT modify any feature code in this task — testing and verification only
  - Do NOT skip backend tests
  - Do NOT skip Flutter tests

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Multi-layer integration testing, full verification sweep
  - **Skills**: [`playwright`]
    - `playwright`: Public page visual verification
  - **Skills Evaluated but Omitted**:
    - `frontend-ui-ux`: No UI changes, just testing

  **Parallelization**:
  - **Can Run In Parallel**: NO (final task)
  - **Parallel Group**: Wave 3
  - **Blocks**: None
  - **Blocked By**: Tasks 2, 3, 5

  **References**:

  **Pattern References**:
  - All files modified in Tasks 1-5

  **Acceptance Criteria**:

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Full roundtrip — create status page with metrics, verify public page
    Tool: Bash (curl) + Playwright
    Steps:
      1. Create status page via API
      2. Attach monitor with metric_keys via API
      3. Assert: API response includes selected_metrics
      4. Toggle publish via API
      5. Navigate to public page with Playwright
      6. Assert: Metric badges visible
      7. Screenshot: .sisyphus/evidence/task-6-full-roundtrip.png
    Expected Result: Metrics flow from admin to public page
    Evidence: .sisyphus/evidence/task-6-full-roundtrip.png

  Scenario: Regression — existing status pages without metrics
    Tool: Playwright
    Steps:
      1. Navigate to existing published status page (no metric selections)
      2. Assert: Page loads normally (200)
      3. Assert: .metrics-grid does NOT exist
      4. Assert: .uptime-chart exists
      5. Assert: .monitor-meta exists
    Expected Result: No regression
    Evidence: Screenshot captured

  Scenario: All tests pass
    Tool: Bash
    Steps:
      1. Run: php artisan test → Assert: 0 failures
      2. Run: flutter test → Assert: 0 failures
      3. Run: dart format . --set-exit-if-changed → Assert: exit 0
      4. Run: flutter analyze --no-fatal-infos → Assert: no errors
    Expected Result: Clean test run
    Evidence: Command outputs captured
  ```

  **Commit**: YES
  - Message: `test(status-page): add integration tests for custom metrics feature`
  - Files: `back-end/tests/Feature/StatusPageMetricsIntegrationTest.php`, any additional test files
  - Pre-commit: `php artisan test && flutter test`

---

## Commit Strategy

| After Task | Message | Files | Verification |
|------------|---------|-------|--------------|
| 1 | `feat(status-page): add status_page_monitor_metrics migration and model` | migration, model, StatusPage.php, tests | `php artisan test --filter=StatusPageMonitorMetric` |
| 2 | `feat(status-page): accept and return metric selections in API` | controller, resource, tests | `php artisan test --filter=StatusPage` |
| 3+4 | `feat(status-page): render custom metric badges on public page` | public controller, show.blade, layout.blade, tests | `php artisan test --filter=PublicStatusPage` |
| 5 | `feat(status-page): add metric selection checkboxes to create/edit views` | views, controller, tests | `flutter test test/resources/views/status_pages/` |
| 6 | `test(status-page): add integration tests for custom metrics feature` | integration tests | `php artisan test && flutter test` |

---

## Success Criteria

### Verification Commands
```bash
# Backend
php artisan migrate                                      # Expected: Migrated successfully
php artisan test --filter=StatusPageMonitorMetric         # Expected: All pass
php artisan test --filter=StatusPage                      # Expected: All pass
php artisan test --filter=PublicStatusPage                # Expected: All pass

# Flutter
flutter test test/resources/views/status_pages/          # Expected: All pass
dart format . --set-exit-if-changed                       # Expected: exit 0
flutter analyze --no-fatal-infos                         # Expected: No errors

# Full regression
php artisan test                                          # Expected: 0 failures
flutter test                                              # Expected: 0 failures
```

### Final Checklist
- [ ] `status_page_monitor_metrics` table exists with correct schema
- [ ] API accepts `metric_keys` per monitor in `attachMonitors`
- [ ] API returns `metric_mappings` and `selected_metrics` per monitor
- [ ] Public page renders typed metric badges (numeric/status/string)
- [ ] Public page handles null/missing metrics gracefully ("N/A")
- [ ] Cache invalidated after metric selection changes
- [ ] Flutter create view shows metric checkboxes
- [ ] Flutter edit view pre-selects saved metrics
- [ ] Monitors without metric_mappings show no metric section
- [ ] All existing tests still pass (regression)
- [ ] No N+1 queries on public page
- [ ] All "Must NOT Have" items are absent
