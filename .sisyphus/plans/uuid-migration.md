# Auto-Increment ID → UUID Migration

## TL;DR

> **Quick Summary**: Convert all domain model primary keys from auto-increment bigint to UUID across the entire stack — Laravel migrations, backend models/controllers/validation/tests, and Flutter models/controllers/views/tests. Database will be wiped and fresh migrations edited in-place.
> 
> **Deliverables**:
> - All 20 migration files updated with UUID PKs and FKs
> - All 14 Laravel models updated with `HasUuids` trait
> - All validation rules changed from `integer` to `uuid`
> - All 7+ Flutter models updated from `int` to `String` ID types
> - All Flutter controllers/views updated to use String IDs
> - All backend and frontend tests passing
> 
> **Estimated Effort**: Large
> **Parallel Execution**: YES - 3 waves
> **Critical Path**: Task 1 (migrations) → Task 2 (backend models) → Task 3 (backend validation/controllers) → Task 5 (Flutter models) → Task 6 (Flutter controllers/views) → Task 8 (tests)

---

## Context

### Original Request
User identified a security vulnerability: all editable models (monitors, status-pages, alert-rules, teams, etc.) use auto-increment integer IDs which are predictable and enumerable. Convert all to UUIDs. Database can be wiped for fresh migration approach.

### Interview Summary
**Key Discussions**:
- `users` table: YES, also convert to UUID (user confirmed)
- Laravel internal tables (jobs, cache, sessions table PK): Keep as-is
- `sessions.user_id` FK: MUST update since users.id changes to UUID
- Fresh migration approach: Edit existing migration files, wipe DB with `migrate:fresh`
- `newsletter_subscribers` and `relay_nodes`: Convert (they have auto-increment PKs, should be consistent)

**Research Findings**:
- 30 migration files total, ~20 need changes
- 14 Laravel models, all need `HasUuids` trait
- `notifications` table already uses UUID PK but `morphs('notifiable')` uses int — must change to `uuidMorphs`
- `personal_access_tokens` uses `morphs('tokenable')` — must change to `uuidMorphs`
- TimescaleDB materialized views reference `monitor_id` — must be recreated after schema change (handled by fresh migration since views are created AFTER tables)
- No Flutter models use `useLocal => true` (confirmed via grep — no matches)
- OneSignal integration uses `'user_' . $this->id` prefix — UUID format is longer but functionally compatible (no production users yet, fresh DB wipe)

### Metis Review
**Identified Gaps** (addressed):
- **Sessions FK mismatch**: `sessions.user_id` uses `foreignId()` but `users.id` becomes UUID → Changed to `foreignUuid('user_id')` in plan
- **Morph columns**: Both `notifications.notifiable` and `personal_access_tokens.tokenable` use `morphs()` (int) → Changed to `uuidMorphs()` in plan
- **TimescaleDB aggregates**: Must be dropped before table changes, recreated after → Fresh migration handles this naturally (aggregates migration runs AFTER table migrations)
- **`int.tryParse` scope**: Only remove for route ID parameters, NOT for form values like `expected_status_code` → Explicit guardrail added
- **`MonitorMetricValue` plain class**: Not a Model subclass, uses `final int id` → Must be manually refactored
- **6 backend scope methods** with `int` type hints → All changed to `string`

---

## Work Objectives

### Core Objective
Eliminate predictable auto-increment integer IDs across the entire Uptizm platform by replacing them with UUIDs, improving security against enumeration attacks.

### Concrete Deliverables
- 20 migration files edited with `$table->uuid('id')->primary()` and `foreignUuid()`
- 14 Laravel models with `HasUuids` trait, `$incrementing = false`, `$keyType = 'string'`
- 3 Form Requests with `'uuid'` validation rules
- 7+ Flutter models with `String` ID types
- 5+ Flutter controllers with `String` method signatures
- 4+ Flutter views without `int.tryParse` on route ID params
- All backend tests passing (`php artisan test`)
- All frontend tests passing (`flutter test`)

### Definition of Done
- [ ] `php artisan migrate:fresh --seed` runs without errors
- [ ] `php artisan test` — all tests pass
- [ ] `flutter test` — all tests pass
- [ ] No `$table->id()` remains in domain model migrations (only in jobs/cache)
- [ ] No `foreignId()` remains pointing to UUID tables
- [ ] No `get<int>('id')` or `get<int>('..._id')` remains in Flutter models
- [ ] No `int.tryParse` on route ID parameters in Flutter views

### Must Have
- `HasUuids` trait on ALL domain models (users, teams, monitors, etc.)
- `uuidMorphs()` for personal_access_tokens.tokenable AND notifications.notifiable
- `foreignUuid('user_id')` on sessions table
- Backend validation rules using `'uuid'` instead of `'integer'` for ID fields
- Flutter model ID getters returning `String?` instead of `int?`
- Flutter controllers accepting `String` IDs in method signatures

### Must NOT Have (Guardrails)
- **DO NOT** create new migration files — edit existing ones in-place
- **DO NOT** touch internal Laravel tables: `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`
- **DO NOT** change `password_reset_tokens` table (uses email as PK, no user_id FK)
- **DO NOT** remove `int.tryParse` on non-ID form inputs (`expected_status_code`, `check_interval`, `timeout`, `display_order` etc.)
- **DO NOT** modify `MonitorCheckService` or `AssertionEvaluator` int params (those are status codes/timing, NOT IDs)
- **DO NOT** change the Magic Framework's base `Model.incrementing` default — override in app models only
- **DO NOT** add UUID generation logic to Flutter models (backend generates UUIDs; Flutter receives them from API)
- **DO NOT** refactor Flutter model getter patterns (3 different patterns exist; only change type, NOT style)
- **DO NOT** add `uuid` package to Flutter test dependencies — use simple string IDs like `'test-uuid-1'`
- **DO NOT** add route regex constraints for UUID format — route model binding handles this
- **DO NOT** add explicit `(string)` casts in API Resources — Eloquent already returns string for UUID columns

