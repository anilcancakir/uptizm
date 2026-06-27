import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
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
/// header / data row is a `flex flex-row items-center` [WDiv], and the columns
/// size themselves through the className track tokens (`flex-1` / `flex-2` for
/// the text columns, fixed `w-*` + `shrink-0` for the numeric columns). A child
/// carrying `flex-1`/`flex-2` is wrapped in an `Expanded` by the parent flex
/// row, so no raw Flutter `Row`/`Expanded`/`SizedBox` is needed.
///
/// All flexible cells carry `min-w-0` to prevent overflow on narrow viewports.
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
  /// Typically sourced from [recentChecks] or a live API response.
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

    // The table is a full-width Wind flex column: a header row followed by one
    // flex row per check. No raw Flutter layout widgets.
    return WDiv(
      className: classes['table'],
      children: [
        _buildHeader(classes),
        for (final row in rows) _buildRow(row, classes),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Private builders
  // ---------------------------------------------------------------------------

  Widget _buildHeader(Map<String, String> classes) {
    // A Wind flex row; each cell carries its own track sizing (flex-1 / flex-2
    // / fixed w-*) so the parent flex row distributes the columns.
    return WDiv(
      className: classes['header'],
      children: [
        WText('Time', className: classes['th']),
        WText('Region', className: classes['th']),
        WText('Status', className: classes['thStatus']),
        WText('Response', className: classes['thResponse']),
        WText('Code', className: classes['thCode']),
      ],
    );
  }

  Widget _buildRow(CheckRow row, Map<String, String> classes) {
    // A Wind flex row mirroring the header tracks. The Status cell is its own
    // flex row holding a left-aligned [StatusBadge]; the numeric cells take a
    // fixed, right-aligned track.
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
