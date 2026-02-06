# Status Page Feature

## TL;DR

> **Quick Summary**: Team-based public status pages with subdomain routing (`{slug}.uptizm.com`). Flutter app manages CRUD, Laravel renders public Blade pages with monitor statuses, 90-day uptime bar charts, and response times.
> 
> **Deliverables**:
> - Laravel: Migration, Model, Policy, FormRequests, API Controller, Public Blade Controller, Blade template
> - Flutter: StatusPage model, StatusPageController, 3 views (index/create/edit), routes
> - TDD tests for both sides
> 
> **Estimated Effort**: Large
> **Parallel Execution**: YES - 2 waves (Backend first, then Flutter)
> **Critical Path**: Migration → Model → Policy → API Controller → Blade Controller → Flutter Model → Flutter Controller → Flutter Views

---

## Context

### Original Request
Users can create public status pages per team. Each status page has a subdomain (`acme.uptizm.com`), configurable branding (title, description, logo, color, favicon), and displays selected monitors with their current status, response times, and 90-day uptime history.

### Interview Summary
**Key Discussions**:
- Monitor selection: User picks which monitors appear (not all team monitors)
- No monitor grouping — flat list with display order
- 90-day uptime bar chart per monitor (daily uptime %, pure CSS)
- No incident management — automatic monitor status only
- Subdomain routing only (`{slug}.uptizm.com`), no custom domains
- Multiple status pages per team
- Always public — no password protection
- Configurable: title, description, logo URL, primary color, favicon URL
- Response time: last + average response time per monitor
- TDD for both Flutter and Laravel

### Metis Review
**Identified Gaps** (addressed):
- `monitor_checks_daily` materialized view already exists with pre-aggregated uptime data (730-day retention) — use this instead of raw `monitor_checks` (90-day retention)
- No Vite/Tailwind build pipeline for Blade — use inline CSS, zero JS
- Local dev needs path-based fallback route (`GET /status/{slug}`) since subdomain routing doesn't work on localhost
- Slug validation needs reserved word list (`api`, `admin`, `www`, `app`, `mail`, `status`, `dashboard`)
- 5-minute cache on public page to prevent expensive queries
- Overall status algorithm: all_up=Operational, any_degraded=Degraded, any_down=Partial Outage, all_down=Major Outage

---

## Work Objectives

### Core Objective
Build a complete status page system: Laravel backend (API + public Blade rendering) and Flutter frontend (CRUD management).

### Concrete Deliverables
**Laravel**:
- `database/migrations/xxxx_create_status_pages_table.php` — status_pages + status_page_monitor pivot
- `app/Models/StatusPage.php` — Eloquent model with team/monitors relations
- `app/Policies/StatusPagePolicy.php` — Role-based authorization
- `app/Http/Requests/Api/V1/StoreStatusPageRequest.php` — Create validation
- `app/Http/Requests/Api/V1/UpdateStatusPageRequest.php` — Update validation
- `app/Http/Resources/Api/V1/StatusPageResource.php` — API resource
- `app/Http/Controllers/Api/V1/StatusPageController.php` — CRUD API
- `app/Http/Controllers/PublicStatusPageController.php` — Public Blade rendering
- `resources/views/status-page/show.blade.php` — Public status page template
- `resources/views/layouts/status-page.blade.php` — Status page layout

**Flutter**:
- `lib/app/models/status_page.dart` — StatusPage model
- `lib/app/controllers/status_page_controller.dart` — Singleton controller
- `lib/resources/views/status_pages/status_pages_index_view.dart` — List view
- `lib/resources/views/status_pages/status_page_create_view.dart` — Create form
- `lib/resources/views/status_pages/status_page_edit_view.dart` — Edit form
- Updated routes in `lib/routes/app.dart`

**Tests**:
- `tests/Feature/StatusPageApiTest.php` — Laravel API tests
- `tests/Feature/PublicStatusPageTest.php` — Public page tests
- `test/app/models/status_page_test.dart` — Flutter model tests
- `test/app/controllers/status_page_controller_test.dart` — Flutter controller tests

### Definition of Done
- [ ] `php artisan migrate` runs without error
- [ ] `php artisan test --filter=StatusPage` — all pass
- [ ] `flutter test test/app/models/status_page_test.dart` — all pass
- [ ] `flutter test test/app/controllers/status_page_controller_test.dart` — all pass
- [ ] `flutter analyze --no-fatal-infos` — no issues
- [ ] `dart format .` — no changes needed
- [ ] Public page returns 200 for valid slug, 404 for invalid
- [ ] CRUD API works with proper auth

### Must Have
- Subdomain routing with path-based fallback for local dev
- 90-day uptime bar chart (pure CSS, no JS)
- Monitor selection (pick which monitors appear)
- Branding customization (title, description, logo, color, favicon)
- Response time display (last + average)
- Overall status computation
- 5-minute cache on public page
- Slug validation with reserved words
- TDD tests

### Must NOT Have (Guardrails)
- NO JavaScript on public Blade page — zero JS, pure HTML/CSS
- NO Vite/Tailwind build pipeline — inline styles or single CSS file
- NO Livewire/Inertia/Alpine.js
- NO incident management, subscriber emails, historical incident timeline
- NO custom domain support, CNAME validation, SSL provisioning
- NO monitor grouping/sections
- NO password protection
- NO analytics/tracking on public page
- NO Flutter "show/preview" view — link opens external browser
- NO modification to Team model/table (slug goes on status_pages)
- NO `getAttribute/setAttribute` in Flutter model — use `get<T>/set` per AGENTS.md
- NO querying raw `monitor_checks` for 90-day data — use `monitor_checks_daily` materialized view (730-day retention)
- NO over-engineering the Blade page (keep it a simple single-page HTML)

---

## Verification Strategy

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
> ALL verification is agent-executed using tools.

### Test Decision
- **Infrastructure exists**: YES (both Flutter `flutter test` and Laravel `php artisan test`)
- **Automated tests**: TDD (RED-GREEN-REFACTOR)
- **Frameworks**: PHPUnit (Laravel), flutter_test (Flutter)