---

## Verification Strategy (MANDATORY)

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks in this plan MUST be verifiable WITHOUT any human action.

### Test Decision
- **Infrastructure exists**: YES (both Laravel PHPUnit and Flutter test)
- **Automated tests**: YES (tests-after — existing tests must be updated to work with UUIDs)
- **Framework**: PHPUnit (Laravel), Flutter test (frontend)

### Agent-Executed QA Scenarios (MANDATORY — ALL tasks)

> Every task includes verification commands. The executing agent runs them directly.

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| **Migrations** | Bash (`php artisan migrate:fresh`) | Run fresh migration, assert exit code 0 |
| **Backend Models** | Bash (`php artisan test`) | Run test suite, assert all pass |
| **Backend Validation** | Bash (`php artisan test --filter=...`) | Run specific tests |
| **Flutter Models** | Bash (`flutter test test/app/models/`) | Run model tests |
| **Flutter Controllers** | Bash (`flutter test test/app/controllers/`) | Run controller tests |
| **Flutter Views** | Bash (`flutter test test/resources/views/`) | Run view tests |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately — Backend Schema + Models):
├── Task 1: Edit ALL migration files (schema changes)
├── Task 2: Update ALL Laravel models (HasUuids trait)
└── Task 3: Update Laravel validation, controllers, scopes

Wave 2 (After Wave 1 — Backend Verification + Flutter Models):
├── Task 4: Fix backend tests + verify with `php artisan test`
├── Task 5: Update ALL Flutter models (int → String)
└── Task 6: Update Flutter controllers + views (String IDs, remove int.tryParse)

Wave 3 (After Wave 2 — Flutter Tests + Final Verification):
├── Task 7: Fix Flutter tests + verify with `flutter test`
└── Task 8: Full-stack verification (fresh migrate + both test suites)
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | 2, 3, 4 | — |
| 2 | 1 | 4 | 3 |
| 3 | 1 | 4 | 2 |
| 4 | 2, 3 | 8 | 5, 6 |
| 5 | None | 7 | 4, 6 |
| 6 | 5 | 7 | 4 |
| 7 | 5, 6 | 8 | — |
| 8 | 4, 7 | None | — |

### Agent Dispatch Summary

| Wave | Tasks | Recommended Agents |
|------|-------|-------------------|
| 1 | 1, 2, 3 | Single agent (sequential within wave due to file dependencies) |
| 2 | 4 (backend tests), 5+6 (Flutter) | Two parallel agents |
| 3 | 7, 8 | Single agent (sequential) |

---

## TODOs

