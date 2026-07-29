import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/support/monitor_types.dart' show CheckRow;
import '../status_badge/index.dart';
import 'check_history_table.recipe.dart';

/// **The Recent Checks History Table**
///
/// Renders a column of recent probe results. Each row shows five columns
/// matching the React `CheckHistoryTable.tsx` design:
///
///   Time | Region | Status (dot + label) | Response | Code
///
/// Header labels are quiet uppercase with letter-spacing, muted foreground.
/// Body rows are separated by hairline dividers. Time and Region columns use
/// muted Geist Mono tabular figures. Response and Code are right-aligned
/// numeric cells in Geist Mono. The Status cell renders a [StatusBadge] (the
/// soft pill with a leading dot, matching the React original), left-aligned so
/// the pill hugs its content.
///
/// The layout is pure Wind: the table is a `flex flex-col w-full` [WDiv], each
/// header / data row is a `flex flex-row items-center` [WDiv]. Time / Region /
/// Status are `flex-1` (equal share) so they distribute the full-width desktop
/// table evenly; Response / Code keep a fixed `w-*` + `shrink-0` right-aligned
/// numeric track. The table also carries `min-w-[680px]` so it never collapses
/// below the point where a flex column would squeeze its content.
///
/// The table is wrapped in a Wind `overflow-x-auto` [WDiv], so `w-full` fills the
/// available width on desktop while the 680px floor makes the whole grid scroll
/// horizontally as one unit on a narrow phone (no cell-text wrapping, no
/// status-pill overflow). The floor lives on the table, not the columns: a
/// `flex-1` column is a Flutter [Expanded], which tightly constrains its child
/// and IGNORES `min-w`, so a per-column floor would not hold. A `LayoutBuilder`
/// stretch-to-fill wrapper is avoided on purpose: the detail page measures
/// intrinsic heights (`items-stretch`) and `LayoutBuilder` throws under intrinsic
/// measurement.
///
/// Uses [checkHistoryTableRecipe] for all styling; no raw hex, `Color(0xFF...)`,
/// or `Colors.*` anywhere. Composes [StatusBadge] instead of duplicating the
/// status dot + pill logic.
///
/// ### Example Usage:
///
/// ```dart
/// // All recent checks from the fixture:
/// CheckHistoryTable(rows: recentChecks)
///
/// // A custom subset:
/// CheckHistoryTable(
///   rows: recentChecks.take(3).toList(),
/// )
/// ```
@immutable
class CheckHistoryTable extends StatelessWidget {
  /// The ordered list of check results to display.
  ///
  /// Typically sourced from a monitor's recent-checks API response, most
  /// recent first.
  final List<CheckRow> rows;

  /// Creates a [CheckHistoryTable] for the given [rows].
  const CheckHistoryTable({super.key, required this.rows});

  /// Formats an optional response-time value.
  ///
  /// Returns `'—'` when [ms] is null (probe timed out or produced no timing).
  static String _fmtMs(int? ms) => ms == null ? '—' : '${ms}ms';

  /// Formats an optional HTTP status code.
  ///
  /// Returns `'—'` when [code] is null.
  static String _fmtCode(int? code) => code?.toString() ?? '—';

  @override
  Widget build(BuildContext context) {
    // Resolve all slot classNames once; no per-row overhead.
    final classes = checkHistoryTableRecipe();

    // The table is a Wind `w-full` flex column: a header row followed by one flex
    // row per check. Content columns flex-1 to fill the desktop width; the table
    // floors at 680px (min-w) so a flex column never squeezes its content below
    // the point where the widest status badge fits.
    final Widget table = WDiv(
      className: classes['table'],
      children: [
        _buildHeader(classes),
        for (final row in rows) _buildRow(row, classes),
      ],
    );

    // Wrap in Wind's `overflow-x-auto` so the whole grid scrolls sideways as one
    // unit on a narrow phone (viewport < 680px) instead of squeezing the cells
    // until their text wraps or the status pill overflows; wider surfaces show
    // the full table (w-full) with no scrollbar. This is the idiomatic Wind
    // horizontal scroll (a WDiv-level SingleChildScrollView), no raw Flutter
    // layout needed.
    return WDiv(className: 'overflow-x-auto', child: table);
  }

  // ---------------------------------------------------------------------------
  // Private builders
  // ---------------------------------------------------------------------------

  Widget _buildHeader(Map<String, String> classes) {
    // A Wind flex row mirroring the data rows: Time / Region / Status flex-1,
    // Response / Code a fixed right-aligned track, so the columns line up.
    return WDiv(
      className: classes['header'],
      children: [
        WText(trans('uptizm.monitors.check_col_time'), className: classes['th']),
        WText(
          trans('uptizm.monitors.check_col_region'),
          className: classes['th'],
        ),
        WText(
          trans('uptizm.monitors.check_col_status'),
          className: classes['thStatus'],
        ),
        WText(
          trans('uptizm.monitors.check_col_response'),
          className: classes['thResponse'],
        ),
        WText(
          trans('uptizm.monitors.check_col_code'),
          className: classes['thCode'],
        ),
      ],
    );
  }

  Widget _buildRow(CheckRow row, Map<String, String> classes) {
    // A Wind flex row mirroring the header's tracks. The Status cell is its own
    // flex-1 row holding a left-aligned [StatusBadge]; the numeric cells are a
    // fixed right-aligned track.
    return WDiv(
      className: classes['row'],
      children: [
        WText(row.time, className: classes['cellId']),
        WText(row.region, className: classes['cellId']),
        WDiv(
          className: classes['statusCell'],
          child: StatusBadge(row.status, size: StatusBadgeSize.sm),
        ),
        WText(_fmtMs(row.responseMs), className: classes['cellResponse']),
        WText(_fmtCode(row.statusCode), className: classes['cellCode']),
      ],
    );
  }
}
