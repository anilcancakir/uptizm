# Incidents & Announcements System

## TL;DR

> **Quick Summary**: Build a full-stack Incidents & Announcements system for Uptizm, inspired by status.claude.com. Incidents support both manual CRUD and automatic creation from consecutive monitor failures. Announcements support scheduled maintenance windows and informational posts. Both display on public status pages.
> 
> **Deliverables**:
> - Backend: Incident, IncidentUpdate, Announcement models + migrations + API endpoints + authorization policies
> - Backend: Auto-incident creation service triggered by consecutive monitor failures
> - Backend: Scheduled announcement processing command
> - Frontend: Incident CRUD views (list, create, show/timeline, edit)
> - Frontend: Announcement CRUD views
> - Frontend: Status page integration (incidents + announcements on public view)
> - Tests: Full TDD coverage (Laravel Feature tests + Flutter unit/widget tests)
> 
> **Estimated Effort**: XL
> **Parallel Execution**: YES - 4 waves
> **Critical Path**: Task 1 → 2 → 3 → 4 → 5 → 8 → 10 → 11 → 14

---

## Context

### Original Request
Build an Incidents and Announcements system similar to status.claude.com. Incidents should support both automatic creation (when monitors have consecutive down checks) and manual management. Each incident has a timeline of updates (Investigating → Identified → Monitoring → Resolved). Announcements are simpler, supporting maintenance notifications and improvement/informational posts with scheduling.

### Interview Summary
**Key Discussions**:
- Incident-Monitor relationship: **Many-to-Many** (one incident can affect multiple monitors)
- Auto-incident threshold: **Configurable per monitor** (new `incident_threshold` field)
- Impact levels: **4 levels** (Major Outage, Partial Outage, Degraded Performance, Under Maintenance)
- Update statuses: Investigating, Identified, Monitoring, Resolved
- Auto-incident opens with: **"Investigating" + default message**
- Announcements linked to: **Status Page** (each page has its own)
- Announcement types: **Maintenance, Improvement, Informational**
- Scheduled announcements: **Yes** (auto-publish/close)
- Public display: **Both** uptime bar popup AND history list (last 15 days)
- Notifications: **NOT in scope** (deferred to future)
- Scope: **Full-stack** (Backend + Flutter together)

### Research Findings
- `ProcessCheckResult` job already tracks `consecutive_fails` (L105-107) — perfect hook for auto-incidents
- `StatusPage` has BelongsToMany with monitors via `status_page_monitor` pivot — incidents appear on status pages indirectly through shared monitors
- `/incidents` route exists as "Coming Soon" placeholder in Flutter routes
- `ActivityType.incident` enum and `SearchResultType.incident` already exist in Flutter
- Translation keys partially exist (`nav.incidents`, `dashboard.active_incidents`)
- Public status page controller needs investigation — file not found at expected path
- All models use UUIDs (HasUuids, non-incrementing)

### Metis Review
**Identified Gaps** (addressed):
- **Anti-flapping protection**: Added cooldown period (30 min default) for auto-incidents to prevent rapid create/resolve cycles
- **Cache invalidation**: Public status page cached 300s — must bust cache on incident/announcement changes
- **Auto-recovery behavior**: On monitor recovery, auto-add "Monitoring" update (not auto-resolve) for human confirmation
- **ProcessCheckResult complexity**: Extract incident logic to separate `IncidentAutoCreationService` class
- **Race condition**: Concurrent check results could create duplicate auto-incidents — use DB locking
- **Public page Blade template**: May need creation — added investigation subtask

---

## Work Objectives

### Core Objective
Enable Uptizm users to track and communicate service incidents and announcements through both manual management and automatic detection, displayed on public status pages.

### Concrete Deliverables
- 4 database migrations (incidents, incident_updates, incident_monitor, announcements)
- 1 migration to add `incident_threshold` to monitors table
- 4 Laravel models (Incident, IncidentUpdate, IncidentMonitor, Announcement)
- 2 Laravel controllers (IncidentController, AnnouncementController)
- 4 Form Requests (StoreIncident, UpdateIncident, StoreIncidentUpdate, StoreAnnouncement/UpdateAnnouncement)
- 2 API Resources (IncidentResource, AnnouncementResource)
- 2 Policies (IncidentPolicy, AnnouncementPolicy)
- 1 Service (IncidentAutoCreationService)
- 1 Artisan Command (ProcessScheduledAnnouncements)
- 4 Flutter models (Incident, IncidentUpdate, Announcement + enums)
- 2 Flutter controllers (IncidentController, AnnouncementController)
- 8+ Flutter views (index, create, show, edit for incidents + announcements)
- Public status page integration (incident timeline + announcement display)

### Definition of Done
- [ ] `php artisan test --filter=Incident` → All pass
- [ ] `php artisan test --filter=Announcement` → All pass
- [ ] `flutter test test/app/models/incident_test.dart` → Pass
- [ ] `flutter test test/app/models/announcement_test.dart` → Pass
- [ ] `flutter test test/app/enums/` → All new enum tests pass
- [ ] Manual incident CRUD works end-to-end (create, add updates, resolve)
- [ ] Auto-incident creates when monitor exceeds threshold
- [ ] Public status page shows active incidents and recent history

### Must Have
- Incident timeline with multiple updates (Investigating → Identified → Monitoring → Resolved)
- Many-to-Many incident-monitor relationship
- Auto-incident creation with configurable threshold per monitor
- Anti-flapping cooldown for auto-incidents (30 min)
- Auto "Monitoring" update on recovery (NOT auto-resolve)
- Announcement CRUD with scheduled support
- Public status page incident display (popup + history list, 15 days)
- Cache busting on incident/announcement changes
- Team-scoped multi-tenancy
- TDD: All code test-first

### Must NOT Have (Guardrails)
- ❌ Email/push notifications for incidents (deferred to future)
- ❌ Comment/discussion system on incidents
- ❌ Rich text editor for announcements (plain text only)
- ❌ Incident templates or severity auto-detection
- ❌ Cross-wiring with existing alert rule system
- ❌ Embeddable status widgets
- ❌ Historical analytics/reporting/trends
- ❌ Bulk operations (batch resolve, batch delete)
- ❌ Per-monitor announcement targeting
- ❌ Approval workflow for announcements
- ❌ Re-opening resolved incidents (Resolved is terminal)

---

## Verification Strategy

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks in this plan MUST be verifiable WITHOUT any human action.

### Test Decision
- **Infrastructure exists**: YES (both Laravel PHPUnit and Flutter test)
- **Automated tests**: TDD (RED-GREEN-REFACTOR) — per AGENTS.md, NON-NEGOTIABLE
- **Framework**: PHPUnit (Laravel), Flutter test (Dart)

### TDD Flow Per Task

**Task Structure:**
1. **RED**: Write failing test first
   - Laravel: `php artisan test --filter=TestName` → FAIL
   - Flutter: `flutter test test/path/file_test.dart` → FAIL
2. **GREEN**: Implement minimum code to pass
   - Same command → PASS
3. **REFACTOR**: Clean up while keeping green
   - Same command → PASS (still)

### Agent-Executed QA Scenarios

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| Backend API | Bash (curl) | Send requests, parse JSON, assert fields |
| Frontend Views | Playwright | Navigate, interact, assert DOM, screenshot |
| Auto-incident | Bash (php artisan test) | Feature test simulating consecutive failures |
| Public page | Playwright | Navigate public URL, verify incident display |
| Scheduled jobs | Bash (php artisan) | Run command, verify DB state changes |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately):
├── Task 1: Backend migrations + models (incidents)
├── Task 6: Backend migrations + models (announcements)
└── Task 9: Flutter enums + models (all)

Wave 2 (After Wave 1):
├── Task 2: Backend incident controller + routes + policies
├── Task 3: Backend auto-incident service
├── Task 7: Backend announcement controller + routes
└── Task 10: Flutter incident controller + views

Wave 3 (After Wave 2):
├── Task 4: Backend auto-incident integration into ProcessCheckResult
├── Task 5: Backend anti-flapping + recovery handling
├── Task 8: Backend scheduled announcements command
├── Task 11: Flutter announcement controller + views
└── Task 12: Flutter status page incident integration

