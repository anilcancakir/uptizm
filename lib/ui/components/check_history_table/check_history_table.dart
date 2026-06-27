import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import '../status_dot/index.dart';
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
/// numeric cells in Geist Mono. The Status cell composes [StatusDot] + a
/// label so no overflow-prone badge pill is needed in table context.
///
/// Each logical row uses an explicit Flutter [Row] with [Expanded]/[SizedBox]
/// columns so column widths are deterministic without relying on Wind
/// `flex-1`/`flex-2` inside a `WDiv` (which defaults to `MainAxisSize.min`).
/// The outer column is a plain [Column] with `CrossAxisAlignment.stretch`
/// so rows fill the available width. Decoration (border-b) is applied via
/// [WDiv] wrappers or [DecoratedBox] per row.
///
/// All cells carry `min-w-0` to prevent overflow on narrow mobile viewports.
///
/// Uses [checkHistoryTableRecipe] for all styling; no raw hex, `Color(0xFF...)`,
/// or `Colors.*` anywhere. Composes [StatusDot] instead of duplicating dot logic.
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

  /// Width of the Response column (fixed, right-aligned numeric).
  static const double _responseWidth = 88;

  /// Width of the Code column (fixed, right-aligned numeric).
  static const double _codeWidth = 56;

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
    // 1. Resolve all slot classNames once; no per-row overhead.
    final classes = checkHistoryTableRecipe();

    // 2. Build the header row.
    final header = _buildHeader(classes);

    // 3. Build each check row.
    final checkRows = [for (final row in rows) _buildRow(row, classes)];

    // 4. Stack header + rows in a full-width column.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [header, ...checkRows],
    );
  }

  // ---------------------------------------------------------------------------
  // Private builders
  // ---------------------------------------------------------------------------

  Widget _buildHeader(Map<String, String> classes) {
    // Use WDiv for the border-b decoration, but an inner Row for column layout
    // so Expanded cells get the max-width constraint they need.
    return WDiv(
      className: classes['header'],
      child: Row(
        mainAxisSize: MainAxisSize.max,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // Time column (flex-1).
          Expanded(child: WText('Time', className: classes['th'])),
          // Region column (flex-1).
          Expanded(child: WText('Region', className: classes['th'])),
          // Status column (flex-2 for the dot + label pair).
          Expanded(flex: 2, child: WText('Status', className: classes['th'])),
          // Response column (fixed width, right-aligned numeric header).
          SizedBox(
            width: _responseWidth,
            child: WText('Response', className: classes['thRight']),
          ),
          // Code column (fixed width, right-aligned numeric header).
          SizedBox(
            width: _codeWidth,
            child: WText('Code', className: classes['thRight']),
          ),
        ],
      ),
    );
  }

  Widget _buildRow(CheckRow row, Map<String, String> classes) {
    // Use WDiv for the border-b decoration, but an inner Row for column layout.
    return WDiv(
      className: classes['row'],
      child: Row(
        mainAxisSize: MainAxisSize.max,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // Timestamp — tabular-nums, muted mono, flex-1.
          Expanded(child: WText(row.time, className: classes['cellMuted'])),
          // Region — muted mono, flex-1.
          Expanded(child: WText(row.region, className: classes['cellMuted'])),
          // StatusDot + status label — flex-2, min-w-0.
          Expanded(
            flex: 2,
            child: WDiv(
              className: classes['statusCell'],
              children: [
                StatusDot(row.status),
                WText(row.status.label, className: 'min-w-0 text-sm text-fg'),
              ],
            ),
          ),
          // Response time — right-aligned numeric, fixed width.
          SizedBox(
            width: _responseWidth,
            child: WText(
              _fmtMs(row.responseMs),
              className: classes['cellMono'],
            ),
          ),
          // HTTP status code — right-aligned numeric, fixed width.
          SizedBox(
            width: _codeWidth,
            child: WText(
              _fmtCode(row.statusCode),
              className: classes['cellMono'],
            ),
          ),
        ],
      ),
    );
  }
}
