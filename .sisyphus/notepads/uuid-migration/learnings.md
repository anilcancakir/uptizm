# Learnings — UUID Migration

## Conventions

## Patterns

## Gotchas

## UUID Migration Execution Summary

### Patterns Discovered

1. **Primary Key Pattern**: All domain tables use `$table->uuid('id')->primary()` instead of `$table->id()`
2. **Foreign Key Pattern**: All foreign keys use `$table->foreignUuid('column_name')` instead of `$table->foreignId()`
3. **Polymorphic Pattern**: Polymorphic relationships use `$table->uuidMorphs('name')` instead of `$table->morphs('name')`
4. **Constraint Chaining**: Foreign keys maintain constraint chains like `.constrained()->cascadeOnDelete()` and `.nullOnDelete()`

### Conventions Applied

- **Consistency**: All 18 domain migrations converted uniformly
- **Preserved Constraints**: All cascade/null delete behaviors preserved during conversion
- **Skipped Internal Tables**: Cache and jobs tables (Laravel internals) left untouched with `$table->id()`
- **Add-Column Migrations**: Migrations that only add columns to existing tables were not modified (they inherit types from base table)

### Gotchas Avoided

1. **Symlink Issue**: Initial `git add back-end/database/migrations/` failed due to symlink traversal. Resolved with `git add -A`
2. **Morphs Conversion**: Both `morphs()` and `uuidMorphs()` needed conversion in notifications and personal_access_tokens tables
3. **Constraint Syntax**: `foreignUuid()` maintains same constraint syntax as `foreignId()` - no API changes needed
4. **TimescaleDB Migration**: The setup_timescaledb migration references monitor_id in raw SQL but inherits UUID type from base table - no manual SQL changes required

### Verification Results

- ✅ All 18 migrations edited successfully
- ✅ Zero `foreignId()` matches in migrations (verified with grep)
- ✅ Only 2 `$table->id()` matches (both in jobs table - correct)
- ✅ `php artisan migrate:fresh` passed with exit code 0
- ✅ All 31 migrations ran successfully in sequence
- ✅ Commit created: `refactor(db): replace auto-increment integer PKs with UUIDs in all domain migrations`

### Files Modified (18 total)

1. 0001_01_01_000000_create_users_table.php (users + sessions tables)
2. 2025_12_29_205337_create_personal_access_tokens_table.php
3. 2025_12_29_220405_create_newsletter_subscribers_table.php
4. 2025_12_29_222748_create_teams_table.php
5. 2025_12_29_222749_create_team_user_table.php
6. 2025_12_30_184124_create_team_invitations_table.php
7. 2025_12_30_214054_add_current_team_id_to_users_table.php
8. 2026_02_02_122236_create_monitors_table.php
9. 2026_02_02_122321_create_monitor_checks_table.php
10. 2026_02_03_000002_create_monitor_metric_values_table.php
11. 2026_02_03_000003_create_relay_nodes_table.php
12. 2026_02_03_094805_create_notifications_table.php
13. 2026_02_03_105848_create_notification_preferences_table.php
14. 2026_02_05_185359_create_alert_rules_table.php
15. 2026_02_05_185417_create_alerts_table.php
16. 2026_02_05_185434_create_alert_rule_states_table.php
17. 2026_02_06_175454_create_status_pages_table.php (status_pages + status_page_monitor tables)
18. 2026_02_06_200046_create_status_page_monitor_metrics_table.php

### Next Steps

The database schema is now fully UUID-based for all domain tables. Next phase should:
1. Update Laravel models to use UUID primary keys (add `protected $keyType = 'string'` and `public $incrementing = false`)
2. Update Flutter models to handle UUID types in serialization/deserialization
3. Update API resources to return UUIDs in responses
4. Update tests to use UUID factories instead of integer IDs

## Task 2: Laravel Model UUID Configuration

### Execution Summary

**Completed**: All 14 Laravel models updated with UUID support.

### Changes Applied

1. **Import Addition**: Added `use Illuminate\Database\Eloquent\Concerns\HasUuids;` to all 14 models
2. **Trait Registration**: Added `HasUuids` to trait list in each model
3. **Key Configuration**: Added two properties to each model:
   ```php
   public $incrementing = false;
   protected $keyType = 'string';
   ```
4. **Type Hint Updates**: Changed all scope/static method ID parameters from `int` to `string`:
   - `Monitor::scopeForTeam(string $teamId)`
   - `AlertRule::scopeForTeam(string $teamId)`
   - `Alert::scopeForMonitor(string $monitorId)` and `scopeForTeam(string $teamId)`
   - `AlertRuleState::scopeForRule(string $ruleId)` and `scopeForMonitor(string $monitorId)`
   - `AlertRuleState::findForRuleAndMonitor(string $ruleId, string $monitorId)`
   - `StatusPage::scopeForTeam($teamId)` - no type hint, left as-is

### Models Updated (14 total)

1. ✅ User.php
2. ✅ Team.php
3. ✅ Monitor.php
4. ✅ MonitorCheck.php
5. ✅ MonitorMetricValue.php
6. ✅ AlertRule.php
7. ✅ Alert.php
8. ✅ AlertRuleState.php
9. ✅ StatusPage.php
10. ✅ StatusPageMonitorMetric.php
11. ✅ NotificationPreference.php
12. ✅ TeamInvitation.php
13. ✅ RelayNode.php
14. ✅ NewsletterSubscriber.php

