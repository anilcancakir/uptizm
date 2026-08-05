import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/support/status_page_types.dart' show Subscriber;
import '../../../app/models/status_page.dart';
import '../../../ui/components/kpi_stat_card/index.dart';

/// **The Status Page Subscribers screen.**
///
/// A faithful Flutter port of the React `StatusPageSubscribersPage`: the
/// subscriber roster for a single status page. It resolves a page [id] to a
/// fixture via [StatusPageController.configById] and renders, in the React
/// section order:
///
/// 1. A "← {page.name}" breadcrumb back to the status-page editor, built with
///    [PageHeader.backLabel] / [PageHeader.backFallback] (the app's unified
///    back-affordance, same as [IncidentDetailView]).
/// 2. **Header** — title "Subscribers", a subtitle naming the page, and an
///    "Export CSV" action disabled when there are zero subscribers.
/// 3. **KPI row** — total subscriber count and the page's subscriptions
///    on/off state.
/// 4. **Body** — when subscriptions are off or there are no subscribers, a
///    dashed-border [MSEmptyState] (message branches on
///    [StatusPage.subscriptionsEnabled]) with an "Open editor" action
///    back to `/status/<id>`. Otherwise a search [Input] over a striped
///    [Card] of subscriber rows, each with a Remove [Button] that opens a
///    [MagicStarterConfirmDialog] before mutating local state.
///
/// When [StatusPageController.configById] returns `null` it renders a graceful
/// not-found [MSEmptyState] rather than crashing on an unknown route id.
///
/// The subscriber roster is live: [StatusPageController.subscribersFor] fetches
/// it from `GET /status-pages/<id>/subscribers`, and Remove delegates to
/// [StatusPageController.removeSubscriber] (optimistic local remove +
/// `DELETE .../subscribers/<id>` + a `Magic.success`/`Magic.error` toast,
/// reverting via a refetch on failure). Only the search [_query] stays local.
/// The page body is a Wind flex column; Wind utilities carry both spacing and
/// leaf styling.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/status/:id/subscribers` content:
/// MagicStarter.view.makeLayout(
///   'layout.app',
///   child: const StatusPageSubscribersView(id: 'acme'),
/// )
/// ```
@immutable
class StatusPageSubscribersView
    extends MagicStatefulView<StatusPageController> {
  /// The status-page identifier resolved against the fixtures via
  /// [StatusPageController.configById].
  ///
  /// `null` or an unknown id renders a graceful not-found [MSEmptyState].
  final String? id;

  /// Creates the [StatusPageSubscribersView] for the given page [id].
  const StatusPageSubscribersView({super.key, this.id});

  @override
  State<StatusPageSubscribersView> createState() =>
      _StatusPageSubscribersViewState();
}