### TDD Workflow Per Task
1. **RED**: Write failing test first → run → confirm FAIL
2. **GREEN**: Implement minimum code to pass → run → confirm PASS
3. **REFACTOR**: Clean up while keeping green → run → confirm PASS

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 — Laravel Backend (Sequential within wave):
├── Task 1: Migration + Model + Factory
├── Task 2: Policy + Form Requests + Resource  
├── Task 3: API Controller (CRUD endpoints)
├── Task 4: Public Blade Controller + Template
└── Task 5: API routes + web routes

Wave 2 — Flutter Frontend (Sequential within wave, parallel with nothing — depends on Wave 1):
├── Task 6: StatusPage model + tests
├── Task 7: StatusPageController + tests
├── Task 8: Views (index/create/edit) + route updates
└── Task 9: Final integration QA
```

### Dependency Matrix

| Task | Depends On | Blocks |
|------|------------|--------|
| 1 | None | 2, 3, 4 |
| 2 | 1 | 3, 4 |
| 3 | 1, 2 | 5 |
| 4 | 1, 2 | 5 |
| 5 | 3, 4 | 6 |
| 6 | 5 | 7 |
| 7 | 6 | 8 |
| 8 | 7 | 9 |
| 9 | 8 | None |

---

## TODOs

- [ ] 1. Laravel: Migration + StatusPage Model + Factory

  **What to do**:

  *TDD — RED*: Write model test first:
  - `tests/Feature/StatusPageModelTest.php`: Test StatusPage has correct fillable fields, casts, relationships (belongsTo Team, belongsToMany Monitor via pivot with `display_order` + `custom_label`), scopes (`published()`, `forTeam()`), and slug uniqueness.
  - Run `php artisan test --filter=StatusPageModelTest` → FAIL (table/model don't exist)

  *TDD — GREEN*: Create migration + model + factory:
  - Migration `create_status_pages_table`:
    ```
    status_pages table:
      id (bigIncrements)
      team_id (foreignId → teams, cascadeOnDelete)
      name (string, 100)
      slug (string, 63, unique) — subdomain-compatible
      description (text, nullable)
      logo_url (string, 2048, nullable)
      favicon_url (string, 2048, nullable)
      primary_color (string, 7, default '#009E60') — hex color from brand.md
      is_published (boolean, default false)
      timestamps
      index on team_id
      index on [team_id, is_published]
    
    status_page_monitor pivot table:
      id (bigIncrements)
      status_page_id (foreignId → status_pages, cascadeOnDelete)
      monitor_id (foreignId → monitors, cascadeOnDelete)
      display_order (integer, default 0)
      custom_label (string, 255, nullable)
      unique constraint on [status_page_id, monitor_id]
      index on status_page_id
    ```
  - Model `app/Models/StatusPage.php`:
    - `$fillable`: name, slug, description, logo_url, favicon_url, primary_color, is_published
    - `$casts`: is_published → boolean
    - Relations: `team()` → belongsTo(Team), `monitors()` → belongsToMany(Monitor, 'status_page_monitor')->withPivot('display_order', 'custom_label')->orderByPivot('display_order')
    - Scopes: `scopePublished($q)` → where('is_published', true), `scopeForTeam($q, $teamId)` → where('team_id', $teamId)
    - Add `statusPages()` hasMany relation to Team model
  - Factory `database/factories/StatusPageFactory.php` with reasonable defaults

  *TDD — REFACTOR*: Run `php artisan test --filter=StatusPageModelTest` → PASS, clean up

  **Must NOT do**:
  - Do NOT add slug to teams table
  - Do NOT add any fields beyond what's listed
  - Do NOT create a separate migration for the pivot (include in same migration file)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`git-master`]
    - `git-master`: For atomic commits after task completion

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 1 — Sequential start
  - **Blocks**: Tasks 2, 3, 4
  - **Blocked By**: None

  **References**:
  - `back-end/database/migrations/2026_02_02_122236_create_monitors_table.php` — Migration pattern (foreignId, indexes, json columns)
  - `back-end/app/Models/Monitor.php` — Model pattern (fillable, casts, relationships, scopes)
  - `back-end/app/Models/Team.php` — Team model to add `statusPages()` relation
  - `back-end/database/factories/MonitorFactory.php` — Factory pattern
  - `back-end/database/migrations/2025_12_29_222749_create_team_user_table.php` — Pivot table migration pattern

  **Acceptance Criteria**:
  - [ ] `php artisan migrate:fresh` runs without error
  - [ ] `php artisan test --filter=StatusPageModelTest` → all PASS
  - [ ] StatusPage::factory()->create() produces valid record
  - [ ] StatusPage belongs to Team, Team hasMany StatusPages
  - [ ] Monitor belongsToMany via pivot with display_order and custom_label

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Migration creates tables correctly
    Tool: Bash (php artisan)
    Steps:
      1. cd back-end && php artisan migrate:fresh --force
      2. php artisan tinker --execute="echo Schema::hasTable('status_pages') ? 'YES' : 'NO'"
      3. Assert output contains "YES"
      4. php artisan tinker --execute="echo Schema::hasTable('status_page_monitor') ? 'YES' : 'NO'"
      5. Assert output contains "YES"
    Expected Result: Both tables exist
    
  Scenario: Model relationships work
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create team via factory
      2. Create status page for team via factory
      3. Assert statusPage->team->id equals team->id
      4. Attach monitor to status page with pivot data
      5. Assert statusPage->monitors->count() equals 1
    Expected Result: All relationships resolve correctly
  ```

  **Commit**: YES
  - Message: `feat(status-page): add migration, model, and factory for status pages`
  - Files: `database/migrations/*, app/Models/StatusPage.php, database/factories/StatusPageFactory.php, app/Models/Team.php, tests/Feature/StatusPageModelTest.php`
  - Pre-commit: `php artisan test --filter=StatusPageModelTest`

---

