import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../app/mocks/status_pages.dart';
import '../../ui/components/empty_state/index.dart';
import '../../ui/components/kpi_stat_card/index.dart';
import '../../ui/layouts/page_container.dart';

/// **The Status Page Subscribers screen.**
///
/// A faithful Flutter port of the React `StatusPageSubscribersPage`: the
/// subscriber roster for a single status page. It resolves a page [id] to a
/// fixture via [findStatusPage] and renders, in the React section order:
///
/// 1. A "← {page.name}" breadcrumb back to the status-page editor, built with
///    [PageHeader.backLabel] / [PageHeader.backFallback] (the app's unified
///    back-affordance, same as [IncidentDetailView]).
/// 2. **Header** — title "Subscribers", a subtitle naming the page, and an
///    "Export CSV" action disabled when there are zero subscribers.
/// 3. **KPI row** — total subscriber count and the page's subscriptions
///    on/off state.
/// 4. **Body** — when subscriptions are off or there are no subscribers, a
///    dashed-border [EmptyState] (message branches on
///    [StatusPageConfig.subscriptionsEnabled]) with an "Open editor" action
///    back to `/status/<id>`. Otherwise a search [Input] over a striped
///    [Card] of subscriber rows, each with a Remove [Button] that opens a
///    [MagicStarterConfirmDialog] before mutating local state.
///
/// When [findStatusPage] returns `null` it renders a graceful not-found
/// [EmptyState] rather than crashing on an unknown route id.
///
/// This is a mock screen: [_subscribers] is seeded once from
/// [subscribersFor] into a local mutable list; Remove mutates that list via
/// [setState] plus a `Magic.success` toast. Nothing persists. A plain Flutter
/// [Column] scaffolds the body so each leaf receives a bounded width from
/// [PageContainer]; Wind utilities appear only on leaf containers.
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
class StatusPageSubscribersView extends StatefulWidget {
  /// The status-page identifier resolved against the fixtures via
  /// [findStatusPage].
  ///
  /// `null` or an unknown id renders a graceful not-found [EmptyState].
  final String? id;

  /// Creates the [StatusPageSubscribersView] for the given page [id].
  const StatusPageSubscribersView({super.key, this.id});

  @override
  State<StatusPageSubscribersView> createState() =>
      _StatusPageSubscribersViewState();
}

class _StatusPageSubscribersViewState extends State<StatusPageSubscribersView> {
  /// The mutable working copy of the page's subscribers.
  ///
  /// Seeded once in [initState] from [subscribersFor]; the fixture map is
  /// never mutated in place. Remove mutates this list via [setState].
  late List<Subscriber> _subscribers;

  /// The current search query, matched against the subscriber email.
  String _query = '';

  @override
  void initState() {
    super.initState();
    _subscribers = subscribersFor(widget.id).toList();
  }

  /// Subscribers whose email contains the current (trimmed,
  /// case-insensitive) [_query].
  List<Subscriber> get _visible {
    final String trimmed = _query.trim().toLowerCase();
    if (trimmed.isEmpty) return _subscribers;
    return _subscribers
        .where((s) => s.email.toLowerCase().contains(trimmed))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the status page; a null / unknown id falls back to a
    //    graceful not-found state so the screen never crashes on an unknown
    //    route id.
    final StatusPageConfig? page = findStatusPage(widget.id);
    if (page == null) {
      return _buildNotFound();
    }

    // 2. A plain Flutter Column scaffolds the page body so each leaf receives
    //    a bounded width from PageContainer (same discipline as the sibling
    //    views).
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PageHeader(
            title: trans('uptizm.status.subscribers_title'),
            subtitle: 'People subscribed to ${page.name} updates.',
            backLabel: page.name,
            backFallback: '/status/${page.id}',
            actions: [
              Button(
                intent: ButtonIntent.secondary,
                onPressed: _subscribers.isEmpty
                    ? null
                    : () {
                        // Export is a mock action: no CSV is generated, only
                        // the disabled-state contract from the plan applies.
                      },
                child: WText(trans('uptizm.status.subscribers_export_csv')),
              ),
            ],
          ),
          const SizedBox(height: 24),

          // 3. KPI row: total subscribers + subscriptions on/off.
          _buildKpiRow(page),
          const SizedBox(height: 24),

          // 4. Body: empty state, or search + subscriber list.
          if (!page.subscriptionsEnabled || _subscribers.isEmpty)
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
  Widget _buildKpiRow(StatusPageConfig page) {
    return WDiv(
      className: 'grid grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.status.subscribers_total_label'),
          value: '${_subscribers.length}',
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
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the dashed-border empty state shown when subscriptions are off
  /// or there are no subscribers yet. The message branches on
  /// [StatusPageConfig.subscriptionsEnabled]; "Open editor" always routes
  /// back to `/status/<id>`.
  Widget _buildEmptyState(StatusPageConfig page) {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: EmptyState(
        icon: Icons.mail_outline,
        title: page.subscriptionsEnabled
            ? trans('uptizm.status.subscribers_empty_subs_enabled_title')
            : trans('uptizm.status.subscribers_empty_subs_disabled_title'),
        description: page.subscriptionsEnabled
            ? trans('uptizm.status.subscribers_empty_subs_enabled_description')
            : trans(
                'uptizm.status.subscribers_empty_subs_disabled_description',
              ),
        action: Button(
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
  Widget _buildSubscriberBody(StatusPageConfig page) {
    final List<Subscriber> visible = _visible;

    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        Input(
          value: _query,
          onChanged: (value) => setState(() => _query = value),
          placeholder: trans('uptizm.status.subscribers_search_placeholder'),
        ),
        Card(
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
    StatusPageConfig page,
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
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
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
        ),
        Button(
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
  /// removes [subscriber] from the local list and surfaces a success toast.
  Future<void> _confirmRemove(
    StatusPageConfig page,
    Subscriber subscriber,
  ) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.status.subscribers_remove_confirm_title'),
      description: trans(
        'uptizm.status.subscribers_remove_confirm_description',
        {'email': subscriber.email, 'page': page.name},
      ),
      confirmLabel: trans('uptizm.status.subscribers_remove_button'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;

    setState(() => _subscribers.remove(subscriber));
    Magic.success(
      trans('uptizm.status.subscribers_remove_confirm_title'),
      subscriber.email,
    );
  }

  // ---------------------------------------------------------------------------
  // Not-found
  // ---------------------------------------------------------------------------

  /// Builds the graceful not-found state shown when [findStatusPage] returns
  /// null.
  Widget _buildNotFound() {
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PageHeader(
            title: trans('uptizm.status.subscribers_title'),
            backLabel: trans('uptizm.status.subscribers_open_editor_button'),
            backFallback: '/status',
          ),
          const SizedBox(height: 24),
          EmptyState(
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
