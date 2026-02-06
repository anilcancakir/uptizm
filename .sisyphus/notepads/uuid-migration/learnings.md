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