- [ ] 2. Laravel: Policy + Form Requests + API Resource

  **What to do**:

  *TDD — RED*: Write policy + validation tests:
  - `tests/Feature/StatusPagePolicyTest.php`: Test that owner/admin/editor can create/update, owner/admin can delete, team members can view, non-members cannot access.
  - `tests/Feature/StatusPageValidationTest.php`: Test slug format validation (lowercase alphanumeric + hyphens), slug uniqueness, reserved words rejection, required fields, primary_color hex format.
  - Run tests → FAIL

  *TDD — GREEN*:
  - `app/Policies/StatusPagePolicy.php`:
    - `view(User, StatusPage)` → user is team member
    - `create(User, Team)` → owner/admin/editor
    - `update(User, StatusPage)` → owner/admin/editor
    - `delete(User, StatusPage)` → owner/admin
    - Private `hasTeamRole()` helper (follow MonitorPolicy pattern)
  - Register policy in `AppServiceProvider.boot()` via `Gate::define()`
  - `app/Http/Requests/Api/V1/StoreStatusPageRequest.php`:
    - name: required, string, max:100
    - slug: required, string, max:63, regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/, unique:status_pages,slug, not_in:api,admin,www,app,mail,status,dashboard,support,help,docs,blog
    - description: nullable, string, max:1000
    - logo_url: nullable, url, max:2048
    - favicon_url: nullable, url, max:2048
    - primary_color: nullable, string, regex:/^#[0-9a-fA-F]{6}$/
    - monitor_ids: nullable, array
    - monitor_ids.*: integer, exists:monitors,id
  - `app/Http/Requests/Api/V1/UpdateStatusPageRequest.php`:
    - Same as Store but all fields use `sometimes` rule
    - slug unique rule ignores current record
  - `app/Http/Resources/Api/V1/StatusPageResource.php`:
    - Return: id, team_id, name, slug, description, logo_url, favicon_url, primary_color, is_published, monitors (collection with pivot), public_url (`{slug}.uptizm.com`), created_at, updated_at

  *TDD — REFACTOR*: Run all tests → PASS

  **Must NOT do**:
  - Do NOT use `extends MagicPolicy` — use plain policy class following MonitorPolicy
  - Do NOT skip reserved words validation
  - Do NOT expose sensitive monitor data (auth_config, headers, body) in StatusPageResource

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`git-master`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 1 — after Task 1
  - **Blocks**: Tasks 3, 4
  - **Blocked By**: Task 1

  **References**:
  - `back-end/app/Policies/MonitorPolicy.php` — Policy pattern with `hasTeamRole()` helper
  - `back-end/app/Http/Requests/Api/V1/StoreMonitorRequest.php` — Form request pattern (rules, messages, authorize)
  - `back-end/app/Http/Requests/Api/V1/UpdateMonitorRequest.php` — Update request with `sometimes` rules
  - `back-end/app/Http/Resources/Api/V1/MonitorResource.php` — Resource pattern (toArray)
  - `back-end/app/Providers/AppServiceProvider.php` — Policy registration pattern

  **Acceptance Criteria**:
  - [ ] `php artisan test --filter=StatusPagePolicyTest` → all PASS
  - [ ] `php artisan test --filter=StatusPageValidationTest` → all PASS
  - [ ] Slug "api" rejected (reserved word)
  - [ ] Slug "UPPER" rejected (must be lowercase)
  - [ ] Slug "valid-slug-123" accepted
  - [ ] primary_color "#FF0000" accepted, "red" rejected
  - [ ] StatusPageResource does NOT include monitor auth_config/headers/body

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Reserved slug rejected
    Tool: Bash (php artisan test)
    Steps:
      1. Run php artisan test --filter=StatusPageValidationTest
      2. Assert exit code 0
    Expected Result: All validation tests pass

  Scenario: Policy authorization works
    Tool: Bash (php artisan test)
    Steps:
      1. Run php artisan test --filter=StatusPagePolicyTest
      2. Assert exit code 0
    Expected Result: All policy tests pass
  ```

  **Commit**: YES
  - Message: `feat(status-page): add policy, form requests, and API resource`
  - Files: `app/Policies/StatusPagePolicy.php, app/Http/Requests/Api/V1/Store*, app/Http/Requests/Api/V1/Update*, app/Http/Resources/Api/V1/StatusPageResource.php, app/Providers/AppServiceProvider.php, tests/Feature/StatusPage*Test.php`
  - Pre-commit: `php artisan test --filter=StatusPage`

---

- [ ] 3. Laravel: API Controller (CRUD + Monitor Attach/Detach)

  **What to do**:

  *TDD — RED*: Write API feature tests:
  - `tests/Feature/StatusPageApiTest.php`:
    - `test_can_list_status_pages_for_team` → GET /api/v1/status-pages → 200, returns team's pages
    - `test_can_create_status_page` → POST /api/v1/status-pages → 201, creates page
    - `test_can_show_status_page` → GET /api/v1/status-pages/{id} → 200
    - `test_can_update_status_page` → PUT /api/v1/status-pages/{id} → 200
    - `test_can_delete_status_page` → DELETE /api/v1/status-pages/{id} → 200
    - `test_can_attach_monitors` → POST /api/v1/status-pages/{id}/monitors → 200
    - `test_can_detach_monitor` → DELETE /api/v1/status-pages/{id}/monitors/{monitorId} → 200
    - `test_can_reorder_monitors` → PUT /api/v1/status-pages/{id}/monitors/reorder → 200
    - `test_can_toggle_publish` → POST /api/v1/status-pages/{id}/publish → 200
    - `test_unauthorized_user_cannot_access` → 403
    - `test_duplicate_slug_returns_422` → 422
  - Run tests → FAIL

  *TDD — GREEN*:
  - `app/Http/Controllers/Api/V1/StatusPageController.php`:
    - `index()` → list status pages for user's current team
    - `store(StoreStatusPageRequest)` → create, optionally attach monitors
    - `show(StatusPage)` → show with monitors
    - `update(UpdateStatusPageRequest, StatusPage)` → update
    - `destroy(StatusPage)` → delete
    - `attachMonitors(Request, StatusPage)` → sync monitors with pivot data
    - `detachMonitor(StatusPage, Monitor)` → detach single monitor
    - `reorderMonitors(Request, StatusPage)` → update display_order
    - `togglePublish(StatusPage)` → toggle is_published
  - Follow MonitorController pattern: manual team check → authorize → execute → respond with `{data, message}`

  *TDD — REFACTOR*: Run all tests → PASS

  **Must NOT do**:
  - Do NOT allow attaching monitors from other teams
  - Do NOT expose monitor auth_config in responses
  - Do NOT skip authorization checks

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`git-master`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 1 — after Task 2
  - **Blocks**: Task 5
  - **Blocked By**: Tasks 1, 2

  **References**:
  - `back-end/app/Http/Controllers/Api/V1/MonitorController.php` — Full CRUD controller pattern (authorize, team check, form request, resource response)
  - `back-end/routes/api/v1.php:52-63` — Monitor route registration pattern (apiResource + custom routes)
  - `back-end/app/Http/Resources/Api/V1/MonitorResource.php` — Resource usage in responses

  **Acceptance Criteria**:
  - [ ] `php artisan test --filter=StatusPageApiTest` → all PASS
  - [ ] POST with valid data → 201, slug in response
  - [ ] POST with duplicate slug → 422
  - [ ] GET list returns only current team's pages
  - [ ] DELETE by non-admin → 403
  - [ ] Attach monitor from another team → 403/422

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Full CRUD lifecycle
    Tool: Bash (curl)
    Preconditions: Auth token available, team with monitors exists
    Steps:
      1. POST /api/v1/status-pages with {"name":"Test","slug":"test-page","description":"Test page"} → Assert 201
      2. GET /api/v1/status-pages → Assert response contains "test-page"
      3. PUT /api/v1/status-pages/{id} with {"name":"Updated"} → Assert 200
      4. POST /api/v1/status-pages/{id}/monitors with {"monitors":[{"monitor_id":1,"display_order":1}]} → Assert 200
      5. POST /api/v1/status-pages/{id}/publish → Assert 200, is_published=true
      6. DELETE /api/v1/status-pages/{id} → Assert 200
    Expected Result: Full CRUD works with proper responses
  ```

  **Commit**: YES
  - Message: `feat(status-page): add CRUD API controller with monitor management`
  - Files: `app/Http/Controllers/Api/V1/StatusPageController.php, tests/Feature/StatusPageApiTest.php`
  - Pre-commit: `php artisan test --filter=StatusPage`