- [x] 1. Edit ALL Laravel migration files — Replace integer PKs and FKs with UUID

  **What to do**:

  **Step A — Edit `0001_01_01_000000_create_users_table.php`:**
  - `users` table: Replace `$table->id()` with `$table->uuid('id')->primary()`
  - `sessions` table: Replace `$table->foreignId('user_id')` with `$table->foreignUuid('user_id')`

  **Step B — Edit `2025_12_29_205337_create_personal_access_tokens_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->morphs('tokenable')` with `$table->uuidMorphs('tokenable')`

  **Step C — Edit `2025_12_29_220405_create_newsletter_subscribers_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`

  **Step D — Edit `2025_12_29_222748_create_teams_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('user_id')` with `$table->foreignUuid('user_id')->constrained()->cascadeOnDelete()`

  **Step E — Edit `2025_12_29_222749_create_team_user_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('team_id')` with `$table->foreignUuid('team_id')->constrained()->cascadeOnDelete()`
  - Replace `$table->foreignId('user_id')` with `$table->foreignUuid('user_id')->constrained()->cascadeOnDelete()`

  **Step F — Edit `2025_12_30_184124_create_team_invitations_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('team_id')` with `$table->foreignUuid('team_id')->constrained()->cascadeOnDelete()`

  **Step G — Edit `2025_12_30_214054_add_current_team_id_to_users_table.php`:**
  - Replace `$table->foreignId('current_team_id')` with `$table->foreignUuid('current_team_id')->nullable()->constrained('teams')->nullOnDelete()`

  **Step H — Edit `2026_02_02_122236_create_monitors_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('team_id')` with `$table->foreignUuid('team_id')->constrained()->cascadeOnDelete()`

  **Step I — Edit `2026_02_02_122321_create_monitor_checks_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('monitor_id')` with `$table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete()`

  **Step J — Edit `2026_02_03_000002_create_monitor_metric_values_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('monitor_id')` with `$table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete()`
  - Replace `$table->foreignId('check_id')->references('id')->on('monitor_checks')->cascadeOnDelete()` with `$table->foreignUuid('check_id')->references('id')->on('monitor_checks')->cascadeOnDelete()`

  **Step K — Edit `2026_02_03_000003_create_relay_nodes_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`

  **Step L — Edit `2026_02_03_094805_create_notifications_table.php`:**
  - Replace `$table->morphs('notifiable')` with `$table->uuidMorphs('notifiable')`

  **Step M — Edit `2026_02_03_105848_create_notification_preferences_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('user_id')` with `$table->foreignUuid('user_id')->constrained()->cascadeOnDelete()`

  **Step N — Edit `2026_02_05_185359_create_alert_rules_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('team_id')` with `$table->foreignUuid('team_id')->constrained()->cascadeOnDelete()`
  - Replace `$table->foreignId('monitor_id')->nullable()` with `$table->foreignUuid('monitor_id')->nullable()->constrained()->cascadeOnDelete()`

  **Step O — Edit `2026_02_05_185417_create_alerts_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('alert_rule_id')` with `$table->foreignUuid('alert_rule_id')->constrained()->cascadeOnDelete()`
  - Replace `$table->foreignId('monitor_id')` with `$table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete()`

  **Step P — Edit `2026_02_05_185434_create_alert_rule_states_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('alert_rule_id')` with `$table->foreignUuid('alert_rule_id')->constrained()->cascadeOnDelete()`
  - Replace `$table->foreignId('monitor_id')` with `$table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete()`
  - Replace `$table->foreignId('active_alert_id')->nullable()->constrained('alerts')->nullOnDelete()` with `$table->foreignUuid('active_alert_id')->nullable()->constrained('alerts')->nullOnDelete()`

  **Step Q — Edit `2026_02_06_175454_create_status_pages_table.php`:**
  - In `status_pages` table: Replace `$table->id()` with `$table->uuid('id')->primary()`
  - In `status_pages` table: Replace `$table->foreignId('team_id')` with `$table->foreignUuid('team_id')->constrained()->cascadeOnDelete()`
  - In `status_page_monitor` table: Replace `$table->id()` with `$table->uuid('id')->primary()`
  - In `status_page_monitor` table: Replace `$table->foreignId('status_page_id')` with `$table->foreignUuid('status_page_id')->constrained()->cascadeOnDelete()`
  - In `status_page_monitor` table: Replace `$table->foreignId('monitor_id')` with `$table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete()`

  **Step R — Edit `2026_02_06_200046_create_status_page_monitor_metrics_table.php`:**
  - Replace `$table->id()` with `$table->uuid('id')->primary()`
  - Replace `$table->foreignId('status_page_id')` with `$table->foreignUuid('status_page_id')->constrained()->cascadeOnDelete()`
  - Replace `$table->foreignId('monitor_id')` with `$table->foreignUuid('monitor_id')->constrained()->cascadeOnDelete()`

  **DO NOT TOUCH these migration files** (no domain model PKs/FKs):
  - `0001_01_01_000001_create_cache_table.php` — internal Laravel cache
  - `0001_01_01_000002_create_jobs_table.php` — internal Laravel queue
  - `2025_12_29_224516_add_localization_fields_to_users_table.php` — adds string columns only
  - `2025_12_30_001537_add_profile_photo_path_to_users_table.php` — adds string column only
  - `2025_12_29_225054_add_device_info_to_personal_access_tokens_table.php` — adds string columns only
  - `2026_01_04_211825_add_profile_photo_path_to_teams_table.php` — adds string column only
  - `2026_02_01_233849_add_profile_fields_to_users_table.php` — adds string columns only
  - `2026_02_02_163910_add_metric_mappings_to_monitors_table.php` — adds json column only
  - `2026_02_02_200000_add_auth_config_to_monitors_table.php` — adds json column only
  - `2026_02_03_000001_add_scheduling_fields_to_monitors_table.php` — adds timestamp/int columns only
  - `2026_02_03_000004_setup_timescaledb.php` — references monitor_id by name in SQL, types inherited from base table
  - `2026_02_03_211857_add_status_value_to_monitor_metric_values_table.php` — adds string column only

  **Must NOT do**:
  - Do NOT create new migration files
  - Do NOT touch jobs, cache, or password_reset_tokens tables
  - Do NOT modify the TimescaleDB setup migration (it creates materialized views that inherit column types from base tables)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel migration patterns and conventions

  **Parallelization**:
  - **Can Run In Parallel**: NO (foundation for everything else)
  - **Parallel Group**: Wave 1 (sequential start)
  - **Blocks**: Tasks 2, 3, 4
  - **Blocked By**: None

  **References**:

  **Pattern References** (existing code to follow):
  - `back-end/database/migrations/2026_02_03_094805_create_notifications_table.php:15` — Already uses `$table->uuid('id')->primary()` — follow this exact pattern
  - Each migration file listed in Steps A-R above — edit these in-place

  **Documentation References**:
  - Laravel HasUuids trait: https://laravel.com/docs/11.x/eloquent#uuid-and-ulid-keys

  **Acceptance Criteria**:
  - [ ] No `$table->id()` remains in any domain model migration (only in `0001_01_01_000001_create_cache_table.php` and `0001_01_01_000002_create_jobs_table.php`)
  - [ ] No `foreignId()` remains pointing to tables that now use UUID PKs
  - [ ] `morphs('tokenable')` changed to `uuidMorphs('tokenable')` in personal_access_tokens
  - [ ] `morphs('notifiable')` changed to `uuidMorphs('notifiable')` in notifications
  - [ ] `sessions.user_id` uses `foreignUuid` not `foreignId`

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Fresh migration succeeds
    Tool: Bash
    Preconditions: PostgreSQL running, database exists
    Steps:
      1. cd back-end && php artisan migrate:fresh
      2. Assert: Exit code 0, no errors in output
      3. Verify UUID columns: php artisan tinker --execute="Schema::getColumnType('users', 'id')"
      4. Assert: Output is 'uuid' or 'char' (36)
    Expected Result: All migrations run without errors
    Evidence: Terminal output captured

  Scenario: Grep confirms no remaining integer PKs in domain migrations
    Tool: Bash (grep)
    Steps:
      1. grep -rn '\$table->id()' back-end/database/migrations/ --include='*.php'
      2. Assert: Only matches in cache and jobs migrations
      3. grep -rn 'foreignId(' back-end/database/migrations/ --include='*.php'
      4. Assert: No matches (all converted to foreignUuid)
    Expected Result: Zero foreignId and only 2 $table->id() remaining
    Evidence: Grep output captured
  ```

  **Commit**: YES
  - Message: `refactor(db): replace auto-increment integer PKs with UUIDs in all domain migrations`
  - Files: All edited migration files in `back-end/database/migrations/`
  - Pre-commit: `cd back-end && php artisan migrate:fresh`

---

- [ ] 2. Update ALL Laravel models — Add HasUuids trait and update type hints

  **What to do**:

  For EACH of these 14 models, add the `HasUuids` trait and set key properties:

  **Models requiring `use HasUuids;` + `$incrementing = false` + `$keyType = 'string'`:**

  1. `app/Models/User.php` — Add `use Illuminate\Database\Eloquent\Concerns\HasUuids;` import, add `HasUuids` to trait list (alongside existing `HasApiTokens, HasFactory, Notifiable`)
  2. `app/Models/Team.php` — Add import + trait
  3. `app/Models/Monitor.php` — Add import + trait
  4. `app/Models/MonitorCheck.php` — Add import + trait
  5. `app/Models/MonitorMetricValue.php` — Add import + trait
  6. `app/Models/AlertRule.php` — Add import + trait
  7. `app/Models/Alert.php` — Add import + trait
  8. `app/Models/AlertRuleState.php` — Add import + trait
  9. `app/Models/StatusPage.php` — Add import + trait
  10. `app/Models/StatusPageMonitorMetric.php` — Add import + trait
  11. `app/Models/NotificationPreference.php` — Add import + trait
  12. `app/Models/TeamInvitation.php` — Add import + trait
  13. `app/Models/RelayNode.php` — Add import + trait
  14. `app/Models/NewsletterSubscriber.php` — Add import + trait

  **For each model add these properties (if not already present):**
  ```php
  use HasUuids;

  public $incrementing = false;
  protected $keyType = 'string';
  ```

  **Note**: `HasUuids` trait from Laravel automatically handles UUID generation. Setting `$incrementing = false` and `$keyType = 'string'` ensures Eloquent treats the key correctly.

  **Update scope method type hints** (search each model file):
  - `Monitor::scopeForTeam($query, int $teamId)` → `string $teamId`
  - `AlertRule::scopeForTeam($query, int $teamId)` → `string $teamId`
  - `Alert::scopeForTeam($query, int $teamId)` → `string $teamId`
  - `AlertRuleState::scopeForRule($query, int $ruleId)` → `string $ruleId`
  - `AlertRuleState::findForRuleAndMonitor(int $ruleId, int $monitorId)` → `string $ruleId, string $monitorId`
  - Any other scope/static method with `int` type hint for ID parameters (search for `int $` in model files)

  **Must NOT do**:
  - Do NOT change any relationship definitions (belongsTo, hasMany etc.) — they work with UUIDs automatically
  - Do NOT change `$fillable` arrays (IDs are not in fillable)
  - Do NOT change any non-ID integer parameters (status codes, intervals, etc.)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel model patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Task 3)
  - **Parallel Group**: Wave 1 (after Task 1)
  - **Blocks**: Task 4
  - **Blocked By**: Task 1

  **References**:

  **Pattern References**:
  - `back-end/app/Models/User.php:14` — Current trait list: `use HasApiTokens, HasFactory, Notifiable;` — add `HasUuids` here
  - `back-end/app/Models/Monitor.php` — Example of `scopeForTeam` with `int` type hint to change
  - `back-end/app/Models/AlertRuleState.php` — Has both scope and static method with `int` params

  **Documentation References**:
  - Laravel HasUuids: https://laravel.com/docs/11.x/eloquent#uuid-and-ulid-keys

  **Acceptance Criteria**:
  - [ ] All 14 models have `use HasUuids;` in trait list
  - [ ] All 14 models have `public $incrementing = false;`
  - [ ] All 14 models have `protected $keyType = 'string';`
  - [ ] Zero `int $teamId`, `int $monitorId`, `int $ruleId` type hints remain in scope/static methods for ID parameters
  - [ ] `php artisan tinker --execute="echo get_class_methods(App\Models\User::class);"` includes UUID-related methods

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Models have HasUuids trait
    Tool: Bash (grep)
    Steps:
      1. For each model file in back-end/app/Models/:
         grep -l 'HasUuids' back-end/app/Models/*.php | wc -l
      2. Assert: Count is 14
      3. grep -rn 'int \$teamId\|int \$monitorId\|int \$ruleId\|int \$alertId' back-end/app/Models/
      4. Assert: No matches
    Expected Result: All models have HasUuids, no int ID type hints
    Evidence: Grep output captured
  ```

  **Commit**: YES (group with Task 3)
  - Message: `refactor(models): add HasUuids trait to all domain models, update int type hints to string`
  - Files: All 14 model files in `back-end/app/Models/`
  - Pre-commit: `cd back-end && php artisan test --filter=Unit` (if unit tests exist)

