# Architectural Decisions

*Key decisions made during implementation.*

---

## [Initial] Core Design Decisions
- Incident ↔ Monitor: Many-to-Many (pivot table)
- Incident ↔ Status Page: Indirect (through shared monitors)
- Auto-incident threshold: Per-monitor field (incident_threshold)
- Auto-incident default: "investigating" + "partial_outage"
- Anti-flapping: 30 minute cooldown
- Recovery: Auto "Monitoring" update, NOT auto-resolve
- Announcements: Linked to Status Page (required)
- Scheduled announcements: everyMinute() artisan command
## [2026-02-07T15:03:16Z] Wave 1 Complete - Git Repository Structure

**Finding**: The main git repository (./) tracks Flutter code, but `back-end/` is excluded via .gitignore. Backend has no separate git repo.

**Implication**: Backend changes (migrations, models, tests) are completed and tested but not version-controlled in git. Only Flutter changes are committed.

**Action**: Proceeded with Flutter-only commit for Wave 1. Backend work verified via artisan test (30 tests passing).

Wave 1 Status: ✅ COMPLETE
- Task 1: Backend Incidents (18 tests PASS) - not committed
- Task 6: Backend Announcements (12 tests PASS) - not committed
- Task 9: Flutter Models (70 tests PASS) - committed