### Verification Results

- ✅ `grep -l 'HasUuids' back-end/app/Models/*.php | wc -l` = **14** (all models have trait)
- ✅ `grep -rn 'int \$teamId\|int \$monitorId\|int \$ruleId\|int \$alertId' back-end/app/Models/` = **0 matches** (all type hints updated)
- ✅ Commit created: `refactor(models): add HasUuids trait to all domain models, update int type hints to string`

### Key Patterns

1. **HasUuids Trait**: Automatically generates UUID primary keys on model creation
2. **Incrementing Flag**: Set to `false` because UUIDs are not auto-incrementing
3. **Key Type**: Set to `'string'` because UUIDs are string values, not integers
4. **Scope Methods**: All ID parameters must be `string` type to match UUID columns in database

### Gotchas Avoided

1. **Relationship Preservation**: Did NOT modify relationship definitions (belongsTo, hasMany, etc.) - they work automatically with UUIDs
2. **Fillable Arrays**: Did NOT modify `$fillable` arrays - IDs are never mass-assignable
3. **Non-ID Integers**: Did NOT change type hints for non-ID integer parameters (status codes, intervals, etc.)
4. **StatusPage Scope**: `scopeForTeam($teamId)` has no type hint in original - left as-is (PHP will accept string)

### Next Steps

The models are now configured for UUID primary keys. Next phase should:
1. Update API resources to ensure UUID responses
2. Update factories to generate UUIDs
3. Update tests to use UUID values
4. Verify API endpoints work with UUID parameters

## Task 3: Laravel Validation Rules & Controller Type Hints

### Execution Summary

**Completed**: All validation rules updated from `'integer'` to `'uuid'` for ID fields. Controllers reviewed for inline validation and casts.

### Changes Applied

#### Form Requests (3 files)

1. **SwitchTeamRequest.php**
   - Changed: `'team_id' => ['required', 'integer', 'exists:teams,id']`
   - To: `'team_id' => ['required', 'uuid', 'exists:teams,id']`

2. **StoreStatusPageRequest.php**
   - Changed: `'monitor_ids.*' => ['integer', 'exists:monitors,id']`
   - To: `'monitor_ids.*' => ['uuid', 'exists:monitors,id']`

3. **UpdateStatusPageRequest.php**
   - Changed: `'monitor_ids.*' => ['integer', 'exists:monitors,id']`
   - To: `'monitor_ids.*' => ['uuid', 'exists:monitors,id']`

#### Controllers (1 file)

4. **StatusPageController.php** (2 inline validations)
   - Line 147: Changed `'monitors.*.monitor_id' => ['required', 'integer', 'exists:monitors,id']`
   - Line 234: Changed `'monitors.*.monitor_id' => ['required', 'uuid', 'exists:monitors,id']`
   - Both in `attachMonitors()` and `reorderMonitors()` methods

### Verification Results

- ✅ `grep -n "'integer'" back-end/app/Http/Requests/Api/V1/SwitchTeamRequest.php` = **0 matches**
- ✅ `grep -n "'uuid'" back-end/app/Http/Requests/Api/V1/StoreStatusPageRequest.php` = **1 match** (line 34)
- ✅ `grep -n "'uuid'" back-end/app/Http/Requests/Api/V1/UpdateStatusPageRequest.php` = **1 match** (line 37)
- ✅ `grep -n "'uuid'" back-end/app/Http/Controllers/Api/V1/StatusPageController.php` = **2 matches** (lines 147, 234)
- ✅ No `(int)` casts on ID fields found in any controller
- ✅ No `intval()` calls on ID fields found in any controller
- ✅ No remaining `'integer'` validations on ID fields in controllers

### Services Review

**MonitorCheckService.php**: No ID-related type hints to change. Service accepts `Monitor $monitor` object, not raw IDs.

**AlertEvaluationService.php**: No ID-related type hints to change. Service accepts `AlertRule $rule` and `Monitor $monitor` objects, not raw IDs.

### Key Patterns

1. **Validation Rule Consistency**: All ID field validations now use `'uuid'` rule instead of `'integer'`
2. **Exists Rule Preservation**: `'exists:table,column'` rules preserved - they work with both integer and UUID columns
3. **Non-ID Integers Untouched**: Business logic integers (`display_order`, `check_interval`, `expected_status_code`, `timeout`, `consecutive_checks`) remain as `'integer'` validation
4. **Service Layer**: Services use type-hinted Model objects, not raw IDs - no changes needed

### Gotchas Avoided

1. **Business Logic Integers**: Did NOT change validation for non-ID integers (display_order, check_interval, etc.)
2. **Service Type Hints**: Services receive Model objects, not raw IDs - no type hint changes needed
3. **Relationship Preservation**: Eloquent relationships handle UUID foreign keys automatically

### Next Steps

The validation layer is now aligned with UUID primary keys. Next phase should:
1. Update API resources to ensure UUID responses
2. Update factories to generate UUIDs
3. Update tests to use UUID values
4. Run full test suite to verify API endpoints work with UUID parameters
