# Decisions

## [2026-02-06T19:59] Plan Initialization

**Storage Strategy**: Normalized table `status_page_monitor_metrics` (not JSON column)
**API Design**: Extend existing `attachMonitors` endpoint (not separate endpoint)
**Public Page Display**: Tip-based styling (numeric=value+unit, status=colored dot, string=plain text)
**Cache Strategy**: `Cache::forget` after metric changes, accept 5min stale data
**Orphaned Metrics**: Silently skip on public page if metric_key not found, show "N/A"
**Field Name Mismatch**: Document as known tech debt, use correct backend field names for new metric fields

## Execution Strategy

**Wave 1** (Parallel): Tasks 1 & 4 (migration + CSS)
**Wave 2** (Parallel): Tasks 2, 3, 5 (API, public page, Flutter UI)
**Wave 3** (Sequential): Task 6 (integration tests)
