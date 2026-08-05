import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/refetches_on_mount.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/monitor.dart';
import 'monitor_form.dart';
import 'monitor_form_support.dart' show keyValueRowsFromMap;

/// **The monitor edit screen.**
///
/// A faithful Flutter port of the React `MonitorFormPage.tsx`. Resolves the
/// given [id] through [MonitorController.monitorById]; when no monitor matches (or [id]
/// is null) it renders a graceful not-found [MSEmptyState] rather than
/// crashing.
///
/// When a monitor is found the screen renders a [MonitorForm] prefilled from
/// EVERY field the form owns, not just name/url/regions: "Save changes" posts the
/// form's complete field map, so any field left at a create-time default would be
/// written over the operator's configuration. "Save changes" fires
/// [MonitorController.save] with the form's full field map (a `PUT` to the
/// monitor's resource route); "Cancel" navigates to the monitor detail route
/// WITHOUT writing anything. Both land on the monitor detail route, falling
/// back to `/monitors` when [id] is null.
///
/// Layout discipline mirrors [MonitorDetailView]: a plain Flutter [Column]
/// scaffolds the page body inside [MSPageContainer] so leaf components receive
/// bounded constraints rather than an unbounded Wind flex context.
///
/// ### Example
/// ```dart
/// // Registered at `/monitors/:id/edit` in app.dart:
/// MonitorEditView(id: 'api')
/// ```
class MonitorEditView extends MagicStatefulView<MonitorController> {
  /// The monitor identifier resolved against the fixtures via
  /// [MonitorController.monitorById].
  ///
  /// `null` or an unknown id renders the not-found state.
  final String? id;

  /// Creates the [MonitorEditView] for the given monitor [id].
  const MonitorEditView({super.key, this.id});

  @override
  State<MonitorEditView> createState() => _MonitorEditViewState();
}

class _MonitorEditViewState
    extends MagicStatefulViewState<MonitorController, MonitorEditView>
    with RefetchesOnMount<MonitorController, MonitorEditView> {
  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(MonitorController.new);
    super.initState();

    // One-time single-resource refresh for the prefill (never from build; see
    // [MonitorController.refreshOne], which notifies listeners).
    final String? id = widget.id;
    if (id != null) {
      controller.refreshOne(id);
    }
  }

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so opening this screen would
  /// otherwise render whatever the roster held when it was first fetched. A
  /// prefilled form is the sharp edge here, since it writes what it shows back on
  /// save. See [RefetchesOnMount].
  @override
  Future<void> refetch() => controller.reload();

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the monitor. A null answer means either "the inventory read is
    //    still in flight" or "no monitor has this id", and only the second is a
    //    not-found. This form is the sharper half of that distinction: it writes
    //    back what it shows, so it must never invite a save against a prefill it
    //    could not read yet.
    final Monitor? monitor = controller.monitorById(widget.id);
    if (monitor == null) {
      return controller.isFirstLoad ? _buildPending() : _buildNotFound();
    }

    // 2. A Wind flex column scaffolds the page body inside MSPageContainer with a
    //    24px (gap-6) header->form rhythm; each leaf stays bounded.
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // 3. Page header: back link to the monitors list, "Edit monitor"
          //    title, and "Editing <name>." as the subtitle — mirroring the
          //    React PageHeader props.
          MSPageHeader(
            title: trans('uptizm.monitors.form_title_edit'),
            subtitle: trans('uptizm.monitors.form_editing', {
              'name': monitor.name,
            }),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),

          // 4. The prefilled form. EVERY field the form owns is seeded from the
          //    monitor, because Submit fires a real PUT with the form's complete
          //    field map: any field left at a create-time default would be
          //    written over the operator's configuration. That is not
          //    hypothetical - while this passed only name/url/regions, renaming a
          //    monitor reset its method, interval, timeout and SLO, and replaced
          //    its real request headers with what was then the create default, a
          //    placeholder `Authorization: Bearer …` that went out on every
          //    probe. That default is empty now, which removes the payload but
          //    not the reason to seed every field.
          //    `isEdit` additionally stops the form posting defaults for the
          //    settings it exposes no control for (auth, tags, status-page and
          //    SSL flags), so those survive a save.
          //
          //    Cancel navigates to the detail route WITHOUT writing; it must
          //    never fire the same save call as Submit.
          MonitorForm(
            isEdit: true,
            initialName: monitor.name ?? '',
            initialUrl: monitor.url ?? '',
            initialType: monitor.type ?? 'http',
            initialRegions: monitor.regions,
            initialIntervalSec: monitor.checkIntervalSec,
            initialHeaders: keyValueRowsFromMap(monitor.requestHeaders),
            initialPolicy: monitor.escalationPolicyId,
            initialSlo: monitor.sloTarget?.toString() ?? '',
            initialMethod: monitor.method ?? 'get',
            initialTimeoutSec: monitor.timeoutSec.toString(),
            initialBody: monitor.requestBody ?? '',
            initialAiMode: monitor.aiMode,
            initialAlertOnDown: monitor.alertOnDown,
            initialAlertOnRecover: monitor.alertOnRecover,
            submitLabel: trans('uptizm.monitors.form_submit_save'),
            onSubmit: (fields) => controller.save(monitor.id, fields),
            onCancel: () => MagicRoute.to('/monitors/${monitor.id}'),
          ),
        ],
      ),
    );
  }

  /// Builds the pending state shown while the inventory read that will decide
  /// whether this monitor exists is still in flight.
  ///
  /// Placeholder bars rather than an empty column: every [MSSkeleton] carries an
  /// explicit height because it wraps a childless `WDiv` and has nothing of its
  /// own to measure, so one without a height lays out 0px tall and the operator
  /// sees a blank screen.
  Widget _buildPending() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('common.loading'),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),
          WDiv(
            className: 'flex flex-col gap-4',
            children: const [
              MSSkeleton(height: 56),
              MSSkeleton(height: 56),
              MSSkeleton(height: 56),
              MSSkeleton(height: 120),
            ],
          ),
        ],
      ),
    );
  }

  /// Builds the graceful not-found state shown when [MonitorController.monitorById]
  /// returns null.
  ///
  /// Reuses the same error-load copy and [MSEmptyState] idiom as
  /// [MonitorDetailView] so the two screens behave consistently on an unknown
  /// route id.
  Widget _buildNotFound() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('uptizm.monitors.error_load_title'),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),
          MSEmptyState(
            icon: Icons.search_off,
            title: trans('uptizm.monitors.error_load_title'),
            description: trans('uptizm.monitors.error_load_description'),
          ),
        ],
      ),
    );
  }
}
