import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../../app/mocks/monitors.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/layouts/page_container.dart';
import 'monitor_form.dart';

/// **The monitor edit screen.**
///
/// A faithful Flutter port of the React `MonitorFormPage.tsx`. Resolves the
/// given [id] to a fixture via [findMonitor]; when no monitor matches (or [id]
/// is null) it renders a graceful not-found [EmptyState] rather than crashing.
///
/// When a monitor is found the screen renders a [MonitorForm] prefilled with
/// the monitor's [MonitorSummary.name], [MonitorSummary.url], and
/// [MonitorSummary.regions]. All other fields use [MonitorForm]'s defaults
/// (matching the React `MonitorFormPage.tsx` lines 33-40, which passes only
/// `initialName`, `initialUrl`, and `initialRegions`). Both "Save changes" and
/// "Cancel" navigate to the monitor detail route (`/monitors/<id>`), falling
/// back to `/monitors` when [id] is null.
///
/// Layout discipline mirrors [MonitorDetailView]: a plain Flutter [Column]
/// scaffolds the page body inside [PageContainer] so leaf components receive
/// bounded constraints rather than an unbounded Wind flex context.
///
/// ### Example
/// ```dart
/// // Registered at `/monitors/:id/edit` in app.dart:
/// MonitorEditView(id: 'api')
/// ```
class MonitorEditView extends StatelessWidget {
  /// The monitor identifier resolved against the fixtures via [findMonitor].
  ///
  /// `null` or an unknown id renders the not-found state.
  final String? id;

  /// Creates the [MonitorEditView] for the given monitor [id].
  const MonitorEditView({super.key, this.id});

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the monitor; null or unknown id falls back to not-found so
    //    the screen never crashes on a stale or invalid route parameter.
    final MonitorSummary? monitor = findMonitor(id);
    if (monitor == null) {
      return _buildNotFound();
    }

    // 2. The detail route this screen returns to on submit or cancel.
    final String returnRoute = '/monitors/${monitor.id}';

    // 3. A plain Flutter Column scaffolds the page body inside PageContainer
    //    (same discipline as the sibling views), keeping leaf components
    //    bounded.
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 4. Page header: back link to the monitors list, "Edit monitor"
          //    title, and "Editing <name>." as the subtitle — mirroring the
          //    React PageHeader props.
          PageHeader(
            title: trans('uptizm.monitors.form_title_edit'),
            subtitle: trans('uptizm.monitors.form_editing', {
              'name': monitor.name,
            }),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),
          const SizedBox(height: 24),

          // 5. The prefilled form: name, url, and regions come from the
          //    fixture (React lines 33-40). Interval and SLO are NOT passed
          //    (intervalLabel like '60s' has no 1:1 option value; React does
          //    not pass them either). Everything else uses MonitorForm's
          //    defaults.
          MonitorForm(
            initialName: monitor.name,
            initialUrl: monitor.url,
            initialRegions: monitor.regions,
            submitLabel: trans('uptizm.monitors.form_submit_save'),
            onSubmit: () => MagicRoute.to(returnRoute),
            onCancel: () => MagicRoute.to(returnRoute),
          ),
        ],
      ),
    );
  }

  /// Builds the graceful not-found state shown when [findMonitor] returns null.
  ///
  /// Reuses the same error-load copy and [EmptyState] idiom as
  /// [MonitorDetailView._buildNotFound] so the two screens behave consistently
  /// on an unknown route id.
  Widget _buildNotFound() {
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PageHeader(
            title: trans('uptizm.monitors.error_load_title'),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),
          const SizedBox(height: 24),
          EmptyState(
            icon: Icons.search_off,
            title: trans('uptizm.monitors.error_load_title'),
            description: trans('uptizm.monitors.error_load_description'),
          ),
        ],
      ),
    );
  }
}