Wave 4 (After Wave 3):
├── Task 13: Public status page integration (backend + Blade)
├── Task 14: Flutter wiring (search, activity, dashboard, translations)
└── Task 15: Monitor settings UI for incident_threshold
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | 2, 3, 4, 5, 13 | 6, 9 |
| 2 | 1 | 4, 13 | 3, 7, 10 |
| 3 | 1 | 4, 5 | 2, 7, 10 |
| 4 | 2, 3 | 5 | 8, 11, 12 |
| 5 | 3, 4 | — | 8, 11, 12 |
| 6 | None | 7, 8, 13 | 1, 9 |
| 7 | 6 | 8, 13 | 2, 3, 10 |
| 8 | 7 | — | 4, 5, 11, 12 |
| 9 | None | 10, 11 | 1, 6 |
| 10 | 2, 9 | 12, 14 | 3, 7 |
| 11 | 7, 9 | — | 4, 5, 12 |
| 12 | 10 | 14 | 4, 5, 8, 11 |
| 13 | 2, 7 | — | 14, 15 |
| 14 | 10, 11 | — | 13, 15 |
| 15 | 1 | — | 13, 14 |

### Agent Dispatch Summary

| Wave | Tasks | Recommended Approach |
|------|-------|---------------------|
| 1 | 1, 6, 9 | 3 parallel agents: backend incidents DB, backend announcements DB, Flutter models |
| 2 | 2, 3, 7, 10 | 4 parallel agents: incident API, auto-service, announcement API, Flutter incident views |
| 3 | 4, 5, 8, 11, 12 | 5 parallel agents |
| 4 | 13, 14, 15 | 3 parallel agents: public page, Flutter wiring, monitor settings |

---

## TODOs

### WAVE 1: Foundation (Models + Migrations)

