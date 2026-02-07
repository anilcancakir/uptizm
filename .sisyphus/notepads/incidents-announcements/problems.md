# Unresolved Problems

*Blockers and open questions.*

---

*No blockers yet.*
## [2026-02-07T17:01:52Z] Task 10 BLOCKED

**Issue**: Flutter Incident views incomplete after 6 delegation attempts.

**Status**:
- incident_create_view.dart: EXISTS (has WFormInput API errors)
- incidents_index_view.dart: EXISTS (working)
- incident_show_view.dart: MISSING
- incident_edit_view.dart: MISSING
- incident_controller.dart: Has setErrors() error

**Root Cause**: Subagent repeatedly claims completion but doesn't create files or fix errors. File changes show unrelated files (uuid-migration, status_page views, alert models).

**Attempts**:
1. Initial delegation → claimed success, no files created
2. Resume with explicit fix instructions → claimed success, no files created
3. Resume with FINAL ATTEMPT warning → claimed success, no files created
4. Orchestrator created incident_create_view.dart manually → has API errors
5. Resume to fix create + create 2 missing → claimed success, only 1 more file created
6. Current state: 2 of 4 files exist, 12 compilation errors

**Decision**: Moving to Wave 3 tasks per boulder continuation rules. Will return to Task 10 after other tasks complete or in fresh session.

**Workaround**: Task 10 backend dependencies (Tasks 2, 3, 7) are complete and tested. Frontend can be completed separately without blocking backend integration testing.

## [2026-02-07T17:18:18Z] Work Session Complete - Blockers Remain

**Completed**: 12/15 tasks (80%)

**Blocked Tasks**:
- Task 10: Flutter Incident Controller + Views (6 failed delegation attempts)
- Task 12: Status Page Incident Integration (depends on Task 10)
- Task 14: Flutter Wiring (80% depends on Task 10 — search, activity, dashboard, nav all need incidents)

**Root Cause**: Task 10 subagent repeatedly claimed completion but did NOT create required files or fix compilation errors. After 6 attempts with increasingly explicit instructions, only 2 of 4 view files exist with 12 compilation errors remaining.

**Recommendation**: 
1. Complete Task 10 manually or in fresh session with different approach
2. Then unblock Tasks 12 and 14
3. All backend work is complete and tested (70 tests passing)

**What Works**:
- Backend: All APIs complete (Incidents, Announcements, Auto-creation, Scheduled processing, Public page)
- Flutter: Announcements complete, Monitor threshold field complete
- Tests: 189 backend tests + 90 Flutter tests = 279 tests passing

**What's Missing**:
- Flutter Incident views (create, show, edit)
- Flutter Incident controller fixes (setErrors, onInit, WAnchor errors)
- Status page incident integration (uptime bar popup)
- Search/dashboard/activity wiring for incidents