class _StatusPageSubscribersViewState
    extends
        MagicStatefulViewState<
          StatusPageController,
          StatusPageSubscribersView
        > {
  /// The current search query, matched against the subscriber email.
  ///
  /// The only local (ephemeral) state; the subscriber roster lives in
  /// [StatusPageController].
  String _query = '';

  @override
  void initState() {
    Magic.findOrPut(StatusPageController.new);
    super.initState();
  }

  /// Subscribers whose email contains the current (trimmed,
  /// case-insensitive) [_query].
  List<Subscriber> get _visible {
    final List<Subscriber> roster = controller.subscribersFor(widget.id);
    final String trimmed = _query.trim().toLowerCase();
    if (trimmed.isEmpty) return roster;
    return roster
        .where((s) => s.email.toLowerCase().contains(trimmed))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the status page; a null / unknown id falls back to a
    //    graceful not-found state so the screen never crashes on an unknown
    //    route id.
    final StatusPage? page = controller.configById(widget.id);
    if (page == null) {
      // `configById` reads the page roster, which answers null both while the
      // first read is in flight and when the id is genuinely gone. This screen
      // already draws that distinction for its SUBSCRIBER roster (see
      // `rosterResolved` below); the page lookup above it needs the same care,
      // or a deep link opens on the empty state for a page that exists.
      return controller.isFirstLoad ? _buildPending() : _buildNotFound();
    }

    // 2. Compose the page body as a Wind flex column: the 24px section rhythm
    //    is carried by gap-6, not SizedBox spacers.
    final String pageName = page.name ?? '';
    final bool hasSubscribers = controller.subscribersFor(page.id).isNotEmpty;
    // Whether THIS page's roster has come back yet. The roster is a lazy
    // per-page cache, so a sibling page having loaded says nothing about this
    // one (see [StatusPageController.hasResolvedSubscribers]).
    final bool rosterResolved = controller.hasResolvedSubscribers(page.id);
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('uptizm.status.subscribers_title'),
            subtitle: trans('uptizm.status.subscribers_subtitle', {
              'page': pageName,
            }),
            backLabel: pageName,
            backFallback: '/status/${page.id}',
            actions: [
              MSButton(
                intent: ButtonIntent.secondary,
                onPressed: hasSubscribers
                    ? () {
                        // Export is a mock action: no CSV is generated, only
                        // the disabled-state contract from the plan applies.
                      }
                    : null,
                child: WText(trans('uptizm.status.subscribers_export_csv')),
              ),
            ],
          ),

          // 3. KPI row: total subscribers + subscriptions on/off.
          _buildKpiRow(page, rosterResolved: rosterResolved),

          // 4. Body: the subscriptions-off notice, a skeleton while this page's
          //    roster is still in flight, the no-subscribers-yet empty state, or
          //    search + the subscriber list.
          //
          //    Subscriptions being off is a configuration fact, known from the
          //    already-loaded page, so it renders regardless of the roster read.
          //    Everything below it depends on the roster, and loading is not
          //    emptiness: a page with subscribers used to open on "No
          //    subscribers yet" (and a Total of 0) until the fetch landed.
          if (!page.subscriptionsEnabled)
            _buildEmptyState(page)
          else if (!rosterResolved)
            _buildSkeleton()
          else if (!hasSubscribers)
            _buildEmptyState(page)
          else
            _buildSubscriberBody(page),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // KPI row
  // ---------------------------------------------------------------------------

  /// Builds the two-card KPI row: total subscriber count and the page's
  /// subscriptions on/off state.
  ///
  /// [rosterResolved] gates the total: an unanswered roster has no count, and
  /// rendering the pre-fetch `0` stated as fact that nobody had subscribed. The
  /// no-data placeholder is the same one the monitors KPI row uses for a metric
  /// it cannot measure yet.
  Widget _buildKpiRow(StatusPage page, {required bool rosterResolved}) {
    return WDiv(
      className: 'grid grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.status.subscribers_total_label'),
          value: rosterResolved
              ? '${controller.subscribersFor(page.id).length}'
              : '—',
        ),
        KpiStatCard(
          label: trans('uptizm.status.subscribers_subscriptions_label'),
          value: page.subscriptionsEnabled
              ? trans('uptizm.status.subscribers_subscriptions_on')
              : trans('uptizm.status.subscribers_subscriptions_off'),
          hint: trans('uptizm.status.subscribers_subscriptions_hint'),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // First-load skeleton
  // ---------------------------------------------------------------------------

  /// Builds the first-load placeholder: the subscriber body's own shape, in
  /// skeletons.
  ///
  /// Mirrors [_buildSubscriberBody] at the same `gap-4` rhythm: the search field
  /// above a full-bleed [MSCard] of rows, three deep, so the layout does not
  /// jump when the roster lands. The leading block approximates the search
  /// [MSInput]'s height, whose exact value is theme-resolved padding.
  Widget _buildSkeleton() {
    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        const MSSkeleton(height: 40),
        MSCard(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (int index = 0; index < 3; index++)
                _buildSkeletonRow(isLast: index == 2),
            ],
          ),
        ),
      ],
    );
  }

  /// One skeleton row, matching [_buildSubscriberRow]'s frame and internal
  /// rhythm: the same `gap-3 px-5 py-3.5` row (with the hairline bottom border
  /// on every entry but the last) around the 36px round envelope tile, the
  /// email + subscribed-at lines, and the trailing Remove slot.
  ///
  /// Every text placeholder carries an explicit height, matching the line box of
  /// the text it stands in for (20px for `text-sm`, 16px for `text-xs`). Without
  /// one an [MSSkeleton] collapses: its `WDiv` has no child to measure, so in a
  /// flex column it lays out 0px tall and the placeholder is invisible.
  Widget _buildSkeletonRow({required bool isLast}) {
    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 border-b '
                'border-color-border',
      children: const [
        MSSkeleton(shape: SkeletonShape.circle, width: 36, height: 36),
        WDiv(
          className: 'flex-1 flex flex-col min-w-0 gap-1',
          children: [
            MSSkeleton(shape: SkeletonShape.text, width: 200, height: 20),
            MSSkeleton(shape: SkeletonShape.text, width: 140, height: 16),
          ],
        ),
        MSSkeleton(width: 72, height: 32),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the dashed-border empty state shown when subscriptions are off
  /// or there are no subscribers yet. The message branches on
  /// [StatusPage.subscriptionsEnabled]; "Open editor" always routes
  /// back to `/status/<id>`.
  Widget _buildEmptyState(StatusPage page) {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
        icon: Icons.mail_outline,
        title: page.subscriptionsEnabled
            ? trans('uptizm.status.subscribers_empty_subs_enabled_title')
            : trans('uptizm.status.subscribers_empty_subs_disabled_title'),
        description: page.subscriptionsEnabled
            ? trans('uptizm.status.subscribers_empty_subs_enabled_description')
            : trans(
                'uptizm.status.subscribers_empty_subs_disabled_description',
              ),
        action: MSButton(
          intent: ButtonIntent.secondary,
          onPressed: () => MagicRoute.to('/status/${page.id}'),
          child: WText(trans('uptizm.status.subscribers_open_editor_button')),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Subscriber list
  // ---------------------------------------------------------------------------

  /// Builds the search input plus the striped [Card] of subscriber rows.
  Widget _buildSubscriberBody(StatusPage page) {
    final List<Subscriber> visible = _visible;

    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        MSInput(
          value: _query,
          onChanged: (value) => setState(() => _query = value),
          placeholder: trans('uptizm.status.subscribers_search_placeholder'),
        ),
        MSCard(
          noPadding: true,
          child: visible.isEmpty
              ? _buildNoMatches()
              : WDiv(
                  className: 'flex flex-col',
                  children: [
                    for (final (int index, Subscriber s) in visible.indexed)
                      _buildSubscriberRow(
                        page,
                        s,
                        isLast: index == visible.length - 1,
                      ),
                  ],
                ),
        ),
      ],
    );
  }

  /// Builds the "no subscribers match the query" placeholder text row.
  Widget _buildNoMatches() {
    return WDiv(
      className: 'px-5 py-8',
      child: WText(
        trans('uptizm.status.subscribers_no_matches_text', {'query': _query}),
        className: 'text-center text-sm text-fg-muted',
      ),
    );
  }

  /// Builds one striped subscriber row: an envelope tile, the email +
  /// "Subscribed {date}" meta, and a ghost Remove [Button].
  ///
  /// The row carries a hairline bottom border on every entry except the
  /// last, mirroring the React `i < visible.length - 1` pattern.
  Widget _buildSubscriberRow(
    StatusPage page,
    Subscriber subscriber, {
    required bool isLast,
  }) {
    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 border-b border-color-border',
      children: [
        WDiv(
          className:
              'grid size-9 shrink-0 place-items-center rounded-full '
              'bg-surface-container-high',
          child: WIcon(
            Icons.mail_outline,
            className: 'text-[16px] text-fg-muted',
          ),
        ),
        WDiv(
          className: 'flex-1 flex flex-col min-w-0',
          children: [
            WText(
              subscriber.email,
              className: 'truncate text-sm font-medium text-fg',
            ),
            WText(
              trans('uptizm.status.subscribers_subscribed_at', {
                'date': subscriber.subscribedAt,
              }),
              className: 'truncate text-xs text-fg-muted',
            ),
          ],
        ),
        MSButton(
          intent: ButtonIntent.ghost,
          size: ButtonSize.sm,
          onPressed: () => _confirmRemove(page, subscriber),
          child: WText(trans('uptizm.status.subscribers_remove_button')),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Remove confirmation
  // ---------------------------------------------------------------------------

  /// Opens the remove [MagicStarterConfirmDialog] imperatively; on confirm,
  /// delegates the roster mutation (plus its success toast) to
  /// [StatusPageController.removeSubscriber].
  Future<void> _confirmRemove(
    StatusPage page,
    Subscriber subscriber,
  ) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.status.subscribers_remove_confirm_title'),
      description: trans(
        'uptizm.status.subscribers_remove_confirm_description',
        {'email': subscriber.email, 'page': page.name ?? ''},
      ),
      confirmLabel: trans('uptizm.status.subscribers_remove_button'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors monitor_detail_view's precedent).
    if (!mounted) return;

    controller.removeSubscriber(page.id, subscriber);
  }

  // ---------------------------------------------------------------------------
  // Not-found
  // ---------------------------------------------------------------------------

  /// Builds the pending state shown while the page roster read that will decide
  /// whether this status page exists is still in flight. Reuses the same
  /// [_buildSkeleton] the subscriber roster shows, so the two waiting states are
  /// visually one thing.
  Widget _buildPending() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('common.loading'),
            backLabel: trans('uptizm.status.subscribers_open_editor_button'),
            backFallback: '/status',
          ),
          _buildSkeleton(),
        ],
      ),
    );
  }

  /// Builds the graceful not-found state shown when
  /// [StatusPageController.configById] returns null AND the roster read has
  /// already answered.
  Widget _buildNotFound() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('uptizm.status.subscribers_title'),
            backLabel: trans('uptizm.status.subscribers_open_editor_button'),
            backFallback: '/status',
          ),
          MSEmptyState(
            title: trans('uptizm.status.subscribers_empty_subs_enabled_title'),
            description: trans(
              'uptizm.status.subscribers_empty_subs_enabled_description',
            ),
          ),
        ],
      ),
    );
  }
}