---

- [ ] 3. Update Laravel validation rules, controllers, and service type hints

  **What to do**:

  **Form Requests — Change `'integer'` validation to `'uuid'` for ID fields:**

  1. `app/Http/Requests/Api/V1/SwitchTeamRequest.php`:
     - `'team_id' => ['required', 'integer', ...]` → `'team_id' => ['required', 'uuid', ...]`

  2. `app/Http/Requests/Api/V1/StoreStatusPageRequest.php`:
     - `'monitor_ids.*' => ['integer', ...]` → `'monitor_ids.*' => ['uuid', ...]`

  3. `app/Http/Requests/Api/V1/UpdateStatusPageRequest.php`:
     - `'monitor_ids.*' => ['integer', ...]` → `'monitor_ids.*' => ['uuid', ...]`

  **Controllers — Check for inline ID validation:**

  4. `app/Http/Controllers/Api/V1/StatusPageController.php`:
     - Search for any inline `'integer'` validation on `monitor_id` or similar fields → change to `'uuid'`

  5. ALL controllers in `app/Http/Controllers/Api/V1/` — Search for:
     - `(int)` cast on IDs → remove or change to `(string)`
     - `intval()` on IDs → remove
     - `'integer'` validation rules → change to `'uuid'`

  **Services — Check for int type hints on ID parameters:**

  6. `app/Services/MonitorCheckService.php` — Review int params: ONLY change ID-related ones. Do NOT change `int $statusCode`, `int $responseTimeMs`, `int $timeout` etc.
  7. `app/Services/AlertEvaluationService.php` — Same: only ID params, not numeric business values

  **Must NOT do**:
  - Do NOT change validation rules for non-ID integers (`check_interval`, `expected_status_code`, `timeout`, `display_order`, `consecutive_checks`)
  - Do NOT change service method params that are NOT IDs (status codes, timing values)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel validation and controller patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Task 2)
  - **Parallel Group**: Wave 1 (after Task 1)
  - **Blocks**: Task 4
  - **Blocked By**: Task 1

  **References**:

  **Pattern References**:
  - `back-end/app/Http/Requests/Api/V1/SwitchTeamRequest.php` — Has `'integer'` rule on `team_id`
  - `back-end/app/Http/Requests/Api/V1/StoreStatusPageRequest.php` — Has `'integer'` rule on `monitor_ids.*`
  - `back-end/app/Http/Requests/Api/V1/UpdateStatusPageRequest.php` — Has `'integer'` rule on `monitor_ids.*`
  - `back-end/app/Http/Controllers/Api/V1/StatusPageController.php` — May have inline validation

  **Acceptance Criteria**:
  - [ ] Zero `'integer'` validation rules for ID fields in Form Requests
  - [ ] grep for `'integer'` in FormRequest files only returns non-ID fields (like `check_interval`)
  - [ ] No `(int)` or `intval()` casts on model IDs in controllers

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: No integer validation on ID fields
    Tool: Bash (grep)
    Steps:
      1. grep -n "'integer'" back-end/app/Http/Requests/Api/V1/SwitchTeamRequest.php
      2. Assert: No matches (team_id now uses 'uuid')
      3. grep -n "'integer'" back-end/app/Http/Requests/Api/V1/StoreStatusPageRequest.php
      4. Assert: Only non-ID fields if any
      5. grep -n "'uuid'" back-end/app/Http/Requests/Api/V1/StoreStatusPageRequest.php
      6. Assert: monitor_ids.* uses 'uuid' rule
    Expected Result: All ID validation uses 'uuid'
    Evidence: Grep output captured
  ```

  **Commit**: YES (group with Task 2)
  - Message: `refactor(validation): change integer to uuid validation for all ID fields`
  - Files: Form Requests + Controllers modified
  - Pre-commit: N/A (combined commit with Task 2)

---

- [ ] 4. Fix backend tests and verify full suite passes

  **What to do**:

  **Search ALL test files** in `back-end/tests/` for:

  1. **Hardcoded integer IDs** like `'id' => 1`, `'team_id' => 10`, `'monitor_id' => 20`:
     - These are fine IF they come from factories (factory auto-generates UUIDs with HasUuids)
     - Change hardcoded IDs to UUID strings: `'id' => 'test-uuid-1'` or use model factories

  2. **`assertIsInt` on IDs** → Change to `assertIsString`

  3. **Integer assertions** like `assertEquals(1, $model->id)` → verify these use factory-generated values, not hardcoded ints

  4. **Route assertions** with integer IDs like `/api/v1/monitors/1` → use `$monitor->id` (UUID string from factory)

  5. **Database assertions** like `assertDatabaseHas('monitors', ['id' => 1])` → use factory model `$monitor->id`

  **Run the full test suite:**
  ```bash
  cd back-end && php artisan migrate:fresh --env=testing
  cd back-end && php artisan test
  ```

  Fix any failures iteratively until ALL tests pass.

  **Must NOT do**:
  - Do NOT delete existing tests
  - Do NOT skip failing tests with `markTestSkipped`
  - Do NOT restructure test patterns — only change ID-related values

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Laravel testing patterns and conventions

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Tasks 5, 6)
  - **Parallel Group**: Wave 2 (after Tasks 2, 3)
  - **Blocks**: Task 8
  - **Blocked By**: Tasks 2, 3

  **References**:

  **Pattern References**:
  - `back-end/tests/Feature/MonitorAnalyticsTest.php` — Key test file for monitor functionality
  - `back-end/tests/Feature/MonitorCreateFlowIntegrationTest.php` — Integration test with ID assertions
  - `back-end/tests/Feature/Jobs/PerformMonitorCheckTest.php` — Job test referencing monitor IDs
  - All files in `back-end/tests/Feature/` and `back-end/tests/Unit/`

  **Acceptance Criteria**:
  - [ ] `cd back-end && php artisan migrate:fresh --env=testing` → exit code 0
  - [ ] `cd back-end && php artisan test` → ALL tests pass, exit code 0
  - [ ] No hardcoded integer IDs remain for model PKs/FKs in test files (use factories or UUID strings)

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Full backend test suite passes
    Tool: Bash
    Preconditions: Backend environment configured, database accessible
    Steps:
      1. cd back-end && php artisan migrate:fresh --env=testing
      2. Assert: Exit code 0
      3. cd back-end && php artisan test
      4. Assert: Exit code 0, output shows "Tests: X passed"
      5. Assert: No "FAILED" in output
    Expected Result: All backend tests pass
    Evidence: Test output captured

  Scenario: No hardcoded int IDs in test files
    Tool: Bash (grep)
    Steps:
      1. grep -rn "'id' => [0-9]" back-end/tests/
      2. Review matches — only allow if value is for non-ID fields (like status_code, interval)
    Expected Result: No hardcoded integer model IDs
    Evidence: Grep output captured
  ```

  **Commit**: YES
  - Message: `test(backend): update all tests for UUID primary keys`
  - Files: All modified test files in `back-end/tests/`
  - Pre-commit: `cd back-end && php artisan test`

