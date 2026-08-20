import 'dart:async' show unawaited;

import 'package:magic/magic.dart';

/// Refetches a view's data every time the view mounts.
///
/// ## Why this exists
///
/// magic caches controllers as Type-keyed singletons and fires `onInit` ONCE per
/// controller instance, not once per view mount. A controller that loads its data
/// in `onInit` therefore fetches on the first view that resolves it and never
/// again for the lifetime of the app, so navigating away and back re-renders the
/// same cached rows.
///
/// On a monitoring product that reads as fabricated data rather than as staleness:
/// a live QA pass found the dashboard insisting on `1 / 3` monitors up, `65.08%`
/// uptime and `20ms` average while the API served `2 / 4`, `63.38%` and `75ms`,
/// and the monitors list omitting a monitor created moments earlier. Only a hard
/// browser reload cleared it. Aggregates did refresh when a Reverb broadcast
/// happened to fire (a monitor status transition, or an incident opening or
/// resolving), which in a healthy fleet can be hours apart, so the realtime path
/// masked the gap rather than closing it.
///
/// ## Usage
///
/// Mix onto a [MagicStatefulViewState] and point [refetch] at the controller's
/// reload:
///
/// ```dart
/// class _MonitorsListViewState
///     extends MagicStatefulViewState<MonitorController, MonitorsListView>
///     with RefetchesOnMount<MonitorController, MonitorsListView> {
///   @override
///   Future<void> refetch() => controller.ensureFresh();
/// }
/// ```
///
/// `ensureFresh`, not `reload`. The mount that CREATES the controller has
/// already started the same load from `onInit`, and both firing sent every
/// request twice: measured on a phone as two `GET /monitors`, two
/// `GET /incidents`, and eight dashboard calls where four would do.
/// `ensureFresh` joins that in-flight load and refetches on every later mount,
/// which is the staleness this mixin exists to prevent. Coalescing inside
/// `reload` instead would also join a refresh issued right after a mutation to
/// a request that started before it, and hand back a snapshot without the row
/// the operator just created.
///
/// The refetch is fire-and-forget: `build()` renders the cached data immediately
/// and the view rebuilds when the fresh data lands, so a mount never blocks on
/// the network. Every controller's `reload()` already keeps its last-known-good
/// cache on failure, so a failed refetch leaves the screen as it was instead of
/// flickering into an empty state.
mixin RefetchesOnMount<
  C extends MagicController,
  W extends MagicStatefulView<C>
>
    on MagicStatefulViewState<C, W> {
  @override
  void initState() {
    super.initState();
    unawaited(refetch());
  }

  /// The refetch to run on each mount, normally `controller.reload()`.
  Future<void> refetch();
}