---

- [ ] 4. Laravel: Public Blade Controller + Template (Server-Side Rendered Status Page)

  **What to do**:

  *TDD — RED*: Write public page tests:
  - `tests/Feature/PublicStatusPageTest.php`:
    - `test_published_status_page_returns_200` → GET /status/{slug} → 200, contains title
    - `test_unpublished_status_page_returns_404` → 404
    - `test_nonexistent_slug_returns_404` → 404
    - `test_page_contains_monitor_statuses` → HTML contains monitor names and status indicators
    - `test_page_contains_uptime_bars` → HTML contains 90-day uptime data
    - `test_page_contains_response_times` → HTML contains response time info
    - `test_page_uses_custom_branding` → HTML contains custom primary_color, logo_url, title
    - `test_page_has_no_javascript` → HTML does NOT contain `<script>` tags
    - `test_page_is_cached_for_5_minutes` → Cache key exists after first load
    - `test_overall_status_computation` → all_up=Operational, any_down=Partial Outage
  - Run tests → FAIL

  *TDD — GREEN*:
  - `app/Http/Controllers/PublicStatusPageController.php`:
    - `show($slug)`:
      1. `Cache::remember("status_page_{$slug}", 300, function() { ... })`
      2. Find StatusPage by slug where is_published=true, or abort(404)
      3. Eager load monitors with pivot, latest status
      4. Query `monitor_checks_daily` for 90-day uptime per monitor (use DB::table raw query against the materialized view)
      5. Compute overall status: all_up → "Operational", any_degraded → "Degraded Performance", any_down → "Partial Outage", all_down → "Major Outage"
      6. Compute avg response time per monitor from `monitor_checks_daily` last 7 days
      7. Return `view('status-page.show', compact(...))`
  - `resources/views/layouts/status-page.blade.php`:
    - HTML5 doctype, responsive meta viewport
    - Dynamic `<title>` from status page name
    - Dynamic favicon from `favicon_url`
    - Open Graph meta tags (og:title, og:description)
    - `<style>` block with all CSS inline — NO external stylesheet, NO Tailwind CDN
    - CSS variables for `--primary-color` from `primary_color` field
    - Responsive design (max-width container, flexbox, mobile-friendly)
    - Dark background (#111827) with light cards (#1f2937) — match brand.md dark mode
    - `@yield('content')` slot
  - `resources/views/status-page/show.blade.php`:
    - **Header section**: Logo image (if logo_url), page title, description, overall status badge
    - **Monitor list section**: For each monitor:
      - Status indicator dot (green=up, red=down, yellow=degraded, gray=unknown)
      - Monitor name (custom_label from pivot, fallback to monitor.name)
      - Current status text
      - Last response time + 7-day average response time
      - 90-day uptime bar: 90 tiny `<div>` elements, each colored by daily uptime %:
        - >= 99%: green (#10B981)
        - >= 95%: yellow (#F59E0B)
        - < 95%: red (#EF4444)
        - no data: gray (#374151)
        - CSS `:hover` pseudo-element showing date + uptime % tooltip
      - Overall uptime % text below the bar
    - **Footer**: "Powered by Uptizm" with link, last updated timestamp
    - ALL styling via inline `<style>` block — ZERO JavaScript

  *TDD — REFACTOR*: Run all tests → PASS

  **Must NOT do**:
  - Do NOT add any `<script>` tags — zero JavaScript
  - Do NOT use Tailwind CDN or any CSS framework CDN
  - Do NOT use Livewire, Alpine.js, or any JS framework
  - Do NOT install Vite or any build pipeline
  - Do NOT expose monitor URLs, auth configs, headers, or any sensitive data in the HTML
  - Do NOT query raw `monitor_checks` table — use `monitor_checks_daily` materialized view
  - Do NOT skip caching — the query is expensive

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`frontend-ui-ux`, `git-master`]
    - `frontend-ui-ux`: For crafting a polished, responsive Blade template with pure CSS

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 1 — after Task 2
  - **Blocks**: Task 5
  - **Blocked By**: Tasks 1, 2

  **References**:
  - `back-end/resources/views/welcome.blade.php` — Existing Blade template (inline Tailwind CSS, responsive)
  - `back-end/app/Models/Monitor.php:last_status,last_response_time_ms` — Real-time monitor status fields
  - `brand.md` — Primary color #009E60, dark mode surfaces, Inter font, 4px spacing grid
  - `back-end/database/migrations/setup_timescaledb.php` — `monitor_checks_daily` materialized view definition (has `up_count`, `down_count`, `check_count`, `avg_response_time_ms` per day per monitor)
  - `.claude/docs/alerting-system-architecture.md` — Monitor status values and transitions

  **Acceptance Criteria**:
  - [ ] `php artisan test --filter=PublicStatusPageTest` → all PASS
  - [ ] GET /status/{valid-slug} → 200, HTML content
  - [ ] GET /status/{invalid-slug} → 404
  - [ ] HTML contains NO `<script>` tags
  - [ ] HTML contains custom primary_color as CSS variable
  - [ ] 90-day bar chart rendered as 90 `<div>` elements
  - [ ] Response time shown per monitor
  - [ ] Overall status badge displayed
  - [ ] Page cached for 5 minutes (Cache::has returns true)

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Public page renders with correct content
    Tool: Bash (curl)
    Preconditions: Published status page "test" exists with 2 monitors attached
    Steps:
      1. curl -s http://localhost:8000/status/test -o /tmp/status.html
      2. Assert exit code 0
      3. grep -c '<script>' /tmp/status.html → Assert output is "0"
      4. grep -q 'Operational\|Partial Outage\|Degraded\|Major Outage' /tmp/status.html → Assert exits 0
      5. grep -q 'var(--primary-color)' /tmp/status.html → Assert exits 0
      6. grep -c 'uptime-bar-day' /tmp/status.html → Assert output >= 90
    Expected Result: Page renders with zero JS, correct status, 90 uptime bars

  Scenario: Unpublished page returns 404
    Tool: Bash (curl)
    Steps:
      1. curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/status/unpublished-slug
      2. Assert output is "404"
    Expected Result: 404 for unpublished pages

  Scenario: Page is cached
    Tool: Bash (php artisan tinker)
    Steps:
      1. Cache::forget('status_page_test')
      2. curl http://localhost:8000/status/test
      3. php artisan tinker --execute="echo Cache::has('status_page_test') ? 'CACHED' : 'NOT'"
      4. Assert output contains "CACHED"
    Expected Result: Cache populated after first request
  ```

  **Commit**: YES
  - Message: `feat(status-page): add public Blade-rendered status page with 90-day uptime chart`
  - Files: `app/Http/Controllers/PublicStatusPageController.php, resources/views/layouts/status-page.blade.php, resources/views/status-page/show.blade.php, tests/Feature/PublicStatusPageTest.php`
  - Pre-commit: `php artisan test --filter=StatusPage`

---

- [ ] 5. Laravel: Route Registration (API + Public Web)

  **What to do**:

  *TDD*: Routes are implicitly tested by Tasks 3 and 4 tests. Just wire them up.

  - Add to `routes/api/v1.php` inside `auth:sanctum` middleware group:
    ```php
    // Status Pages
    Route::apiResource('status-pages', StatusPageController::class);
    Route::post('status-pages/{statusPage}/monitors', [StatusPageController::class, 'attachMonitors']);
    Route::delete('status-pages/{statusPage}/monitors/{monitor}', [StatusPageController::class, 'detachMonitor']);
    Route::put('status-pages/{statusPage}/monitors/reorder', [StatusPageController::class, 'reorderMonitors']);
    Route::post('status-pages/{statusPage}/publish', [StatusPageController::class, 'togglePublish']);
    ```
  - Add to `routes/web.php` (public, no auth):
    ```php
    // Public Status Pages — path-based (works for local dev + production fallback)
    Route::get('/status/{slug}', [PublicStatusPageController::class, 'show'])->name('status-page.show');
    ```
  - Add subdomain route group (production only, controlled by env):
    ```php
    // Subdomain routing (production) — in RouteServiceProvider or web.php
    if (config('app.status_page_domain')) {
        Route::domain('{slug}.' . config('app.status_page_domain'))->group(function () {
            Route::get('/', [PublicStatusPageController::class, 'show'])->name('status-page.subdomain');
        });
    }
    ```
  - Add `status_page_domain` to `config/app.php`: `'status_page_domain' => env('STATUS_PAGE_DOMAIN', null)`
  - Add `STATUS_PAGE_DOMAIN=uptizm.com` to `.env.example`

  **Must NOT do**:
  - Do NOT require subdomain routing for local dev — path-based route is the fallback
  - Do NOT add complex middleware for subdomain resolution
  - Do NOT modify existing routes

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`git-master`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 1 — after Tasks 3 & 4
  - **Blocks**: Task 6
  - **Blocked By**: Tasks 3, 4

  **References**:
  - `back-end/routes/api/v1.php:52-63` — Monitor route pattern (apiResource + custom routes)
  - `back-end/routes/web.php` — Current web routes (only welcome)
  - `back-end/config/app.php` — Config pattern

  **Acceptance Criteria**:
  - [ ] `php artisan route:list --path=status-pages` shows CRUD + custom routes
  - [ ] `php artisan route:list --path=status` shows public web route
  - [ ] `php artisan test --filter=StatusPage` → all PASS (existing tests still work with routes)

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Routes registered correctly
    Tool: Bash
    Steps:
      1. cd back-end && php artisan route:list --path=status-pages --columns=method,uri,name
      2. Assert output contains "GET|HEAD api/v1/status-pages"
      3. Assert output contains "POST api/v1/status-pages"
      4. Assert output contains "GET|HEAD api/v1/status-pages/{status_page}"
      5. Assert output contains "PUT|PATCH api/v1/status-pages/{status_page}"
      6. Assert output contains "DELETE api/v1/status-pages/{status_page}"
      7. php artisan route:list --path=status/ --columns=method,uri
      8. Assert output contains "GET|HEAD status/{slug}"
    Expected Result: All routes registered
  ```

  **Commit**: YES
  - Message: `feat(status-page): register API and public web routes`
  - Files: `routes/api/v1.php, routes/web.php, config/app.php, .env.example`
  - Pre-commit: `php artisan test --filter=StatusPage`

---

- [ ] 6. Flutter: StatusPage Model + Tests

  **What to do**:

  *TDD — RED*: Write model test first:
  - `test/app/models/status_page_test.dart` (use `package:uptizm/` imports per AGENTS.md test convention):
    - Test fillable fields
    - Test typed getters/setters (`get<String>('name')`, `set('name', value)`)
    - Test `slug` getter
    - Test `isPublished` computed property
    - Test `publicUrl` computed property (returns `https://{slug}.uptizm.com`)
    - Test `monitors` list getter (from nested data)
    - Test `primaryColor` with default fallback to '#009E60'
    - Test `fromMap()` factory / constructor
  - Run `flutter test test/app/models/status_page_test.dart` → FAIL

  *TDD — GREEN*:
  - `lib/app/models/status_page.dart`:
    ```dart
    class StatusPage extends Model with HasTimestamps, InteractsWithPersistence {
      StatusPage() : super();

      @override String get table => 'status-pages';
      @override String get resource => 'status-pages';
      @override List<String> get fillable => [
        'name', 'slug', 'description', 'logo_url', 'favicon_url',
        'primary_color', 'is_published', 'monitor_ids',
      ];

      // Typed getters
      String get name => get<String>('name') ?? '';
      set name(String value) => set('name', value);
      
      String get slug => get<String>('slug') ?? '';
      set slug(String value) => set('slug', value);
      
      String? get description => get<String>('description');
      set description(String? value) => set('description', value);
      
      String? get logoUrl => get<String>('logo_url');
      set logoUrl(String? value) => set('logo_url', value);
      
      String? get faviconUrl => get<String>('favicon_url');
      set faviconUrl(String? value) => set('favicon_url', value);
      
      String get primaryColor => get<String>('primary_color') ?? '#009E60';
      set primaryColor(String value) => set('primary_color', value);
      
      bool get isPublished => get<bool>('is_published') ?? false;
      set isPublished(bool value) => set('is_published', value);
      
      String get publicUrl => 'https://$slug.uptizm.com';
      
      // Monitor IDs for create/update
      List<int> get monitorIds {
        final ids = get<List>('monitor_ids');
        return ids?.map((e) => (e as num).toInt()).toList() ?? [];
      }
      set monitorIds(List<int> value) => set('monitor_ids', value);
      
      // Monitors from API response (read-only, populated by resource)
      List<Monitor> get monitors {
        final data = get<List>('monitors');
        if (data == null) return [];
        return data.map((m) => Monitor()..fill(Map<String, dynamic>.from(m))).toList();
      }
    }
    ```

  *TDD — REFACTOR*: Run tests → PASS

  **Must NOT do**:
  - Do NOT use `getAttribute/setAttribute` — use `get<T>/set`
  - Do NOT use `as int` for numeric values — use `(value as num?)?.toInt()`
  - Do NOT use package imports within `lib/` — use relative imports
  - DO use package imports in `test/` — use `package:uptizm/`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`git-master`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 2 — start
  - **Blocks**: Task 7
  - **Blocked By**: Task 5 (needs API to be ready)

  **References**:
  - `lib/app/models/monitor.dart` — Model pattern (get<T>/set, fillable, table, resource, typed getters/setters)
  - `lib/app/models/team.dart` — Team model (computed properties like canEdit, isOwner)
  - `test/app/models/monitor_test.dart` or `test/unit/models/` — Existing model test patterns
  - `lib/app/models/monitor.dart:9-12` — Class declaration pattern (extends Model with HasTimestamps, InteractsWithPersistence)

  **Acceptance Criteria**:
  - [ ] `flutter test test/app/models/status_page_test.dart` → all PASS
  - [ ] `flutter analyze lib/app/models/status_page.dart` → no issues
  - [ ] `dart format lib/app/models/status_page.dart` → no changes
  - [ ] Model uses `get<T>/set` pattern, NOT `getAttribute/setAttribute`
  - [ ] No package imports within `lib/` (relative only)

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Model tests pass
    Tool: Bash
    Steps:
      1. flutter test test/app/models/status_page_test.dart
      2. Assert exit code 0
    Expected Result: All model tests pass

  Scenario: Static analysis clean
    Tool: Bash
    Steps:
      1. flutter analyze lib/app/models/status_page.dart --no-fatal-infos
      2. Assert output contains "No issues found" or exit code 0
    Expected Result: No analysis issues
  ```

  **Commit**: YES
  - Message: `feat(status-page): add Flutter StatusPage model with tests`
  - Files: `lib/app/models/status_page.dart, test/app/models/status_page_test.dart`
  - Pre-commit: `flutter test test/app/models/status_page_test.dart`

---

- [ ] 7. Flutter: StatusPageController + Tests

  **What to do**:

  *TDD — RED*: Write controller test:
  - `test/app/controllers/status_page_controller_test.dart`:
    - Test singleton access via `StatusPageController.instance`
    - Test `index()` returns `StatusPagesIndexView` widget
    - Test `create()` returns `StatusPageCreateView` widget
    - Test `edit()` returns `StatusPageEditView` widget
    - Test notifiers initialized (statusPagesNotifier, selectedStatusPageNotifier)
  - Run → FAIL

  *TDD — GREEN*:
  - `lib/app/controllers/status_page_controller.dart`:
    ```dart
    class StatusPageController extends MagicController
        with MagicStateMixin<bool>, ValidatesRequests {
      static StatusPageController get instance =>
          Magic.findOrPut(StatusPageController.new);

      final statusPagesNotifier = ValueNotifier<List<StatusPage>>([]);
      final selectedStatusPageNotifier = ValueNotifier<StatusPage?>(null);

      Widget index() => const StatusPagesIndexView();
      Widget create() => const StatusPageCreateView();
      Widget edit() => const StatusPageEditView();

      Future<void> loadStatusPages() async { ... }  // GET /status-pages
      Future<void> loadStatusPage(int id) async { ... }  // GET /status-pages/{id}
      Future<void> store({required String name, required String slug, ...}) async { ... }  // POST
      Future<void> update(int id, {...}) async { ... }  // PUT
      Future<void> destroy(int id) async { ... }  // DELETE with confirm dialog
      Future<void> togglePublish(int id) async { ... }  // POST /status-pages/{id}/publish
      Future<void> attachMonitors(int id, List<Map<String, dynamic>> monitors) async { ... }
      Future<void> detachMonitor(int statusPageId, int monitorId) async { ... }
      Future<void> reorderMonitors(int id, List<Map<String, dynamic>> monitors) async { ... }

      @override
      void dispose() {
        statusPagesNotifier.dispose();
        selectedStatusPageNotifier.dispose();
        super.dispose();
      }
    }
    ```
  - Follow MonitorController pattern exactly: setLoading, clearErrors, try/catch, setSuccess/setError, MagicRoute.to(), Magic.toast()
  - HTTP paths: `/status-pages`, `/status-pages/$id`, etc. (relative, no leading domain)

  *TDD — REFACTOR*: Run tests → PASS

  **Must NOT do**:
  - Do NOT add a `show()` action — no Flutter show/preview view; users open browser
  - Do NOT use absolute HTTP URLs — use relative paths (Magic framework prepends base URL)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`git-master`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 2 — after Task 6
  - **Blocks**: Task 8
  - **Blocked By**: Task 6

  **References**:
  - `lib/app/controllers/monitor_controller.dart` — Full controller pattern (singleton, ValueNotifiers, CRUD, dispose, setLoading/clearErrors/setSuccess/setError)
  - `.claude/rules/controllers.md` — Controller rules (singleton, state management, CRUD, form submit, navigation)
  - `lib/app/controllers/team_controller.dart` — Another controller example for comparison

  **Acceptance Criteria**:
  - [ ] `flutter test test/app/controllers/status_page_controller_test.dart` → all PASS
  - [ ] `flutter analyze lib/app/controllers/status_page_controller.dart` → no issues
  - [ ] Controller is singleton via `Magic.findOrPut`
  - [ ] All notifiers disposed in `dispose()`
  - [ ] No `show()` action exists

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Controller tests pass
    Tool: Bash
    Steps:
      1. flutter test test/app/controllers/status_page_controller_test.dart
      2. Assert exit code 0
    Expected Result: All controller tests pass
  ```

  **Commit**: YES
  - Message: `feat(status-page): add Flutter StatusPageController with tests`
  - Files: `lib/app/controllers/status_page_controller.dart, test/app/controllers/status_page_controller_test.dart`
  - Pre-commit: `flutter test test/app/controllers/status_page_controller_test.dart`

---

- [ ] 8. Flutter: Views (Index + Create + Edit) + Route Updates

  **What to do**:

  Create 3 views following existing patterns, then update routes.

  **8a. StatusPagesIndexView** (`lib/resources/views/status_pages/status_pages_index_view.dart`):
  - Extends `MagicStatefulView<StatusPageController>`
  - `onInit()` → `controller.loadStatusPages()`
  - `AppPageHeader` with title `trans('status_pages.title')` and "Create" action button
  - `ValueListenableBuilder<List<StatusPage>>` for reactive list
  - Each list item shows: name, slug (as subdomain URL), published status badge, monitor count
  - Actions per item: Edit button, Toggle Publish button, Open in Browser button (launches external URL), Delete button
  - Empty state when no status pages
  - Wind UI only: `WDiv`, `WText`, `WButton`, `WIcon`, `WBadge`
  - Dark mode on everything

  **8b. StatusPageCreateView** (`lib/resources/views/status_pages/status_page_create_view.dart`):
  - Extends `MagicStatefulView<StatusPageController>`
  - `MagicFormData` with fields: name, slug, description, logo_url, favicon_url, primary_color
  - Slug field: auto-generates from name (slugify), editable, shows preview `{slug}.uptizm.com`
  - Color picker or hex input for primary_color (default #009E60)
  - Monitor selection: Load team's monitors via `MonitorController`, show checkboxes or multi-select
  - Display order: drag-to-reorder or manual number input for selected monitors
  - Custom label input per selected monitor (optional)
  - Submit calls `controller.store(...)` then `controller.attachMonitors(...)`
  - Wind UI form components: `WFormInput`, `WFormSelect`, `WButton`
  - Dark mode

  **8c. StatusPageEditView** (`lib/resources/views/status_pages/status_page_edit_view.dart`):
  - Same form as create but pre-populated
  - `onInit()` loads status page by ID from path parameter
  - Submit calls `controller.update(id, ...)`
  - Shows current monitor selection with ability to add/remove/reorder

  **8d. Route Updates** (`lib/routes/app.dart`):
  - Replace lines 108-114 (Coming Soon placeholder) with:
    ```dart
    MagicRoute.page('/status-pages', () => StatusPageController.instance.index());
    MagicRoute.page('/status-pages/create', () => StatusPageController.instance.create());
    MagicRoute.page('/status-pages/:id/edit', () => StatusPageController.instance.edit());
    ```
  - Add import for StatusPageController

  **Must NOT do**:
  - Do NOT create a show/preview view — "Open in Browser" button opens the public URL
  - Do NOT use Material widgets (Container, Text, TextField, Row/Column, ElevatedButton) — Wind UI only
  - Do NOT use filled icons — outlined only (e.g., `Icons.edit_outlined`)
  - Do NOT skip dark mode classes
  - Do NOT use `package:uptizm/` imports within `lib/` — relative imports only

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`frontend-ui-ux`, `git-master`]
    - `frontend-ui-ux`: For crafting polished Flutter views with Wind UI components

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 2 — after Task 7
  - **Blocks**: Task 9
  - **Blocked By**: Task 7

  **References**:
  - `lib/resources/views/monitors/monitors_index_view.dart` — Index view pattern (MagicStatefulView, AppPageHeader, ValueListenableBuilder, filtering)
  - `lib/resources/views/monitors/monitor_create_view.dart` — Create form pattern (MagicFormData, sections, validation, submit)
  - `lib/resources/views/monitors/monitor_edit_view.dart` — Edit form pattern (pre-populated, path parameter)
  - `lib/resources/views/components/app_page_header.dart` — Page header component
  - `lib/routes/app.dart:108-114` — Current placeholder to replace
  - `.claude/rules/views.md` — View rules (Wind UI only, dark mode, outlined icons)
  - `brand.md` — Design system (colors, spacing, typography)
  - `lib/resources/views/components/` — Available shared components

  **Acceptance Criteria**:
  - [ ] `flutter analyze --no-fatal-infos` → no issues
  - [ ] `dart format .` → no changes needed
  - [ ] Route `/status-pages` renders index view (not "Coming Soon")
  - [ ] Route `/status-pages/create` renders create form
  - [ ] Route `/status-pages/:id/edit` renders edit form
  - [ ] No Material widgets used (no Container, Text, TextField, Row, Column, ElevatedButton)
  - [ ] All views have dark mode classes
  - [ ] No `<script>` or JS in views (Flutter views, not Blade)

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: No Material widgets in views
    Tool: ast_grep_search
    Steps:
      1. Search for Container( in lib/resources/views/status_pages/
      2. Search for TextField( in lib/resources/views/status_pages/
      3. Search for ElevatedButton( in lib/resources/views/status_pages/
      4. Assert zero matches for each
    Expected Result: No Material widgets found

  Scenario: Routes replaced
    Tool: Grep
    Steps:
      1. Search for "Coming Soon" in lib/routes/app.dart
      2. Assert the status-pages "Coming Soon" is gone
      3. Search for "StatusPageController" in lib/routes/app.dart
      4. Assert it exists
    Expected Result: Placeholder replaced with controller actions

  Scenario: Static analysis clean
    Tool: Bash
    Steps:
      1. flutter analyze --no-fatal-infos
      2. Assert exit code 0
    Expected Result: No analysis issues
  ```

  **Commit**: YES
  - Message: `feat(status-page): add Flutter views (index/create/edit) and update routes`
  - Files: `lib/resources/views/status_pages/*.dart, lib/routes/app.dart`
  - Pre-commit: `flutter analyze --no-fatal-infos`

---

- [ ] 9. Final Integration QA + Translations

  **What to do**:
  - Add translation keys for status page strings (check existing translation files pattern)
  - Run full test suites for both Flutter and Laravel
  - Verify end-to-end: create status page via API → publish → access public page
  - Verify Flutter analyze and format pass
  - Final cleanup: remove any TODO comments, verify imports

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`git-master`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Final
  - **Blocks**: None
  - **Blocked By**: Task 8

  **References**:
  - Existing translation files in project (search for `trans(` usage patterns)
  - `lib/resources/views/components/navigation/navigation_list.dart:55` — `nav.status_pages` translation key already referenced

  **Acceptance Criteria**:
  - [ ] `php artisan test --filter=StatusPage` → all PASS
  - [ ] `flutter test` → all PASS (full suite, not just status page)
  - [ ] `flutter analyze --no-fatal-infos` → no issues
  - [ ] `dart format .` → no changes
  - [ ] No `TODO` or `FIXME` comments in new files
  - [ ] All `trans()` keys have corresponding translation entries

  **Agent-Executed QA Scenarios**:
  ```
  Scenario: Full Laravel test suite passes
    Tool: Bash
    Steps:
      1. cd back-end && php artisan test --filter=StatusPage
      2. Assert exit code 0
      3. Assert output shows 0 failures
    Expected Result: All Laravel status page tests pass

  Scenario: Full Flutter test suite passes
    Tool: Bash
    Steps:
      1. flutter test
      2. Assert exit code 0
    Expected Result: All Flutter tests pass (including status page tests)

  Scenario: End-to-end flow works
    Tool: Bash (curl)
    Preconditions: Laravel dev server running, test user authenticated
    Steps:
      1. POST /api/v1/status-pages → create "e2e-test" page
      2. POST /api/v1/status-pages/{id}/monitors → attach monitor
      3. POST /api/v1/status-pages/{id}/publish → publish
      4. GET /status/e2e-test → Assert 200
      5. Assert HTML contains monitor name
      6. DELETE /api/v1/status-pages/{id} → cleanup
    Expected Result: Full flow works end-to-end
  ```

  **Commit**: YES
  - Message: `feat(status-page): add translations and finalize integration`
  - Files: Translation files, any minor fixes
  - Pre-commit: `flutter test && cd back-end && php artisan test --filter=StatusPage`

---

## Commit Strategy

| After Task | Message | Verification |
|------------|---------|--------------|
| 1 | `feat(status-page): add migration, model, and factory` | `php artisan test --filter=StatusPageModelTest` |
| 2 | `feat(status-page): add policy, form requests, and API resource` | `php artisan test --filter=StatusPage` |
| 3 | `feat(status-page): add CRUD API controller with monitor management` | `php artisan test --filter=StatusPage` |
| 4 | `feat(status-page): add public Blade-rendered status page` | `php artisan test --filter=StatusPage` |
| 5 | `feat(status-page): register API and public web routes` | `php artisan test --filter=StatusPage` |
| 6 | `feat(status-page): add Flutter StatusPage model with tests` | `flutter test test/app/models/status_page_test.dart` |
| 7 | `feat(status-page): add Flutter StatusPageController with tests` | `flutter test test/app/controllers/` |
| 8 | `feat(status-page): add Flutter views and update routes` | `flutter analyze --no-fatal-infos` |
| 9 | `feat(status-page): add translations and finalize integration` | `flutter test && php artisan test` |

---

## Success Criteria

### Verification Commands
```bash
# Laravel
cd back-end && php artisan test --filter=StatusPage  # All status page tests pass

# Flutter
flutter test                                          # All tests pass
flutter analyze --no-fatal-infos                      # No analysis issues
dart format --set-exit-if-changed .                   # Formatting correct

# Public page
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/status/test-slug  # 200
curl -s http://localhost:8000/status/test-slug | grep -c '<script>'            # 0 (zero JS)
```

### Final Checklist
- [ ] All "Must Have" items present
- [ ] All "Must NOT Have" items absent (especially: zero JS on public page, no Vite/Tailwind pipeline)
- [ ] All tests pass (Laravel + Flutter)
- [ ] 90-day uptime chart renders as pure CSS bars
- [ ] Public page cached for 5 minutes
- [ ] Slug validation includes reserved words
- [ ] Monitor sensitive data (auth_config, headers, body, URL) NOT exposed on public page
- [ ] Flutter views use Wind UI only, dark mode enabled
- [ ] `monitor_checks_daily` used for uptime data (NOT raw `monitor_checks`)