---

- [ ] 5. Update ALL Flutter models — Change int IDs to String

  **What to do**:

  For each Flutter model in `lib/app/models/`:

  **1. `lib/app/models/monitor.dart`:**
  - Change `int? get id => get<int>('id')` → `String? get id => get<String>('id')`
  - Change `int? get teamId => get<int>('team_id')` → `String? get teamId => get<String>('team_id')`
  - Change `static Future<Monitor?> find(int id)` → `static Future<Monitor?> find(String id)`
  - Add `@override bool get incrementing => false;`
  - Any other `int` typed ID getters → `String`

  **2. `lib/app/models/user.dart`:**
  - Update ID getter to return `String?` instead of `int?` or `dynamic`
  - If `find(dynamic id)` exists, update to `find(String id)` or keep `dynamic`
  - Add `@override bool get incrementing => false;`

  **3. `lib/app/models/team.dart`:**
  - Change `int? get id` → `String? get id`
  - Change `int? get ownerId` → `String? get ownerId` (or whatever the FK getter is)
  - Remove `getAttribute('id') as int?` pattern → use `get<String>('id')`
  - Add `@override bool get incrementing => false;`

  **4. `lib/app/models/alert.dart`:**
  - Change all `(getAttribute('id') as num?)?.toInt()` → `getAttribute('id') as String?`
  - Change `int? get monitorId` → `String? get monitorId`
  - Add `@override bool get incrementing => false;`

  **5. `lib/app/models/alert_rule.dart`:**
  - Same pattern: `int?` → `String?` for id, teamId, monitorId
  - Add `@override bool get incrementing => false;`

  **6. `lib/app/models/status_page.dart`:**
  - Change `int? get id` → `String? get id`
  - **CRITICAL**: Change `List<int> get monitorIds` → `List<String> get monitorIds`
  - Update the fromMap/list casting accordingly (e.g., `.map((e) => e as int).toList()` → `.map((e) => e.toString()).toList()`)
  - Add `@override bool get incrementing => false;`

  **7. `lib/app/models/monitor_check.dart`:**
  - Change `int? get id` → `String? get id`
  - Change `int? get monitorId` → `String? get monitorId`
  - Add `@override bool get incrementing => false;`

  **8. `lib/app/models/monitor_metric_value.dart`:**
  - **This is a plain Dart class, NOT a Model subclass**
  - Change `final int id` → `final String id`
  - Change `final int monitorId` → `final String monitorId`
  - Change `final int checkId` → `final String checkId`
  - Update constructor and fromMap: `(map['id'] as num).toInt()` → `map['id'].toString()` or `map['id'] as String`

  **Must NOT do**:
  - Do NOT change non-ID integer fields (response_time_ms, status_code, check_interval, expected_status_code, display_order, consecutive_checks, etc.)
  - Do NOT change the getter style/pattern — only change the type
  - Do NOT add UUID generation packages
  - Do NOT modify the Magic Framework's base Model class

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`magic-framework`, `wind-ui`]
    - `magic-framework`: Flutter model patterns, attribute access conventions
    - `wind-ui`: May have model references in views

  **Parallelization**:
  - **Can Run In Parallel**: YES (with Task 4)
  - **Parallel Group**: Wave 2
  - **Blocks**: Tasks 6, 7
  - **Blocked By**: None (Flutter models are independent of backend changes)

  **References**:

  **Pattern References**:
  - `lib/app/models/monitor.dart` — Primary model showing `get<int>('id')` pattern to change
  - `lib/app/models/team.dart` — Shows `getAttribute('id') as int?` pattern
  - `lib/app/models/alert.dart` — Shows `(getAttribute('id') as num?)?.toInt()` safe casting pattern
  - `lib/app/models/status_page.dart` — Has `List<int> monitorIds` that becomes `List<String>`
  - `lib/app/models/monitor_metric_value.dart` — Plain Dart class with `final int` fields

  **API/Type References**:
  - `plugins/fluttersdk_magic/lib/src/database/eloquent/model.dart` — Base Model class: `bool get incrementing => true;` (override to `false` in app models)

  **Acceptance Criteria**:
  - [ ] Zero `get<int>('id')` or `get<int>('..._id')` calls remain in model files
  - [ ] Zero `as int?` or `as num?)?.toInt()` casts on ID fields
  - [ ] All ID getters return `String?` (or `String` for non-nullable)
  - [ ] `StatusPage.monitorIds` returns `List<String>`
  - [ ] `MonitorMetricValue` has `String` id, monitorId, checkId fields
  - [ ] All models that extend Model have `@override bool get incrementing => false;`

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: No int ID getters in Flutter models
    Tool: Bash (grep)
    Steps:
      1. grep -rn "get<int>('id')\|get<int>('.*_id')" lib/app/models/
      2. Assert: No matches
      3. grep -rn "as int?" lib/app/models/
      4. Assert: No matches for ID fields (may match non-ID fields like status_code)
      5. grep -rn "incrementing => false" lib/app/models/
      6. Assert: Matches in all Model-extending files
    Expected Result: All models use String IDs
    Evidence: Grep output captured
  ```

  **Commit**: YES
  - Message: `refactor(flutter-models): change all model IDs from int to String for UUID support`
  - Files: All model files in `lib/app/models/`
  - Pre-commit: `flutter analyze lib/app/models/`

---

- [ ] 6. Update Flutter controllers and views — String IDs, remove int.tryParse on route params

  **What to do**:

  **Controllers** in `lib/app/controllers/`:

  1. Search ALL controller files for `int id`, `int monitorId`, `int statusPageId`, `int ruleId`, `int teamId`, `int alertId` in method signatures → Change to `String`
  2. Search for `int.parse()` or `int.tryParse()` on ID values → Remove (IDs are already strings)
  3. Update any API call construction that expects int IDs

  Specific controllers to check:
  - `lib/app/controllers/monitor_controller.dart` — `loadMonitor(int id)` → `loadMonitor(String id)`, `update(int id, ...)` → `update(String id, ...)`
  - `lib/app/controllers/alert_controller.dart` — `fetchMonitorAlertRules(int monitorId)` → `String`, `deleteAlertRule(int ruleId)` → `String`
  - `lib/app/controllers/status_page_controller.dart` — ID parameters
  - `lib/app/controllers/team_controller.dart` — ID parameters
  - All other controllers with ID method params

  **Views** in `lib/resources/views/`:

  4. `lib/resources/views/monitors/monitor_show_view.dart`:
     - Remove `_monitorId = int.tryParse(idParam)` → `_monitorId = idParam` (keep as String)
     - Change `int? _monitorId` → `String? _monitorId`

  5. ALL other views that extract ID from route params:
     - Search for `int.tryParse.*pathParameter\|int.tryParse.*idParam` → Remove the int parsing
     - Change variable types from `int?` to `String?`

  6. Views with ID-based navigation:
     - `MagicRoute.to('/monitors/${monitor.id}')` — Already works (String interpolation handles both int and String)
     - `ValueKey(item['monitor_id'])` — Already works (ValueKey accepts dynamic)

  **CRITICAL DISTINCTION**: Only remove `int.tryParse` for ROUTE ID parameters. Do NOT remove `int.tryParse` for form inputs like `expected_status_code`, `check_interval`, `timeout`.

  **Must NOT do**:
  - Do NOT remove `int.tryParse` on non-ID form values
  - Do NOT change navigation patterns
  - Do NOT change Widget keys that use IDs (ValueKey works with any type)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`magic-framework`, `wind-ui`]
    - `magic-framework`: Controller and routing patterns
    - `wind-ui`: View patterns using className

  **Parallelization**:
  - **Can Run In Parallel**: NO (depends on Task 5 for model types)
  - **Parallel Group**: Wave 2 (sequential after Task 5)
  - **Blocks**: Task 7
  - **Blocked By**: Task 5

  **References**:

  **Pattern References**:
  - `lib/app/controllers/monitor_controller.dart` — Controller with `int id` method params
  - `lib/app/controllers/alert_controller.dart` — Controller with `int monitorId`, `int ruleId`
  - `lib/resources/views/monitors/monitor_show_view.dart` — View with `int.tryParse(idParam)`
  - `lib/resources/views/status_pages/status_page_edit_view.dart` — View referencing monitor_id in maps
  - `lib/routes/app.dart` — Route definitions with `:id` params (these DON'T need changes — routes are already string-based)

  **Acceptance Criteria**:
  - [ ] Zero `int id`, `int monitorId`, `int ruleId` etc. in controller method signatures for ID params
  - [ ] Zero `int.tryParse` on route ID parameters in view files
  - [ ] `int.tryParse` on form values (expected_status_code etc.) is PRESERVED
  - [ ] `flutter analyze lib/app/controllers/ lib/resources/views/` — no errors

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: No int types for IDs in controllers
    Tool: Bash (grep)
    Steps:
      1. grep -rn "int id\|int monitorId\|int statusPageId\|int ruleId\|int teamId\|int alertId" lib/app/controllers/
      2. Assert: No matches
    Expected Result: All controller ID params are String
    Evidence: Grep output captured

  Scenario: No int.tryParse on route ID params in views
    Tool: Bash (grep)
    Steps:
      1. grep -rn "int.tryParse.*pathParameter\|int.tryParse.*idParam\|int.tryParse.*idStr" lib/resources/views/
      2. Assert: No matches
    Expected Result: Route ID params used as strings directly
    Evidence: Grep output captured
  ```

  **Commit**: YES
  - Message: `refactor(flutter): update controllers and views to use String IDs instead of int`
  - Files: Controller and view files
  - Pre-commit: `flutter analyze`