- [x] 1. Backend: Incident & IncidentUpdate Migrations + Models

  **What to do**:
  
  **Migration: create_incidents_table**
  ```
  - id: uuid, primary key
  - team_id: foreignUuid → teams.id, cascadeOnDelete
  - title: string
  - impact: string (enum: major_outage, partial_outage, degraded_performance, under_maintenance)
  - status: string (enum: investigating, identified, monitoring, resolved), default 'investigating'
  - is_auto_created: boolean, default false
  - started_at: timestamp
  - resolved_at: timestamp, nullable
  - created_at, updated_at, deleted_at (softDeletes)
  ```

  **Migration: create_incident_updates_table**
  ```
  - id: uuid, primary key
  - incident_id: foreignUuid → incidents.id, cascadeOnDelete
  - status: string (investigating, identified, monitoring, resolved)
  - title: string, nullable (optional per-update title)
  - message: text
  - created_at, updated_at
  ```

  **Migration: create_incident_monitor_table (pivot)**
  ```
  - id: uuid, primary key
  - incident_id: foreignUuid → incidents.id, cascadeOnDelete
  - monitor_id: foreignUuid → monitors.id, cascadeOnDelete
  - unique constraint on [incident_id, monitor_id]
  ```

  **Migration: add_incident_threshold_to_monitors**
  ```
  - incident_threshold: unsignedSmallInteger, nullable (null = disabled)
  ```

  **Incident Model** (`back-end/app/Models/Incident.php`):
  - UUID, SoftDeletes, HasFactory, HasUuids
  - Fillable: title, team_id, impact, status, is_auto_created, started_at, resolved_at
  - Casts: impact → string, status → string, is_auto_created → boolean, started_at → datetime, resolved_at → datetime
  - Relations: team(), updates() hasMany(IncidentUpdate), monitors() belongsToMany(Monitor, 'incident_monitor')
  - Scopes: forTeam($teamId), active() [status != 'resolved'], resolved()
  - Computed: isResolved(), duration() (started_at to resolved_at or now)

  **IncidentUpdate Model** (`back-end/app/Models/IncidentUpdate.php`):
  - UUID, HasFactory, HasUuids
  - Fillable: incident_id, status, title, message
  - Casts: status → string
  - Relations: incident() belongsTo(Incident)

  **Must NOT do**:
  - Do NOT add notification dispatching in model events
  - Do NOT add cascade behavior (auto-resolve on model save, etc.)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel model patterns, migration conventions

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 6, 9)
  - **Blocks**: Tasks 2, 3, 4, 5, 13
  - **Blocked By**: None

  **References**:
  
  **Pattern References**:
  - `back-end/app/Models/Monitor.php` — UUID model pattern (HasUuids, $incrementing=false, $keyType='string'), SoftDeletes, team() relation, forTeam scope
  - `back-end/app/Models/StatusPage.php` — BelongsToMany with pivot pattern (monitors relation with StatusPageMonitor pivot class)
  - `back-end/app/Models/MonitorCheck.php` — HasMany child model pattern (for IncidentUpdate)
  - `back-end/database/migrations/` — Existing migration naming and structure patterns
  
  **Why Each Reference Matters**:
  - Monitor.php: Copy exact UUID setup and team-scoping pattern for Incident model
  - StatusPage.php: Copy BelongsToMany pivot pattern for incident-monitor relationship
  - MonitorCheck.php: Copy HasMany child pattern for incident-update relationship

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file: `back-end/tests/Feature/Models/IncidentTest.php`
  - [ ] Test covers: Incident creation with UUID, team scoping, impact/status fields, relationships (updates, monitors)
  - [ ] Test covers: IncidentUpdate creation, linking to incident
  - [ ] Test covers: incident_monitor pivot uniqueness constraint
  - [ ] `php artisan test --filter=IncidentTest` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: Migrations run successfully
    Tool: Bash
    Steps:
      1. php artisan migrate:fresh --seed
      2. Assert: exit code 0
      3. php artisan tinker --execute="Schema::hasTable('incidents')"
      4. Assert: output contains "true"
      5. php artisan tinker --execute="Schema::hasTable('incident_updates')"
      6. Assert: output contains "true"
      7. php artisan tinker --execute="Schema::hasTable('incident_monitor')"
      8. Assert: output contains "true"
      9. php artisan tinker --execute="Schema::hasColumn('monitors', 'incident_threshold')"
      10. Assert: output contains "true"
    Expected Result: All 4 tables created, monitors column added
  ```

  **Commit**: YES
  - Message: `feat(incidents): add incident and incident_update migrations and models`
  - Files: `back-end/database/migrations/*incidents*.php`, `back-end/database/migrations/*incident_updates*.php`, `back-end/database/migrations/*incident_monitor*.php`, `back-end/database/migrations/*incident_threshold*.php`, `back-end/app/Models/Incident.php`, `back-end/app/Models/IncidentUpdate.php`, `back-end/tests/Feature/Models/IncidentTest.php`
  - Pre-commit: `cd back-end && php artisan test --filter=IncidentTest`

---

- [x] 6. Backend: Announcement Migration + Model

  **What to do**:

  **Migration: create_announcements_table**
  ```
  - id: uuid, primary key
  - team_id: foreignUuid → teams.id, cascadeOnDelete
  - status_page_id: foreignUuid → status_pages.id, cascadeOnDelete
  - title: string
  - body: text
  - type: string (enum: maintenance, improvement, informational)
  - scheduled_at: timestamp, nullable (for future-dated announcements)
  - published_at: timestamp, nullable
  - ended_at: timestamp, nullable
  - created_at, updated_at, deleted_at (softDeletes)
  ```

  **Announcement Model** (`back-end/app/Models/Announcement.php`):
  - UUID, SoftDeletes, HasFactory, HasUuids
  - Fillable: team_id, status_page_id, title, body, type, scheduled_at, published_at, ended_at
  - Casts: type → string, scheduled_at → datetime, published_at → datetime, ended_at → datetime
  - Relations: team(), statusPage() belongsTo(StatusPage)
  - Scopes: forTeam($teamId), published() [published_at != null AND (ended_at == null OR ended_at > now)], scheduled() [scheduled_at > now AND published_at == null], forStatusPage($statusPageId)
  - Computed: isActive(), isScheduled(), isEnded()

  **Must NOT do**:
  - Do NOT add rich text/markdown processing
  - Do NOT add per-monitor targeting
  - Do NOT add approval workflow

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel model patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 9)
  - **Blocks**: Tasks 7, 8, 13
  - **Blocked By**: None

  **References**:
  
  **Pattern References**:
  - `back-end/app/Models/StatusPage.php` — Parent model pattern for BelongsTo relation
  - `back-end/app/Models/Monitor.php` — UUID model pattern, team scoping, SoftDeletes

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file: `back-end/tests/Feature/Models/AnnouncementTest.php`
  - [ ] Test covers: Announcement creation, type enum values, status page relation, scopes (published, scheduled, forStatusPage)
  - [ ] `php artisan test --filter=AnnouncementTest` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: Announcement migration runs correctly
    Tool: Bash
    Steps:
      1. php artisan migrate
      2. Assert: exit code 0
      3. php artisan tinker --execute="Schema::hasTable('announcements')"
      4. Assert: output contains "true"
    Expected Result: announcements table created
  ```

  **Commit**: YES
  - Message: `feat(announcements): add announcement migration and model`
  - Files: `back-end/database/migrations/*announcements*.php`, `back-end/app/Models/Announcement.php`, `back-end/tests/Feature/Models/AnnouncementTest.php`
  - Pre-commit: `cd back-end && php artisan test --filter=AnnouncementTest`

---

- [x] 9. Flutter: Enums + Models (Incident, IncidentUpdate, Announcement)

  **What to do**:

  **Enums** (4 new files in `lib/app/enums/`):
  
  `incident_impact.dart`:
  - Values: majorOutage('major_outage', 'Major Outage'), partialOutage('partial_outage', 'Partial Outage'), degradedPerformance('degraded_performance', 'Degraded Performance'), underMaintenance('under_maintenance', 'Under Maintenance')
  - Pattern: value, label, fromValue(String?), selectOptions
  - Add color getter: majorOutage → red, partialOutage → orange, degradedPerformance → yellow, underMaintenance → blue
  - Add icon getter: majorOutage → 'close' (x mark), partialOutage → 'warning', degradedPerformance → 'speed', underMaintenance → 'build'

  `incident_status.dart`:
  - Values: investigating('investigating', 'Investigating'), identified('identified', 'Identified'), monitoring('monitoring', 'Monitoring'), resolved('resolved', 'Resolved')
  - Pattern: value, label, fromValue(String?), selectOptions
  - Add color getter: investigating → gray, identified → orange, monitoring → blue, resolved → green

  `announcement_type.dart`:
  - Values: maintenance('maintenance', 'Maintenance'), improvement('improvement', 'Improvement'), informational('informational', 'Informational')
  - Pattern: value, label, fromValue(String?), selectOptions
  - Add color and icon getters

  **Models** (3 new files in `lib/app/models/`):

  `incident.dart`:
  - Extends Model with HasTimestamps, InteractsWithPersistence
  - table: 'incidents', resource: 'incidents', incrementing: false
  - Getters: id, title, impact (IncidentImpact), status (IncidentStatus), isAutoCreated(bool), startedAt(Carbon), resolvedAt(Carbon?), monitorIds(List<String>), monitors(List<Monitor>), updates(List<IncidentUpdate>)
  - Setters: title, impact, status, monitorIds
  - Computed: isResolved, isActive, duration (Duration)
  - Static: find(String id), all()

  `incident_update.dart`:
  - Extends Model with HasTimestamps
  - Getters: id, incidentId, status(IncidentStatus), title(String?), message
  - Setters: status, title, message

  `announcement.dart`:
  - Extends Model with HasTimestamps, InteractsWithPersistence
  - table: 'announcements', resource: 'announcements', incrementing: false (NOTE: actual API path will be nested under status-pages)
  - Getters: id, statusPageId, title, body, type(AnnouncementType), scheduledAt(Carbon?), publishedAt(Carbon?), endedAt(Carbon?)
  - Setters: title, body, type, scheduledAt, publishedAt, endedAt
  - Computed: isActive, isScheduled, isEnded

  **Must NOT do**:
  - Do NOT use `as int` anywhere — use safe num casts
  - Do NOT use package imports within lib/

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Eloquent model patterns, enum patterns in Flutter

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 6)
  - **Blocks**: Tasks 10, 11
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `lib/app/models/monitor.dart` — Model pattern: extends Model + HasTimestamps + InteractsWithPersistence, typed getters via get<T>('key'), enum getters via EnumType.fromValue(), Carbon.parse for dates
  - `lib/app/models/status_page.dart` — Model with fromMap factory, relation list mapping (monitors getter maps raw list to Model objects)
  - `lib/app/enums/monitor_status.dart` — Enum pattern: value, label, fromValue(String?), selectOptions using firstWhere

  **Test References**:
  - `test/app/models/` — Existing model test patterns
  - `test/app/enums/` — Existing enum test patterns

  **Acceptance Criteria**:

  **TDD:**
  - [ ] `flutter test test/app/enums/incident_impact_test.dart` → PASS
  - [ ] `flutter test test/app/enums/incident_status_test.dart` → PASS
  - [ ] `flutter test test/app/enums/announcement_type_test.dart` → PASS
  - [ ] `flutter test test/app/models/incident_test.dart` → PASS (fromMap, typed getters, enum mapping, computed properties)
  - [ ] `flutter test test/app/models/incident_update_test.dart` → PASS
  - [ ] `flutter test test/app/models/announcement_test.dart` → PASS

  **Commit**: YES
  - Message: `feat(flutter): add incident and announcement enums and models`
  - Files: `lib/app/enums/incident_impact.dart`, `lib/app/enums/incident_status.dart`, `lib/app/enums/announcement_type.dart`, `lib/app/models/incident.dart`, `lib/app/models/incident_update.dart`, `lib/app/models/announcement.dart`, `test/app/enums/*`, `test/app/models/*`
  - Pre-commit: `flutter test test/app/enums/ test/app/models/`

---

### WAVE 2: API Controllers + Flutter Views

- [ ] 2. Backend: Incident API Controller + Routes + Policy

  **What to do**:

  **IncidentPolicy** (`back-end/app/Policies/IncidentPolicy.php`):
  - viewAny: team member check
  - view: team member + incident belongs to team
  - create: team member check
  - update: team member + incident belongs to team + NOT resolved
  - delete: team member + incident belongs to team

  **Form Requests**:
  - `StoreIncidentRequest`: title (required, string, max:255), impact (required, in:major_outage,partial_outage,degraded_performance,under_maintenance), message (required, string — for initial update), monitor_ids (required, array, min:1), monitor_ids.* (uuid, exists:monitors,id)
  - `UpdateIncidentRequest`: title (sometimes, string, max:255), impact (sometimes, in:...), status (sometimes, in:investigating,identified,monitoring,resolved)
  - `StoreIncidentUpdateRequest`: status (required, in:investigating,identified,monitoring,resolved), title (nullable, string, max:255), message (required, string)

  **IncidentResource** (`back-end/app/Http/Resources/IncidentResource.php`):
  - Return: id, title, impact, status, is_auto_created, started_at, resolved_at, created_at, updated_at
  - Conditional: monitors (MonitorResource collection), updates (IncidentUpdateResource collection, ordered by created_at desc)

  **IncidentController** (`back-end/app/Http/Controllers/Api/V1/IncidentController.php`):
  - index: List incidents for current team (with monitors and updates eager-loaded), paginated, filter by status
  - store: Create incident + initial IncidentUpdate (from 'message') + attach monitor_ids + bust cache for affected status pages
  - show: Single incident with updates and monitors
  - update: Update incident fields + if status changed to 'resolved', set resolved_at + bust cache
  - destroy: Soft delete incident + bust cache

  **Additional endpoint — addUpdate**:
  - POST /incidents/{incident}/updates
  - Create IncidentUpdate + update incident status to match + if 'resolved', set resolved_at + bust cache

  **Cache busting logic**: When incident is created/updated/resolved:
  ```php
  // Find all status pages that share monitors with this incident
  $monitorIds = $incident->monitors()->pluck('monitors.id');
  $statusPageSlugs = StatusPage::whereHas('monitors', fn($q) => $q->whereIn('monitors.id', $monitorIds))->pluck('slug');
  foreach ($statusPageSlugs as $slug) {
      Cache::forget("status_page_{$slug}");
  }
  ```

  **Routes** (add to `back-end/routes/api/v1.php` after status-pages block):
  ```php
  Route::apiResource('incidents', IncidentController::class);
  Route::post('incidents/{incident}/updates', [IncidentController::class, 'addUpdate']);
  ```

  **Must NOT do**:
  - Do NOT add notification dispatching
  - Do NOT add bulk operations
  - Do NOT cross-wire with alert rules

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel controller, form request, resource, policy patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 3, 7, 10)
  - **Blocks**: Tasks 4, 13
  - **Blocked By**: Task 1

  **References**:

  **Pattern References**:
  - `back-end/app/Http/Controllers/Api/V1/StatusPageController.php` — Controller pattern: apiResource actions, team scoping via `$request->user()->current_team_id`, eager loading, pagination
  - `back-end/app/Http/Controllers/Api/V1/MonitorController.php` — Additional action pattern (test, pause, resume) for the addUpdate endpoint
  - `back-end/app/Policies/` — Policy pattern (check team membership)
  - `back-end/app/Http/Requests/` — Form request pattern
  - `back-end/app/Http/Resources/` — API Resource pattern
  - `back-end/routes/api/v1.php:L87-109` — Route registration pattern (apiResource + additional routes)

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file: `back-end/tests/Feature/Api/V1/IncidentApiTest.php`
  - [ ] Tests cover: CRUD operations, validation, authorization (team isolation), addUpdate endpoint, status transitions, resolved_at auto-set, cache busting
  - [ ] `php artisan test --filter=IncidentApiTest` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: Full incident lifecycle via API
    Tool: Bash (curl)
    Preconditions: Server running, authenticated user with team and monitors
    Steps:
      1. POST /api/v1/incidents {"title":"API Down","impact":"major_outage","message":"Investigating the issue","monitor_ids":["<monitor-uuid>"]}
      2. Assert: status 201, response has id (UUID), status = "investigating"
      3. GET /api/v1/incidents/{id}
      4. Assert: status 200, has updates array with 1 entry (investigating), has monitors array
      5. POST /api/v1/incidents/{id}/updates {"status":"identified","message":"Root cause found"}
      6. Assert: status 201
      7. POST /api/v1/incidents/{id}/updates {"status":"resolved","message":"Fix deployed"}
      8. Assert: status 201
      9. GET /api/v1/incidents/{id}
      10. Assert: status = "resolved", resolved_at is not null, updates has 3 entries
    Expected Result: Complete incident lifecycle works

  Scenario: Team isolation prevents cross-team access
    Tool: Bash (curl)
    Steps:
      1. Create incident as Team A user
      2. GET /api/v1/incidents/{id} as Team B user
      3. Assert: status 403 or 404
    Expected Result: Cross-team access blocked
  ```

  **Commit**: YES
  - Message: `feat(incidents): add incident API controller, routes, policy, and resources`
  - Files: `back-end/app/Http/Controllers/Api/V1/IncidentController.php`, `back-end/app/Policies/IncidentPolicy.php`, `back-end/app/Http/Requests/StoreIncidentRequest.php`, `back-end/app/Http/Requests/UpdateIncidentRequest.php`, `back-end/app/Http/Requests/StoreIncidentUpdateRequest.php`, `back-end/app/Http/Resources/IncidentResource.php`, `back-end/app/Http/Resources/IncidentUpdateResource.php`, `back-end/routes/api/v1.php`, `back-end/tests/Feature/Api/V1/IncidentApiTest.php`
  - Pre-commit: `cd back-end && php artisan test --filter=IncidentApiTest`

---

- [ ] 3. Backend: IncidentAutoCreationService

  **What to do**:

  Create `back-end/app/Services/IncidentAutoCreationService.php`:
  
  ```
  class IncidentAutoCreationService
  {
      public function evaluate(Monitor $monitor): void
      {
          // 1. Check if incident_threshold is configured (not null)
          // 2. Check if consecutive_fails >= incident_threshold
          // 3. Check cooldown: no auto-incident for this monitor in last 30 min
          //    → Incident::where('is_auto_created', true)
          //      ->whereHas('monitors', fn($q) => $q->where('monitors.id', $monitor->id))
          //      ->where('created_at', '>', now()->subMinutes(30))
          //      ->exists()
          // 4. If all pass, create incident:
          //    - title: "{monitor->name} is down"
          //    - impact: 'partial_outage' (safe default for auto)
          //    - status: 'investigating'
          //    - is_auto_created: true
          //    - started_at: now()
          //    - team_id: monitor->team_id
          // 5. Create initial IncidentUpdate:
          //    - status: 'investigating'
          //    - message: "We are currently investigating this issue."
          // 6. Attach monitor to incident (pivot)
          // 7. Bust cache for affected status pages
      }

      public function handleRecovery(Monitor $monitor): void
      {
          // 1. Find active auto-created incidents linked to this monitor
          //    → Incident::where('is_auto_created', true)
          //      ->where('status', '!=', 'resolved')
          //      ->whereHas('monitors', fn($q) => $q->where('monitors.id', $monitor->id))
          //      ->get()
          // 2. For each: create IncidentUpdate with status='monitoring', message="The service appears to be recovering."
          // 3. Do NOT auto-resolve (leave for human confirmation)
          // 4. Bust cache
      }
  }
  ```

  Use DB locking to prevent duplicate auto-incidents from concurrent check results:
  ```php
  DB::transaction(function () use ($monitor) {
      // Lock check prevents race condition
      $exists = Incident::lockForUpdate()
          ->where('is_auto_created', true)
          ->whereHas('monitors', fn($q) => $q->where('monitors.id', $monitor->id))
          ->where('status', '!=', 'resolved')
          ->exists();
      if ($exists) return; // Already has an active auto-incident
      // ... create incident
  });
  ```

  **Must NOT do**:
  - Do NOT auto-resolve incidents (only add "Monitoring" update on recovery)
  - Do NOT dispatch notifications
  - Do NOT couple to alert rule system

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel service patterns, DB transactions

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 2, 7, 10)
  - **Blocks**: Tasks 4, 5
  - **Blocked By**: Task 1

  **References**:

  **Pattern References**:
  - `back-end/app/Services/` — Existing service class patterns
  - `back-end/app/Jobs/ProcessCheckResult.php:L105-107` — consecutive_fails tracking logic (the trigger point)
  - `back-end/app/Models/Monitor.php` — team_id field, consecutive_fails field

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file: `back-end/tests/Feature/Services/IncidentAutoCreationServiceTest.php`
  - [ ] Tests: threshold check, cooldown period (no duplicate within 30min), incident creation with correct fields, initial update creation, monitor attachment, recovery handling (adds "monitoring" update, does NOT resolve), race condition protection
  - [ ] `php artisan test --filter=IncidentAutoCreationServiceTest` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: Auto-incident creation when threshold exceeded
    Tool: Bash (php artisan test)
    Steps:
      1. Create monitor with incident_threshold=3
      2. Simulate 3 consecutive failures (set consecutive_fails=3)
      3. Call service.evaluate(monitor)
      4. Assert: Incident created with is_auto_created=true, status='investigating'
      5. Assert: IncidentUpdate exists with message containing "investigating"
      6. Assert: Monitor attached to incident
    Expected Result: Auto-incident created correctly

  Scenario: Cooldown prevents duplicate auto-incidents
    Tool: Bash (php artisan test)
    Steps:
      1. Create auto-incident for monitor (created 10 min ago)
      2. Call service.evaluate(monitor) again
      3. Assert: No new incident created
    Expected Result: Cooldown blocks duplicate
  ```

  **Commit**: YES
  - Message: `feat(incidents): add auto-creation service with cooldown and recovery`
  - Files: `back-end/app/Services/IncidentAutoCreationService.php`, `back-end/tests/Feature/Services/IncidentAutoCreationServiceTest.php`
  - Pre-commit: `cd back-end && php artisan test --filter=IncidentAutoCreationServiceTest`

---

- [ ] 7. Backend: Announcement API Controller + Routes + Policy

  **What to do**:

  **AnnouncementPolicy** (`back-end/app/Policies/AnnouncementPolicy.php`):
  - viewAny: team member check
  - view: team member + announcement belongs to team
  - create: team member + status page belongs to team
  - update: team member + announcement belongs to team
  - delete: team member + announcement belongs to team

  **Form Requests**:
  - `StoreAnnouncementRequest`: title (required, string, max:255), body (required, string), type (required, in:maintenance,improvement,informational), scheduled_at (nullable, date, after:now), published_at (nullable, date), ended_at (nullable, date, after:published_at)
  - `UpdateAnnouncementRequest`: same fields but 'sometimes' instead of 'required'

  **AnnouncementResource**:
  - Return: id, status_page_id, title, body, type, scheduled_at, published_at, ended_at, created_at, updated_at

  **AnnouncementController** (`back-end/app/Http/Controllers/Api/V1/AnnouncementController.php`):
  - Nested under status-pages: `/status-pages/{statusPage}/announcements`
  - index: List announcements for given status page (team-scoped)
  - store: Create announcement, if published_at not set and scheduled_at not set → auto-set published_at to now, bust cache
  - show: Single announcement
  - update: Update announcement, bust cache
  - destroy: Soft delete, bust cache

  **Routes**:
  ```php
  Route::apiResource('status-pages.announcements', AnnouncementController::class)
      ->parameters(['status-pages' => 'statusPage']);
  ```

  Add hasMany relationship to StatusPage model:
  ```php
  public function announcements() { return $this->hasMany(Announcement::class); }
  ```

  **Must NOT do**:
  - Do NOT add rich text processing
  - Do NOT add approval workflow
  - Do NOT add per-monitor targeting

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 2, 3, 10)
  - **Blocks**: Tasks 8, 13
  - **Blocked By**: Task 6

  **References**:

  **Pattern References**:
  - `back-end/app/Http/Controllers/Api/V1/StatusPageController.php` — Controller pattern for nested resources
  - `back-end/routes/api/v1.php:L97-109` — Nested route pattern (status-pages with parameters)
  - `back-end/app/Models/StatusPage.php` — Parent model for announcement relation

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file: `back-end/tests/Feature/Api/V1/AnnouncementApiTest.php`
  - [ ] Tests: CRUD operations, team isolation, status page scoping, auto-publish on creation, scheduled_at validation, cache busting
  - [ ] `php artisan test --filter=AnnouncementApiTest` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: CRUD announcement lifecycle
    Tool: Bash (curl)
    Steps:
      1. POST /api/v1/status-pages/{spId}/announcements {"title":"v2.0 Released","body":"New features...","type":"improvement"}
      2. Assert: status 201, published_at is not null (auto-published)
      3. GET /api/v1/status-pages/{spId}/announcements
      4. Assert: list contains the created announcement
      5. PUT /api/v1/status-pages/{spId}/announcements/{id} {"title":"v2.0.1 Released"}
      6. Assert: status 200, title updated
      7. DELETE /api/v1/status-pages/{spId}/announcements/{id}
      8. Assert: status 204
    Expected Result: Full CRUD works

  Scenario: Scheduled announcement not auto-published
    Tool: Bash (curl)
    Steps:
      1. POST announcement with scheduled_at in future
      2. Assert: published_at is null
    Expected Result: Scheduled announcements wait for their time
  ```

  **Commit**: YES
  - Message: `feat(announcements): add announcement API controller, routes, and policy`
  - Files: `back-end/app/Http/Controllers/Api/V1/AnnouncementController.php`, `back-end/app/Policies/AnnouncementPolicy.php`, `back-end/app/Http/Requests/Store/UpdateAnnouncementRequest.php`, `back-end/app/Http/Resources/AnnouncementResource.php`, `back-end/routes/api/v1.php`, `back-end/tests/Feature/Api/V1/AnnouncementApiTest.php`
  - Pre-commit: `cd back-end && php artisan test --filter=AnnouncementApiTest`

---

- [ ] 10. Flutter: Incident Controller + Views

  **What to do**:

  **IncidentController** (`lib/app/controllers/incident_controller.dart`):
  - Singleton via `Magic.findOrPut<IncidentController>`
  - Notifiers: `incidentsNotifier` (List<Incident>), `selectedIncidentNotifier` (Incident?)
  - Actions: index(), create(), show(), edit()
  - CRUD: loadIncidents(), loadIncident(id), store({title, impact, message, monitorIds}), update(id, {...}), destroy(id), addUpdate(incidentId, {status, title, message})
  - Pattern: setLoading → try/Http → setSuccess+toast+navigate | catch → Log.error+setError

  **Views** (in `lib/resources/views/incidents/`):
  
  `incidents_index_view.dart`:
  - AppPageHeader with title "Incidents" and "Create Incident" button
  - Filter tabs: All / Active / Resolved
  - List of incident cards showing: title, impact badge (colored), status badge, duration, monitor names, created_at
  - Empty state when no incidents
  
  `incident_create_view.dart`:
  - Form with: title (WFormInput), impact (WFormSelect with IncidentImpact.selectOptions), monitors (WFormMultiSelect with available monitors), initial message (WFormInput textarea)
  - Submit creates incident via controller.store()
  
  `incident_show_view.dart` (THE KEY VIEW — timeline):
  - Header: title, impact badge, status badge, duration
  - Affected monitors list (chips/badges)
  - "Add Update" button (opens inline form or modal)
  - Timeline: reverse chronological list of IncidentUpdates
    - Each update: status badge (colored) + title (optional) + message + timestamp
    - Visual timeline connector (vertical line between updates)
  - If not resolved: show "Add Update" form inline with status select + message input
  
  `incident_edit_view.dart`:
  - Edit title, impact, monitor associations
  - Cannot edit status here (use addUpdate for that)

  **Route registration** (update `lib/routes/app.dart`):
  - Replace "Coming Soon" placeholder at `/incidents`
  - Add: /incidents → index, /incidents/create → create, /incidents/:id → show, /incidents/:id/edit → edit

  **Must NOT do**:
  - Do NOT add comment/discussion features
  - Do NOT add incident templates
  - Do NOT build rich text editors

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`wind-ui`, `magic-framework`, `flutter-design`, `mobile-app-design-mastery`]
    - `wind-ui`: All views use Wind UI widgets (WDiv, WText, WButton, WFormInput, etc.)
    - `magic-framework`: Controller pattern, Http facade, routing
    - `flutter-design`: Theming, color scheme, typography
    - `mobile-app-design-mastery`: Mobile UI patterns, touch targets, spacing

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 2, 3, 7)
  - **Blocks**: Tasks 12, 14
  - **Blocked By**: Tasks 2 (API must exist), 9 (models must exist)

  **References**:

  **Pattern References**:
  - `lib/app/controllers/status_page_controller.dart` — Singleton controller pattern: Magic.findOrPut, ValueNotifier, MagicStateMixin, CRUD actions, dispose
  - `lib/resources/views/status_pages/status_pages_index_view.dart` — Index view pattern with AppPageHeader, list rendering
  - `lib/resources/views/status_pages/status_page_create_view.dart` — Create form pattern with WFormInput, WFormSelect, validation
  - `lib/resources/views/status_pages/status_page_show_view.dart` — Show view with detail display, related data
  - `lib/resources/views/monitors/monitor_show_view.dart` — Complex show view with tabs and detail sections
  - `lib/resources/views/components/app_page_header.dart` — Page header component
  - `lib/resources/views/components/app_card.dart` — Card component
  
  **API References**:
  - Task 2 output: Incident API endpoints (CRUD + addUpdate)

  **Acceptance Criteria**:

  **TDD:**
  - [ ] `flutter test test/app/controllers/incident_controller_test.dart` → PASS
  - [ ] `flutter test test/resources/views/incidents/` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: Navigate to incidents page
    Tool: Playwright
    Preconditions: App running, user logged in
    Steps:
      1. Navigate to /incidents
      2. Wait for page load (timeout: 10s)
      3. Assert: Page header contains "Incidents"
      4. Assert: "Create Incident" button is visible
      5. Screenshot: .sisyphus/evidence/task-10-incidents-index.png
    Expected Result: Incidents index page loads

  Scenario: Create incident flow
    Tool: Playwright
    Steps:
      1. Navigate to /incidents/create
      2. Fill title input with "API Service Down"
      3. Select impact: "Major Outage"
      4. Select monitors (at least 1)
      5. Fill message with "We are investigating the issue"
      6. Click submit button
      7. Wait for navigation to /incidents/{id}
      8. Assert: Incident show page displays title "API Service Down"
      9. Assert: Timeline shows "Investigating" update
      10. Screenshot: .sisyphus/evidence/task-10-incident-create.png
    Expected Result: Incident created and timeline shown
  ```

  **Commit**: YES
  - Message: `feat(flutter): add incident controller and views (CRUD + timeline)`
  - Files: `lib/app/controllers/incident_controller.dart`, `lib/resources/views/incidents/*`, `lib/routes/app.dart`
  - Pre-commit: `flutter test test/app/controllers/incident_controller_test.dart`

---

### WAVE 3: Integration + Advanced Features

- [ ] 4. Backend: Integrate IncidentAutoCreationService into ProcessCheckResult

  **What to do**:
  
  Modify `back-end/app/Jobs/ProcessCheckResult.php`:
  
  After the monitor update block (after line ~113 where consecutive_fails is updated):
  ```php
  // After monitor->update([...])
  $autoIncidentService = app(IncidentAutoCreationService::class);
  
  if ($status === 'down') {
      $autoIncidentService->evaluate($this->monitor->fresh());
  } elseif ($status === 'up' && $this->monitor->consecutive_fails > 0) {
      // Monitor just recovered (was down, now up)
      $autoIncidentService->handleRecovery($this->monitor);
  }
  ```

  Register IncidentAutoCreationService in a service provider (or use auto-resolution).

  **Must NOT do**:
  - Do NOT put the full incident logic in the job — call the service only
  - Do NOT modify existing status determination logic
  - Do NOT add notification dispatching

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 3 (sequential within — must run after Task 2 and 3)
  - **Blocks**: Task 5
  - **Blocked By**: Tasks 2, 3

  **References**:

  **Pattern References**:
  - `back-end/app/Jobs/ProcessCheckResult.php:L100-115` — Monitor update block, consecutive_fails logic, existing evaluateAlerts() call pattern
  - `back-end/app/Services/IncidentAutoCreationService.php` — Service to call (from Task 3)

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file: `back-end/tests/Feature/Jobs/ProcessCheckResultAutoIncidentTest.php`
  - [ ] Tests: consecutive failures at threshold triggers auto-incident, below threshold does NOT trigger, recovery triggers monitoring update, service is called correctly
  - [ ] `php artisan test --filter=ProcessCheckResultAutoIncidentTest` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: End-to-end auto-incident on consecutive failures
    Tool: Bash
    Steps:
      1. Create monitor with incident_threshold=3
      2. Simulate 3 consecutive ProcessCheckResult jobs with 'down' status
      3. Assert: After 3rd job, Incident exists in DB with is_auto_created=true
      4. Assert: IncidentUpdate exists with status='investigating'
    Expected Result: Auto-incident created after threshold met
  ```

  **Commit**: YES (groups with Task 5)
  - Message: `feat(incidents): integrate auto-incident service into ProcessCheckResult`
  - Files: `back-end/app/Jobs/ProcessCheckResult.php`, `back-end/tests/Feature/Jobs/ProcessCheckResultAutoIncidentTest.php`
  - Pre-commit: `cd back-end && php artisan test --filter=ProcessCheckResult`

---

- [ ] 5. Backend: Anti-Flapping Protection + Recovery Handling Tests

  **What to do**:
  
  Comprehensive integration testing for edge cases:
  
  1. **Anti-flapping test**: Monitor goes down → auto-incident created → monitor goes up → monitor goes down again within 30 min → NO new auto-incident
  2. **Recovery test**: Auto-incident exists → monitor recovers → "Monitoring" update added → incident still NOT resolved
  3. **Threshold disabled test**: Monitor with incident_threshold=null → no auto-incidents regardless of consecutive_fails
  4. **Multiple monitors same incident test**: Verify only one incident per monitor at a time
  5. **Race condition test**: Simulate concurrent evaluate() calls → only one incident created

  **Must NOT do**:
  - Do NOT change cooldown period without updating all tests
  - Do NOT auto-resolve incidents in any scenario

  **Recommended Agent Profile**:
  - **Category**: `unspecified-low`
  - **Skills**: [`magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Wave 3 (after Task 4)
  - **Blocks**: None
  - **Blocked By**: Tasks 3, 4

  **References**:
  - `back-end/app/Services/IncidentAutoCreationService.php` — Service under test
  - `back-end/tests/Feature/Services/IncidentAutoCreationServiceTest.php` — Existing tests to extend

  **Acceptance Criteria**:
  - [ ] `php artisan test --filter=IncidentAutoCreationServiceTest` → PASS (all edge cases)
  - [ ] Tests verify: flapping protection, recovery behavior, threshold disabled, concurrent safety

  **Commit**: YES (grouped with Task 4)
  - Message: `test(incidents): comprehensive edge case tests for auto-incident service`
  - Files: `back-end/tests/Feature/Services/IncidentAutoCreationServiceTest.php`

---

- [ ] 8. Backend: Scheduled Announcements Command

  **What to do**:

  **Artisan Command** (`back-end/app/Console/Commands/ProcessScheduledAnnouncements.php`):
  - Command: `announcements:process-scheduled`
  - Logic:
    1. Find announcements where `scheduled_at <= now()` AND `published_at IS NULL`
    2. Set `published_at = now()`
    3. Bust cache for the announcement's status page
    4. Find announcements where `ended_at <= now()` AND `ended_at IS NOT NULL` AND still showing as active
    5. Mark as ended (ended_at already set, no action needed but bust cache)

  **Register in scheduler** (`back-end/routes/console.php` or `app/Console/Kernel.php`):
  ```php
  Schedule::command('announcements:process-scheduled')->everyMinute();
  ```

  **Must NOT do**:
  - Do NOT add notification sending
  - Do NOT add complex timezone handling (store UTC, display in user timezone)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Tasks 4, 5, 11, 12)
  - **Blocks**: None
  - **Blocked By**: Task 7

  **References**:
  - `back-end/routes/console.php` — Existing scheduler registration (ScheduleMonitorChecks::class runs everyMinute)
  - `back-end/app/Console/Commands/ScheduleMonitorChecks.php` — Artisan command pattern

  **Acceptance Criteria**:

  **TDD:**
  - [ ] Test file: `back-end/tests/Feature/Commands/ProcessScheduledAnnouncementsTest.php`
  - [ ] Tests: scheduled announcement gets published when time arrives, not-yet-scheduled stays unpublished, cache busted on publish
  - [ ] `php artisan test --filter=ProcessScheduledAnnouncementsTest` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: Scheduled announcement auto-publishes
    Tool: Bash
    Steps:
      1. Create announcement with scheduled_at = 5 minutes ago, published_at = null
      2. php artisan announcements:process-scheduled
      3. Assert: Announcement now has published_at set
    Expected Result: Scheduled announcement published on time
  ```

  **Commit**: YES
  - Message: `feat(announcements): add scheduled announcements processing command`
  - Files: `back-end/app/Console/Commands/ProcessScheduledAnnouncements.php`, `back-end/routes/console.php`, `back-end/tests/Feature/Commands/ProcessScheduledAnnouncementsTest.php`
  - Pre-commit: `cd back-end && php artisan test --filter=ProcessScheduledAnnouncementsTest`

---

- [ ] 11. Flutter: Announcement Controller + Views

  **What to do**:

  **AnnouncementController** (`lib/app/controllers/announcement_controller.dart`):
  - Singleton via `Magic.findOrPut<AnnouncementController>`
  - Notifiers: `announcementsNotifier` (List<Announcement>), `selectedAnnouncementNotifier` (Announcement?)
  - Context: statusPageId required for API calls (nested resource)
  - CRUD: loadAnnouncements(statusPageId), store(statusPageId, {title, body, type, scheduledAt?}), update(statusPageId, id, {...}), destroy(statusPageId, id)
  - API paths: Http.get('/status-pages/$statusPageId/announcements'), etc.

  **Views** (in `lib/resources/views/announcements/` or nested under status_pages):
  
  `announcements_index_view.dart`:
  - Accessible from within StatusPage show view (tab or section)
  - List of announcement cards: title, type badge (colored), status (scheduled/published/ended), dates
  - "Create Announcement" button

  `announcement_create_view.dart`:
  - Form: title (WFormInput), body (WFormInput textarea), type (WFormSelect), scheduled_at (WFormDatePicker, optional)
  - If scheduled_at not set → publishes immediately

  `announcement_edit_view.dart`:
  - Same form, pre-filled

  **Route integration**: Announcements are accessed via status page context. Routes could be:
  - `/status-pages/:id/announcements` → index
  - `/status-pages/:id/announcements/create` → create
  - `/status-pages/:id/announcements/:announcementId/edit` → edit
  
  OR integrated as a tab within the status page show view.

  **Must NOT do**:
  - Do NOT add rich text editor
  - Do NOT add per-monitor targeting

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`wind-ui`, `magic-framework`, `flutter-design`, `mobile-app-design-mastery`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Tasks 4, 5, 8, 12)
  - **Blocks**: Task 14
  - **Blocked By**: Tasks 7 (API), 9 (models)

  **References**:
  - `lib/app/controllers/status_page_controller.dart` — Controller singleton pattern
  - `lib/resources/views/status_pages/` — View patterns, component usage
  - `lib/resources/views/monitors/` — List/detail view patterns

  **Acceptance Criteria**:

  **TDD:**
  - [ ] `flutter test test/app/controllers/announcement_controller_test.dart` → PASS

  **Agent-Executed QA:**
  ```
  Scenario: Create announcement from status page
    Tool: Playwright
    Steps:
      1. Navigate to /status-pages/{id}
      2. Find "Announcements" section/tab
      3. Click "Create Announcement"
      4. Fill title: "Scheduled Maintenance"
      5. Fill body: "We will perform maintenance on Feb 10"
      6. Select type: "Maintenance"
      7. Set scheduled date (optional)
      8. Click submit
      9. Assert: Announcement appears in list
      10. Screenshot: .sisyphus/evidence/task-11-announcement-create.png
    Expected Result: Announcement created and visible
  ```

  **Commit**: YES
  - Message: `feat(flutter): add announcement controller and views`
  - Files: `lib/app/controllers/announcement_controller.dart`, `lib/resources/views/announcements/*`, `lib/routes/app.dart`

---

- [ ] 12. Flutter: Status Page Incident Integration (Admin Side)

  **What to do**:

  Update `lib/resources/views/status_pages/status_page_show_view.dart` to include:
  
  1. **Active Incidents Section**: Show active incidents linked to this status page's monitors
     - Query: Fetch incidents where incident.monitors ∩ statusPage.monitors ≠ ∅
     - Display: Incident cards with title, impact badge, status, duration
     - "View Incident" link to /incidents/:id
  
  2. **Recent Incident History**: Last 15 days of incidents (resolved + active)
     - Timeline format similar to Claude's status page
     - Grouped by date
  
  3. **Announcements Tab/Section**: Link to announcements for this status page

  The incident data can be fetched via a new API endpoint or parameter:
  - Option: Add `?include=incidents` to status page show endpoint
  - Or: Separate API call from Flutter controller

  **Must NOT do**:
  - Do NOT add embeddable widgets
  - Do NOT add analytics/trends

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`wind-ui`, `flutter-design`, `mobile-app-design-mastery`, `magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Tasks 4, 5, 8, 11)
  - **Blocks**: Task 14
  - **Blocked By**: Task 10

  **References**:
  - `lib/resources/views/status_pages/status_page_show_view.dart` — The view to modify
  - `lib/app/controllers/status_page_controller.dart` — May need new methods for incident data
  - `lib/app/controllers/incident_controller.dart` — Incident controller from Task 10

  **Acceptance Criteria**:

  **Agent-Executed QA:**
  ```
  Scenario: Status page shows active incidents
    Tool: Playwright
    Steps:
      1. Create an incident linked to a monitor on a status page
      2. Navigate to /status-pages/{id}
      3. Assert: "Active Incidents" section visible
      4. Assert: Incident title and impact badge shown
      5. Screenshot: .sisyphus/evidence/task-12-sp-incidents.png
    Expected Result: Active incidents displayed on status page
  ```

  **Commit**: YES
  - Message: `feat(flutter): integrate incidents into status page view`
  - Files: `lib/resources/views/status_pages/status_page_show_view.dart`

---

### WAVE 4: Public Page + Wiring

- [ ] 13. Public Status Page: Incident + Announcement Display (Backend)

  **What to do**:

  **Step 1: Investigate existing public page implementation**
  - Check if `PublicStatusPageController.php` exists (Metis flagged it as potentially missing)
  - Check for Blade template files (`resources/views/status-page/`)
  - If missing, create from scratch following existing patterns

  **Step 2: Add incident data to public page response**
  
  Modify the public status page controller/response to include:
  - Active incidents: linked to this page's monitors, ordered by started_at desc
  - Recent incident history: last 15 days, grouped by date, each with updates timeline
  - Active announcements: published and not ended
  - Scheduled maintenance announcements: upcoming

  **Data Structure for Blade/JSON**:
  ```php
  $monitorIds = $statusPage->monitors()->pluck('monitors.id');
  
  $activeIncidents = Incident::whereHas('monitors', fn($q) => $q->whereIn('monitors.id', $monitorIds))
      ->where('status', '!=', 'resolved')
      ->with('updates', 'monitors')
      ->latest('started_at')
      ->get();
  
  $recentIncidents = Incident::whereHas('monitors', fn($q) => $q->whereIn('monitors.id', $monitorIds))
      ->where('created_at', '>=', now()->subDays(15))
      ->with('updates', 'monitors')
      ->latest('started_at')
      ->get();
  
  $announcements = $statusPage->announcements()
      ->whereNotNull('published_at')
      ->where(fn($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', now()))
      ->latest('published_at')
      ->get();
  
  $scheduledMaintenance = $statusPage->announcements()
      ->where('type', 'maintenance')
      ->whereNull('published_at')
      ->where('scheduled_at', '>', now())
      ->orderBy('scheduled_at')
      ->get();
  ```

  **Cache busting**: Already handled in Tasks 2 and 7 controllers.

  **Step 3: Update Blade template** (or create if missing)
  - Add sections for:
    - Active incident banner (prominent, at top)
    - Uptime bar popup data (incidents per day)
    - Incident history timeline (last 15 days, grouped by date, each with updates)
    - Announcements section (scheduled maintenance, recent announcements)

  **Must NOT do**:
  - Do NOT add embedded widgets
  - Do NOT add RSS/Atom feed
  - Do NOT change caching strategy (300s is fine, just bust on changes)

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel blade templates, controllers, caching patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 14, 15)
  - **Blocks**: None
  - **Blocked By**: Tasks 2, 7

  **References**:
  - `back-end/app/Http/Controllers/Api/V1/StatusPageController.php` — Existing controller patterns
  - `back-end/resources/views/` — Check for existing Blade templates
  - `back-end/app/Models/StatusPage.php` — StatusPage model, monitors relationship
  - status.claude.com screenshots — UI reference for public page layout

  **Acceptance Criteria**:

  **Agent-Executed QA:**
  ```
  Scenario: Public page shows active incident
    Tool: Playwright
    Preconditions: Status page is published, has monitor with active incident
    Steps:
      1. Navigate to public status page URL /status/{slug}
      2. Assert: Active incident banner visible at top
      3. Assert: Incident title and impact displayed
      4. Assert: Timeline updates visible (Investigating, etc.)
      5. Screenshot: .sisyphus/evidence/task-13-public-incident.png
    Expected Result: Active incidents displayed on public page

  Scenario: Public page shows scheduled maintenance
    Tool: Playwright
    Steps:
      1. Create scheduled maintenance announcement for status page
      2. Navigate to public status page
      3. Assert: "Upcoming Maintenance" section visible
      4. Assert: Maintenance title and scheduled time shown
      5. Screenshot: .sisyphus/evidence/task-13-public-maintenance.png
    Expected Result: Scheduled maintenance visible

  Scenario: Public page shows incident history
    Tool: Playwright
    Steps:
      1. Navigate to public status page (with some resolved incidents in last 15 days)
      2. Scroll to "Incident History" section
      3. Assert: Incidents grouped by date
      4. Assert: Each incident shows timeline with updates
      5. Screenshot: .sisyphus/evidence/task-13-public-history.png
    Expected Result: 15-day incident history displayed
  ```

  **Commit**: YES
  - Message: `feat(public): add incidents and announcements to public status page`
  - Files: `back-end/app/Http/Controllers/Api/V1/PublicStatusPageController.php` (or equivalent), `back-end/resources/views/status-page/*`

---

- [ ] 14. Flutter: Wiring (Search, Activity, Dashboard, Translations)

  **What to do**:

  1. **Translations** (`assets/lang/en.json`):
     - Add all missing keys for incidents and announcements
     - incident.title, incident.impact, incident.status, incident.message, incident.create, incident.update, etc.
     - announcement.title, announcement.body, announcement.type, announcement.scheduled_at, etc.
     - All impact level labels, status labels, announcement type labels

  2. **Search Autocomplete** (`lib/resources/views/components/search_autocomplete.dart`):
     - Replace hardcoded mock incident search results with real API calls
     - Search incidents by title, return results with incident icon and link

  3. **Activity Feed** (`lib/resources/views/components/activity_item.dart`):
     - ActivityType.incident already exists with icon/color
     - Wire up to display real incident activities (if activity feed is used)

  4. **Dashboard** (`lib/resources/views/dashboard/`):
     - Wire up "Active Incidents" counter with real data
     - Add recent incidents card/widget if space allows

  5. **Navigation**: Verify `/incidents` nav item works with new route

  **Must NOT do**:
  - Do NOT add notification integration
  - Do NOT over-engineer activity feed

  **Recommended Agent Profile**:
  - **Category**: `unspecified-low`
  - **Skills**: [`wind-ui`, `magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 13, 15)
  - **Blocks**: None
  - **Blocked By**: Tasks 10, 11

  **References**:
  - `assets/lang/en.json` — Existing translation keys
  - `lib/resources/views/components/search_autocomplete.dart` — SearchResultType.incident reference
  - `lib/resources/views/components/activity_item.dart` — ActivityType.incident reference
  - `lib/resources/views/dashboard/` — Dashboard views

  **Acceptance Criteria**:
  - [ ] All new UI text uses trans() keys (no hardcoded strings)
  - [ ] Search for incident by title returns results
  - [ ] Dashboard shows real active incident count
  - [ ] /incidents nav link works

  **Commit**: YES
  - Message: `feat(flutter): wire incidents into search, dashboard, and translations`
  - Files: `assets/lang/en.json`, `lib/resources/views/components/search_autocomplete.dart`, `lib/resources/views/dashboard/*`

---

- [ ] 15. Flutter: Monitor Settings — Incident Threshold Configuration

  **What to do**:

  Update the monitor create/edit forms to include an `incident_threshold` field:
  
  1. Add `incidentThreshold` getter/setter to Flutter `Monitor` model (nullable int)
  2. Add field to monitor create/edit views:
     - WFormInput (numeric) with label "Auto-Incident Threshold"
     - Helper text: "Number of consecutive failures before auto-creating an incident. Leave empty to disable."
     - Nullable — empty means disabled
  3. Add to store/update API call payload
  4. Update backend MonitorController to accept `incident_threshold` in store/update

  **Must NOT do**:
  - Do NOT add complex threshold UI (simple number input is sufficient)
  - Do NOT add per-location thresholds

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`wind-ui`, `magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 13, 14)
  - **Blocks**: None
  - **Blocked By**: Task 1 (migration must exist)

  **References**:
  - `lib/resources/views/monitors/monitor_create_view.dart` — Monitor form pattern
  - `lib/resources/views/monitors/monitor_edit_view.dart` — Monitor edit form
  - `lib/app/models/monitor.dart` — Monitor model (add getter/setter)
  - `back-end/app/Http/Controllers/Api/V1/MonitorController.php` — Update validation to accept incident_threshold
  - `back-end/app/Http/Requests/StoreMonitorRequest.php` — Add validation rule: 'incident_threshold' => 'nullable|integer|min:1|max:100'

  **Acceptance Criteria**:

  **Agent-Executed QA:**
  ```
  Scenario: Set incident threshold on monitor
    Tool: Playwright
    Steps:
      1. Navigate to monitor edit page
      2. Find "Auto-Incident Threshold" input
      3. Enter value: 5
      4. Submit form
      5. Assert: Monitor saved successfully (toast)
      6. Reload page → Assert: threshold value persisted as 5
      7. Screenshot: .sisyphus/evidence/task-15-threshold-setting.png
    Expected Result: Threshold configurable per monitor
  ```

  **Commit**: YES
  - Message: `feat(monitors): add incident threshold configuration`
  - Files: `lib/app/models/monitor.dart`, `lib/resources/views/monitors/monitor_create_view.dart`, `lib/resources/views/monitors/monitor_edit_view.dart`, `back-end/app/Http/Controllers/Api/V1/MonitorController.php`, `back-end/app/Http/Requests/StoreMonitorRequest.php`

---

## Commit Strategy

| After Task | Message | Verification |
|------------|---------|--------------|
| 1 | `feat(incidents): add incident and incident_update migrations and models` | `php artisan test --filter=IncidentTest` |
| 2 | `feat(incidents): add incident API controller, routes, policy, and resources` | `php artisan test --filter=IncidentApiTest` |
| 3 | `feat(incidents): add auto-creation service with cooldown and recovery` | `php artisan test --filter=IncidentAutoCreationServiceTest` |
| 4+5 | `feat(incidents): integrate auto-incident into ProcessCheckResult + edge case tests` | `php artisan test --filter=ProcessCheckResult` |
| 6 | `feat(announcements): add announcement migration and model` | `php artisan test --filter=AnnouncementTest` |
| 7 | `feat(announcements): add announcement API controller, routes, and policy` | `php artisan test --filter=AnnouncementApiTest` |
| 8 | `feat(announcements): add scheduled announcements processing command` | `php artisan test --filter=ProcessScheduledAnnouncementsTest` |
| 9 | `feat(flutter): add incident and announcement enums and models` | `flutter test test/app/enums/ test/app/models/` |
| 10 | `feat(flutter): add incident controller and views (CRUD + timeline)` | `flutter test test/app/controllers/incident_controller_test.dart` |
| 11 | `feat(flutter): add announcement controller and views` | `flutter test test/app/controllers/announcement_controller_test.dart` |
| 12 | `feat(flutter): integrate incidents into status page view` | Manual QA |
| 13 | `feat(public): add incidents and announcements to public status page` | Playwright QA |
| 14 | `feat(flutter): wire incidents into search, dashboard, and translations` | `flutter test` |
| 15 | `feat(monitors): add incident threshold configuration` | `flutter test + php artisan test` |

---

## Success Criteria

### Verification Commands
```bash
# Backend - ALL tests pass
cd back-end && php artisan test --filter=Incident
cd back-end && php artisan test --filter=Announcement
cd back-end && php artisan test --filter=ProcessScheduledAnnouncements
cd back-end && php artisan test --filter=ProcessCheckResultAutoIncident

# Frontend - ALL tests pass
flutter test test/app/enums/incident_impact_test.dart
flutter test test/app/enums/incident_status_test.dart
flutter test test/app/enums/announcement_type_test.dart
flutter test test/app/models/incident_test.dart
flutter test test/app/models/incident_update_test.dart
flutter test test/app/models/announcement_test.dart
flutter test test/app/controllers/incident_controller_test.dart
flutter test test/app/controllers/announcement_controller_test.dart

# Full suite
cd back-end && php artisan test
flutter test
```

### Final Checklist
- [ ] Incident CRUD works end-to-end (create, add updates, resolve)
- [ ] Auto-incident creates after consecutive failures exceed threshold
- [ ] Anti-flapping prevents rapid incident creation (30 min cooldown)
- [ ] Recovery adds "Monitoring" update (NOT auto-resolve)
- [ ] Announcement CRUD works with status page context
- [ ] Scheduled announcements auto-publish on time
- [ ] Public status page displays active incidents + history (15 days)
- [ ] Public status page displays announcements
- [ ] Cache busted on incident/announcement changes
- [ ] Team isolation enforced (multi-tenancy)
- [ ] All "Must NOT Have" items absent
- [ ] All tests pass
- [ ] All translation keys present (no hardcoded strings)