---

- [ ] 7. Fix Flutter tests and verify full suite passes

  **What to do**:

  **Search ALL test files** in `test/` for:

  1. **Hardcoded integer IDs** in test data maps like `'id': 1`, `'team_id': 10`, `'monitor_id': 20`:
     - Change to UUID-like strings: `'id': 'test-uuid-1'`, `'team_id': 'test-team-uuid-1'`, `'monitor_id': 'test-monitor-uuid-1'`
     - Use simple, readable strings — NOT real UUIDs (readability > realism in tests)

  2. **Integer type assertions** on IDs → change to String assertions

  3. **`int` type in test variable declarations** for IDs → change to `String`

  4. **Model creation in tests** with `int` IDs → use String IDs

  **Run the full test suite:**
  ```bash
  flutter test
  ```

  Fix any failures iteratively until ALL tests pass.

  **Must NOT do**:
  - Do NOT add uuid package to test dependencies
  - Do NOT delete existing tests
  - Do NOT restructure test helpers — only change ID-related types and values
  - Do NOT change non-ID integer values in test data (status_code: 200, check_interval: 60, etc.)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Flutter test patterns

  **Parallelization**:
  - **Can Run In Parallel**: NO (depends on Tasks 5, 6)
  - **Parallel Group**: Wave 3
  - **Blocks**: Task 8
  - **Blocked By**: Tasks 5, 6

  **References**:

  **Pattern References**:
  - All test files in `test/app/models/` — Model tests with ID assertions
  - All test files in `test/app/controllers/` — Controller tests
  - All test files in `test/resources/views/` — View tests
  - AGENTS.md: TDD structure shows test mirrors lib/ directory

  **Acceptance Criteria**:
  - [ ] `flutter test` → ALL tests pass, exit code 0
  - [ ] No hardcoded integer IDs for model PKs/FKs in test data maps
  - [ ] No `int` variable declarations for ID values in tests

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Full Flutter test suite passes
    Tool: Bash
    Steps:
      1. flutter test
      2. Assert: Exit code 0
      3. Assert: "All tests passed" in output
    Expected Result: All Flutter tests pass
    Evidence: Test output captured

  Scenario: No hardcoded int IDs in Flutter tests
    Tool: Bash (grep)
    Steps:
      1. grep -rn "'id': [0-9]\|'team_id': [0-9]\|'monitor_id': [0-9]" test/
      2. Assert: No matches for ID fields
    Expected Result: All test IDs are strings
    Evidence: Grep output captured
  ```

  **Commit**: YES
  - Message: `test(flutter): update all tests for UUID string IDs`
  - Files: All modified test files in `test/`
  - Pre-commit: `flutter test`

---

- [ ] 8. Full-stack verification — Fresh migration + both test suites

  **What to do**:

  This is the final verification task. Run everything from scratch:

  1. **Backend fresh migration:**
     ```bash
     cd back-end && php artisan migrate:fresh --seed
     ```
     Assert: Exit code 0

  2. **Backend full test suite:**
     ```bash
     cd back-end && php artisan test
     ```
     Assert: All tests pass

  3. **Flutter full test suite:**
     ```bash
     flutter test
     ```
     Assert: All tests pass

  4. **Verification greps** (confirm no remnants):
     ```bash
     # Backend: No foreignId pointing to UUID tables
     grep -rn 'foreignId(' back-end/database/migrations/ --include='*.php'
     # Assert: No matches

     # Backend: No integer validation on ID fields
     grep -rn "'integer'" back-end/app/Http/Requests/Api/V1/ --include='*.php'
     # Assert: Only non-ID fields

     # Flutter: No int ID getters
     grep -rn "get<int>('id')\|get<int>('.*_id')" lib/app/models/
     # Assert: No matches

     # Flutter: No int.tryParse on route IDs
     grep -rn "int.tryParse.*pathParameter" lib/resources/views/
     # Assert: No matches
     ```

  5. **UUID format verification:**
     ```bash
     cd back-end && php artisan tinker --execute="
       \$user = App\Models\User::factory()->create();
       echo 'User ID: ' . \$user->id . PHP_EOL;
       echo 'Is UUID: ' . (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', \$user->id) ? 'YES' : 'NO');
     "
     ```
     Assert: Output shows UUID format, "Is UUID: YES"

  **Must NOT do**:
  - Do NOT modify any files in this task — verification only
  - If failures found, go back to the relevant task (1-7) and fix there

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`magic-framework`]

  **Parallelization**:
  - **Can Run In Parallel**: NO (final gate)
  - **Parallel Group**: Wave 3 (after all other tasks)
  - **Blocks**: None (final task)
  - **Blocked By**: Tasks 4, 7

  **References**:
  - All files modified in Tasks 1-7

  **Acceptance Criteria**:
  - [ ] `php artisan migrate:fresh --seed` → exit code 0
  - [ ] `php artisan test` → ALL pass
  - [ ] `flutter test` → ALL pass
  - [ ] UUID format verified in database
  - [ ] All verification greps pass (no remnants of int IDs)

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Complete stack works with UUIDs
    Tool: Bash
    Steps:
      1. cd back-end && php artisan migrate:fresh --seed
      2. Assert: Exit code 0
      3. cd back-end && php artisan test
      4. Assert: Exit code 0, all pass
      5. flutter test
      6. Assert: Exit code 0, all pass
      7. Verify UUID in DB via tinker
      8. Assert: ID matches UUID regex
    Expected Result: Entire stack works with UUID primary keys
    Evidence: All output captured
  ```

  **Commit**: NO (verification only — no changes)

---

## Commit Strategy

| After Task | Message | Files | Verification |
|------------|---------|-------|--------------|
| 1 | `refactor(db): replace auto-increment integer PKs with UUIDs in all domain migrations` | 18 migration files | `php artisan migrate:fresh` |
| 2+3 | `refactor(backend): add HasUuids to all models, update validation and type hints` | 14 models + 3 form requests + controllers | `php artisan test` |
| 4 | `test(backend): update all tests for UUID primary keys` | test files | `php artisan test` |
| 5 | `refactor(flutter-models): change all model IDs from int to String` | 8 model files | `flutter analyze` |
| 6 | `refactor(flutter): update controllers and views to use String IDs` | controllers + views | `flutter analyze` |
| 7 | `test(flutter): update all tests for UUID string IDs` | test files | `flutter test` |

---

## Success Criteria

### Verification Commands
```bash
# Backend
cd back-end && php artisan migrate:fresh --seed  # Expected: exit code 0
cd back-end && php artisan test                   # Expected: all tests pass

# Frontend
flutter test                                       # Expected: all tests pass

# UUID format check
cd back-end && php artisan tinker --execute="echo App\Models\User::factory()->create()->id;"
# Expected: UUID string like 550e8400-e29b-41d4-a716-446655440000
```

### Final Checklist
- [ ] All "Must Have" items present (HasUuids, uuidMorphs, foreignUuid, String IDs)
- [ ] All "Must NOT Have" items absent (no foreignId to UUID tables, no int IDs in Flutter models)
- [ ] All backend tests pass
- [ ] All frontend tests pass
- [ ] Database generates UUID primary keys
- [ ] No `$table->id()` in domain model migrations
- [ ] No `get<int>('id')` in Flutter models
- [ ] No `int.tryParse` on route ID params in Flutter views
