import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/notification_channel_controller.dart';
import '../../../app/enums/channel_type.dart' show ChannelType;

/// **The team notification channels screen (`/teams/notifications`).**
///
/// The team-level integrations a team's monitoring and incident alerts route
/// to: Slack, a generic webhook, PagerDuty, and Microsoft Teams (email/push are
/// per-user preferences at `/settings/notifications`; SMS is an opt-in per-user
/// preference, see `docs/uptizm-system/`). One [MSCard] holds a row per
/// [ChannelType]: a channel icon tile, its name (with a severity summary
/// [MSBadge] once connected), the masked detail line, and a trailing [MSSwitch]
/// once connected or a "Connect" [MSButton] otherwise.
///
/// Live-wired against S9's `api/v1/notification-channels/*` endpoints through
/// [NotificationChannelController]: the widget wraps the body in a
/// [ListenableBuilder] on the controller singleton, so a create/update/delete
/// write's internal reload rebuilds the roster directly, with no local mirror
/// state. The push-not-provisioned heads-up above the card reads the same
/// controller's [NotificationChannelController.pushProvisioned], hydrated by
/// the very index request that loads the roster; this view issues no HTTP call
/// of its own. Tapping a row expands an inline config form (type-conditional
/// credential fields, resolved by a switch on [ChannelType]) plus a severity
/// [MSSegmentedControl] and Save/Send-test actions; enabling/disabling and
/// changing severity on an already-connected channel fire immediately
/// (`PUT .../:id`, no credentials in the payload, so the stored secret is
/// never touched by that write). Because the backend never returns a raw
/// token/url/secret (only masked presence booleans + non-secret hints), the
/// credential inputs always start blank: leaving them blank on Save keeps the
/// existing stored credential, typing a fresh value replaces it.
///
/// **Two exceptions to the app's usual client-validation shape, both
/// forced.** First, `credentials.token`/`.url`/`.secret`/`.routing_key` are
/// each required only for ONE [ChannelType] on the backend
/// (`required_if:channel_type,...`, which magic has no equivalent of), so
/// [_NotificationChannelsViewState._rulesFor] is a per-type SWITCH rather
/// than a flat rule map — kept here, in the caller, rather than moved onto
/// [NotificationChannelController]: a new [ChannelType] with no arm is a
/// compile error, never a silently-unvalidated channel. Second, this screen
/// holds one [_ChannelDraft] PER type expanded at once and `credentials.url`
/// is the wire key for BOTH the webhook and Teams card, so every validation
/// key — client or server — is namespaced `<type>.<wire_key>` (e.g.
/// `teams.credentials.url`) at the point it is written, and each
/// [MSFormField] below reads only its own type's prefix.
///
/// ### Example
/// ```dart
/// MagicRoute.page(
///   '/teams/notifications',
///   () => const NotificationChannelsView(),
/// );
/// ```
@immutable
class NotificationChannelsView extends StatefulWidget {
  /// Creates the [NotificationChannelsView].
  const NotificationChannelsView({super.key});

  @override
  State<NotificationChannelsView> createState() =>
      _NotificationChannelsViewState();
}

/// Ephemeral, per-type local UI state: the inline form's typed (never
/// persisted-back) credential fields, its expansion, and the pre-connect
/// severity pick.
///
/// Once a channel exists, severity/enabled read from the controller's cached
/// [NotificationChannelRecord] (the source of truth); [severity] here only
/// backs the segmented control BEFORE the first successful connect, when
/// there is no record yet to read from.
class _ChannelDraft {
  /// Whether the inline config form is expanded.
  bool expanded = false;

  /// Typed Slack bot token (never pre-filled; the backend masks it).
  String token = '';

  /// Typed Slack channel name (optional).
  String channel = '';

  /// Typed webhook / Microsoft Teams endpoint URL (never pre-filled; the
  /// backend masks it). Reused across the webhook and Teams drafts, which each
  /// hold their own [_ChannelDraft] instance.
  String url = '';

  /// Typed webhook signing secret (optional).
  String secret = '';

  /// Typed PagerDuty routing key (never pre-filled; the backend masks it).
  String routingKey = '';

  /// Severity pick before the first connect. `'all'` or `'critical'`.
  String severity = 'all';
}

class _NotificationChannelsViewState extends State<NotificationChannelsView> {
  /// Channel types whose save is currently in flight.
  ///
  /// A set rather than one bool, because this screen hosts four independent
  /// forms and freezing all of them while one saves would be a worse answer
  /// than the double-submit it guards against.
  final Set<ChannelType> _saving = <ChannelType>{};

  /// The channel types this screen configures, in display order.
  static const List<ChannelType> _types = [
    ChannelType.slack,
    ChannelType.webhook,
    ChannelType.pagerduty,
    ChannelType.teams,
  ];

  /// The two severity options, in [MSSegmentedControl] display order. Index 0
  /// is `'all'`, index 1 is `'critical'`.
  static const List<String> _severityValues = ['all', 'critical'];

  /// Per-type local draft state, seeded once in [initState].
  final Map<ChannelType, _ChannelDraft> _drafts = {
    for (final ChannelType type in _types) type: _ChannelDraft(),
  };

  @override
  void initState() {
    super.initState();
    // Fires the controller's single index fetch, which hydrates BOTH the
    // roster and the push-provisioning flag this screen renders. The
    // controller is never a MagicView's backing controller (this is a plain
    // StatefulWidget consulting it through a ListenableBuilder), so magic's
    // `onInit` hook never runs for it and the load is triggered here instead
    // (the precedent `MonitorMetricsController` sets in
    // `monitor_metrics_tab.dart`). Not awaited: the first build renders the
    // last-known-good cache and the ListenableBuilder picks up the response.
    NotificationChannelController.instance.reload();
  }

  /// Resolves the leading icon for [type].
  IconData _iconFor(ChannelType type) => switch (type) {
    ChannelType.slack => Icons.tag,
    ChannelType.webhook => Icons.webhook,
    ChannelType.pagerduty => Icons.crisis_alert,
    ChannelType.teams => Icons.groups,
  };

  /// Resolves the localized description line for [type].
  String _descriptionFor(ChannelType type) => switch (type) {
    ChannelType.slack => trans('uptizm.teams.channels_slack_desc'),
    ChannelType.webhook => trans('uptizm.teams.channels_webhook_desc'),
    ChannelType.pagerduty => trans('uptizm.teams.channels_pagerduty_desc'),
    ChannelType.teams => trans('uptizm.teams.channels_teams_desc'),
  };

  /// Resolves the localized severity summary label for [severity].
  String _severityLabel(String severity) => severity == 'critical'
      ? trans('uptizm.teams.channels_severity_critical')
      : trans('uptizm.teams.channels_severity_all');

  @override
  Widget build(BuildContext context) {
    // Section rhythm is `gap-6` (24px), the DESIGN.md `lg` step, rather than a
    // hand-carried `SizedBox`.
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // 1. Page header.
          MSPageHeader(
            title: trans('uptizm.teams.channels_title'),
            subtitle: trans('uptizm.teams.channels_description'),
          ),

          // 2. The push heads-up and the channels card both read controller
          // state hydrated by one index response, so a single
          // ListenableBuilder rebuilds them together.
          ListenableBuilder(
            listenable: NotificationChannelController.instance,
            builder: (context, _) => _buildBody(),
          ),
        ],
      ),
    );
  }

  /// Builds the controller-backed body: the push-not-provisioned heads-up
  /// above the channels card.
  ///
  /// The heads-up renders only while the backend reports no OneSignal
  /// `app_id` ([NotificationChannelController.pushProvisioned] `false`), so a
  /// team lead knows the per-user push channel cannot deliver yet. Push stays
  /// a per-user preference at `/settings/notifications`; this team-level
  /// screen only surfaces the heads-up, never a toggle.
  ///
  /// While the first roster read is in flight the card is replaced by a
  /// skeleton: every row decides between "Connect" and a live switch purely on
  /// whether the roster holds a record for that type, so a pending read used to
  /// render four Connect buttons and tell a team with Slack already wired that
  /// it had no integrations at all. Loading is not emptiness. The push heads-up
  /// needs no such guard, since [NotificationChannelController.pushProvisioned]
  /// is optimistically `true` until a response actually says otherwise.
  Widget _buildBody() {
    final NotificationChannelController controller =
        NotificationChannelController.instance;

    // `gap-4` (16px) between the hint and the card. A `gap` only applies
    // BETWEEN children, so the absent hint costs no space and the spread that
    // used to carry its own trailing spacer is now a plain conditional child.
    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        if (!controller.pushProvisioned) _buildPushHint(),
        if (controller.isFirstLoad) _buildSkeleton() else _buildChannelsCard(),
      ],
    );
  }

  /// Builds the first-load placeholder: the channels card's own shape, in
  /// skeletons.
  ///
  /// One row per [ChannelType] in [_types] (the row count is fixed and known
  /// before any fetch, so the skeleton is exactly as tall as the real card) with
  /// the same hairline dividers, so nothing shifts when the roster lands.
  Widget _buildSkeleton() {
    return MSCard(
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          for (int index = 0; index < _types.length; index++)
            _buildSkeletonRow(hasDivider: index < _types.length - 1),
        ],
      ),
    );
  }

  /// One skeleton row, matching [_buildRow]'s frame and internal rhythm: the
  /// same `gap-3 px-5 py-4` row around the 36px icon tile, the name/description
  /// column, and the trailing control slot.
  ///
  /// Every text placeholder carries an explicit height, matching the line box of
  /// the text it stands in for (20px for `text-sm`, 16px for `text-xs`). Without
  /// one an [MSSkeleton] collapses: its `WDiv` has no child to measure, so in a
  /// flex column it lays out 0px tall and the placeholder is invisible.
  Widget _buildSkeletonRow({required bool hasDivider}) {
    return WDiv(
      className: hasDivider
          ? 'flex flex-row items-center gap-3 px-5 py-4 border-b '
                'border-color-border'
          : 'flex flex-row items-center gap-3 px-5 py-4',
      children: const [
        MSSkeleton(width: 36, height: 36),
        WDiv(
          className: 'flex flex-col gap-0.5 flex-1 min-w-0',
          children: [
            MSSkeleton(shape: SkeletonShape.text, width: 120, height: 20),
            MSSkeleton(shape: SkeletonShape.text, width: 220, height: 16),
          ],
        ),
        MSSkeleton(width: 80, height: 32),
      ],
    );
  }

  /// Builds the full-bleed card holding one row (plus its inline config) per
  /// [ChannelType] in [_types], with hairline dividers between rows.
  Widget _buildChannelsCard() {
    return MSCard(
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          for (int index = 0; index < _types.length; index++)
            _buildChannel(_types[index], index < _types.length - 1),
        ],
      ),
    );
  }

  /// Builds the subtle info hint shown when OneSignal push is not provisioned
  /// (empty `app_id`). Uses the monitoring `info` status family tokens for a
  /// calm, non-alarming heads-up.
  ///
  /// The icon keeps its intrinsic size (`shrink-0`) while the copy is the only
  /// shrinkable child (`flex-1 min-w-0`), so on a phone the sentence wraps
  /// instead of pushing the row past the viewport. Without the flex pair the
  /// text is measured at its natural single-line width and a locale with
  /// longer copy (or a narrower device) overflows the row.
  Widget _buildPushHint() {
    return WDiv(
      className:
          'flex flex-row items-center gap-2 rounded-lg px-4 py-3 bg-info-soft',
      children: [
        WIcon(
          Icons.info_outline,
          className: 'shrink-0 text-[18px] text-info',
        ),
        WText(
          // Shared with magic_starter's notification-preferences view, which
          // surfaces the same heads-up under its push channel row.
          trans('notifications.channel_push_unconfigured'),
          className: 'flex-1 min-w-0 text-sm text-info-soft-foreground',
        ),
      ],
    );
  }

  /// Builds a single channel: the header row and, when expanded, its inline
  /// config form. [hasDivider] draws a hairline bottom border between rows.
  Widget _buildChannel(ChannelType type, bool hasDivider) {
    final NotificationChannelRecord? record = NotificationChannelController
        .instance
        .channelOfType(type);
    final _ChannelDraft draft = _drafts[type]!;

    return WDiv(
      className: hasDivider
          ? 'flex flex-col border-b border-color-border'
          : 'flex flex-col',
      children: [
        _buildRow(type, record),
        if (draft.expanded) _buildConfig(type, record, draft),
      ],
    );
  }

  /// Builds the tappable channel header row: icon tile + name/detail column +
  /// trailing control (a [MSSwitch] once connected, a "Connect" [MSButton]
  /// when not). Tapping the row toggles the inline config form.
  Widget _buildRow(ChannelType type, NotificationChannelRecord? record) {
    return WAnchor(
      onTap: () => _toggleExpanded(type),
      child: WDiv(
        className: 'flex flex-row items-center gap-3 px-5 py-4',
        children: [
          _buildIconTile(type, record?.isEnabled ?? false),
          _buildDetails(type, record),
          _buildTrailing(type, record),
        ],
      ),
    );
  }

  /// Builds the square icon tile. It reads in the `ai` tint while the channel
  /// is connected and enabled, and in a muted tone otherwise.
  Widget _buildIconTile(ChannelType type, bool enabled) {
    return WDiv(
      className: enabled
          ? 'size-9 shrink-0 rounded-lg flex items-center justify-center '
                'bg-ai-soft'
          : 'size-9 shrink-0 rounded-lg flex items-center justify-center '
                'bg-surface-container-high',
      child: WIcon(
        _iconFor(type),
        className: enabled
            ? 'text-[18px] text-ai'
            : 'text-[18px] text-fg-muted',
      ),
    );
  }

  /// Builds the flexible name + detail column. Shows a severity summary
  /// [MSBadge] next to the name and the masked detail line only once
  /// connected ([record] non-null).
  Widget _buildDetails(ChannelType type, NotificationChannelRecord? record) {
    return WDiv(
      className: 'flex flex-col gap-0.5 flex-1 min-w-0',
      children: [
        WDiv(
          className: 'flex flex-row flex-wrap items-center gap-2',
          children: [
            WText(
              type.label,
              className: 'text-sm font-medium text-fg',
            ),
            if (record != null) MSBadge(_severityLabel(record.severity)),
          ],
        ),
        WText(
          _descriptionFor(type),
          className: 'text-xs text-fg-muted',
        ),
        if (record != null && (record.detail ?? '').isNotEmpty)
          WText(
            record.detail!,
            className: 'truncate font-mono text-xs text-fg-muted',
          ),
      ],
    );
  }

  /// Builds the trailing control: a [MSSwitch] once connected, or a "Connect"
  /// [MSButton] while the integration is not yet set up.
  Widget _buildTrailing(ChannelType type, NotificationChannelRecord? record) {
    if (record == null) {
      return MSButton(
        intent: ButtonIntent.secondary,
        size: ButtonSize.sm,
        onPressed: () => _connect(type),
        child: WText(trans('uptizm.teams.channels_connect_button')),
      );
    }

    // The switch carries no visible label of its own: the channel name sits in
    // a sibling column, so without a semantic label a screen reader announced a
    // bare "switch" with nothing saying which integration it turns off, and an
    // E2E driver had no handle to resolve it by. The channel name is what the
    // sighted reader pairs it with, so it is what the assistive reader hears.
    return MSSwitch(
      value: record.isEnabled,
      onChanged: (bool value) => _setEnabled(record, value),
      semanticLabel: type.label,
    );
  }

  /// Reveals the inline config form for [type] (local UI state only; the
  /// channel is actually created on Save).
  void _connect(ChannelType type) {
    setState(() => _drafts[type]!.expanded = true);
  }

  /// Toggles the inline config form for [type].
  void _toggleExpanded(ChannelType type) {
    setState(() {
      final _ChannelDraft draft = _drafts[type]!;
      draft.expanded = !draft.expanded;
    });
  }

  /// Flips [record]'s enabled state via `PUT .../:id` (no credentials in the
  /// payload, so the stored credential is untouched). Fire-and-forget: the
  /// controller's own reload rebuilds this view through the [ListenableBuilder].
  ///
  /// Passes [record.type] explicitly: this payload carries no `channel_type`
  /// key, and the controller no longer derives the type from the payload (see
  /// [NotificationChannelController.update]'s docblock).
  void _setEnabled(NotificationChannelRecord record, bool value) {
    NotificationChannelController.instance.update(record.id, record.type, {
      'is_enabled': value,
    });
  }

  /// Builds the inline config form: the type-conditional credential fields,
  /// the severity [MSSegmentedControl], and the Save + Send-test actions.
  Widget _buildConfig(
    ChannelType type,
    NotificationChannelRecord? record,
    _ChannelDraft draft,
  ) {
    return WDiv(
      className:
          'flex flex-col gap-4 border-t border-color-border px-5 py-4',
      children: [
        ..._buildTypeFields(type, draft),
        _buildSeverityField(type, record, draft),
        _buildActions(type, record),
      ],
    );
  }

  /// Resolves the type-conditional credential fields for [type], one arm per
  /// channel shape (Slack: bot token + channel; webhook: URL + secret;
  /// PagerDuty: routing key; Teams: Workflows webhook URL).
  ///
  /// Every `error:` reads [NotificationChannelController.getError] under this
  /// type's namespaced key (`<type>.credentials.<field>`), never a local
  /// `_ChannelDraft` field: both the controller's client-side `validate()`
  /// (required/length) and a server 422 publish into the SAME namespaced
  /// [NotificationChannelController.validationErrors], so one read serves
  /// both. Each `onChanged` clears only that field's own namespaced key via
  /// [NotificationChannelController.clearFieldError].
  List<Widget> _buildTypeFields(ChannelType type, _ChannelDraft draft) {
    final NotificationChannelController controller =
        NotificationChannelController.instance;
    final String prefix = type.name;

    return switch (type) {
      ChannelType.slack => [
        MSFormField(
          label: trans('uptizm.teams.channels_slack_token_label'),
          error: controller.getError('$prefix.credentials.token'),
          child: MSInput(
            value: draft.token,
            onChanged: (String value) => setState(() {
              draft.token = value;
              controller.clearFieldError('$prefix.credentials.token');
            }),
            type: InputType.password,
            // The Slack token prefix itself, identical in every locale.
            placeholder: trans('uptizm.teams.channels_slack_token_placeholder'),
          ),
        ),
        MSFormField(
          label: trans('uptizm.teams.channels_slack_channel_label'),
          error: controller.getError('$prefix.credentials.channel'),
          child: MSInput(
            value: draft.channel,
            onChanged: (String value) => setState(() {
              draft.channel = value;
              controller.clearFieldError('$prefix.credentials.channel');
            }),
            placeholder: trans('uptizm.teams.channels_slack_channel_placeholder'),
          ),
        ),
      ],
      ChannelType.webhook => [
        MSFormField(
          label: trans('uptizm.teams.channels_webhook_url_label'),
          error: controller.getError('$prefix.credentials.url'),
          child: MSInput(
            value: draft.url,
            onChanged: (String value) => setState(() {
              draft.url = value;
              controller.clearFieldError('$prefix.credentials.url');
            }),
            // A bare URL scheme, identical in every locale.
            placeholder: trans('uptizm.teams.channels_webhook_url_placeholder'),
          ),
        ),
        MSFormField(
          label: trans('uptizm.teams.channels_webhook_secret_label'),
          hint: trans('uptizm.teams.channels_webhook_secret_hint'),
          error: controller.getError('$prefix.credentials.secret'),
          child: MSInput(
            value: draft.secret,
            onChanged: (String value) => setState(() {
              draft.secret = value;
              controller.clearFieldError('$prefix.credentials.secret');
            }),
            type: InputType.password,
          ),
        ),
      ],
      ChannelType.pagerduty => [
        MSFormField(
          label: trans('uptizm.teams.channels_pagerduty_routing_key_label'),
          error: controller.getError('$prefix.credentials.routing_key'),
          child: MSInput(
            value: draft.routingKey,
            onChanged: (String value) => setState(() {
              draft.routingKey = value;
              controller.clearFieldError('$prefix.credentials.routing_key');
            }),
            type: InputType.password,
          ),
        ),
      ],
      ChannelType.teams => [
        MSFormField(
          label: trans('uptizm.teams.channels_teams_webhook_label'),
          hint: trans('uptizm.teams.channels_teams_webhook_hint'),
          error: controller.getError('$prefix.credentials.url'),
          child: MSInput(
            value: draft.url,
            onChanged: (String value) => setState(() {
              draft.url = value;
              controller.clearFieldError('$prefix.credentials.url');
            }),
            placeholder: trans('uptizm.teams.channels_teams_webhook_placeholder'),
          ),
        ),
      ],
    };
  }

  /// Builds the severity delivery field: a [MSSegmentedControl] over the
  /// All / Critical options. Once connected ([record] non-null), a change
  /// fires immediately (`PUT .../:id`, no credentials in the payload);
  /// before the first connect, it only updates [draft.severity], sent on the
  /// next Save.
  Widget _buildSeverityField(
    ChannelType type,
    NotificationChannelRecord? record,
    _ChannelDraft draft,
  ) {
    final String severity = record?.severity ?? draft.severity;

    return MSFormField(
      label: trans('uptizm.teams.channels_severity_label'),
      hint: trans('uptizm.teams.channels_severity_hint'),
      child: MSSegmentedControl(
        size: SegmentedControlSize.sm,
        // The two option labels are the row's only content and no segment may
        // shrink, so on a phone (or in a locale with longer copy) the pair
        // cannot always fit one line. `wrap` lets the second segment fall to a
        // second run instead of overflowing; it never triggers while the pair
        // fits, so the desktop rendering is unchanged.
        classNames: const {'root': 'wrap'},
        options: [
          trans('uptizm.teams.channels_severity_all'),
          trans('uptizm.teams.channels_severity_critical'),
        ],
        selectedIndex: _severityValues.indexOf(severity),
        onChanged: (int index) {
          final String value = _severityValues[index];
          setState(() => draft.severity = value);
          if (record != null) {
            // No `channel_type` in this payload either; [record.type] carries
            // it explicitly for the same reason as [_setEnabled].
            NotificationChannelController.instance.update(
              record.id,
              record.type,
              {'severity': value},
            );
          }
        },
      ),
    );
  }

  /// Builds the Save + Send-test + Delete action row. Send-test and Delete
  /// only render once the channel exists ([record] non-null; there is
  /// nothing to test or remove before the first connect).
  Widget _buildActions(ChannelType type, NotificationChannelRecord? record) {
    return WDiv(
      className: 'flex flex-row flex-wrap gap-2',
      children: [
        MSButton(
          size: ButtonSize.sm,
          // Disabled while this channel's own write is in flight, so the guard
          // in `_save` is visible rather than only defensive.
          onPressed: _saving.contains(type)
              ? null
              : () => _save(type, record),
          child: WText(trans('uptizm.teams.channels_save_button')),
        ),
        if (record != null)
          MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: () => _sendTest(record),
            child: WText(trans('uptizm.teams.channels_test_button')),
          ),
        if (record != null)
          MSButton(
            intent: ButtonIntent.ghost,
            size: ButtonSize.sm,
            onPressed: () => _confirmDelete(type, record),
            child: WText(trans('uptizm.teams.channels_delete_button')),
          ),
      ],
    );
  }

  /// Validates [type]'s credential fields client-side, then creates or
  /// updates the channel through [NotificationChannelController].
  ///
  /// Two exceptions this vertical carries, both forced (see the class
  /// docblock):
  ///
  ///  1. [_rulesFor] is the exhaustive `ChannelType` switch the plan for this
  ///     step keeps: the backend keys every credential rule
  ///     `required_if:channel_type,...`, which magic has no equivalent of, so
  ///     a missing arm here is a compile error rather than a silently
  ///     unvalidated channel. `validate()` runs BEFORE any request and, on
  ///     failure, publishes into [NotificationChannelController.validationErrors]
  ///     under this type's namespaced keys — which is what every
  ///     [MSFormField] above already reads via `getError` — so this method
  ///     returns without building a payload at all.
  ///  2. [_isValidWebhookUrl] is the one check no magic [Rule] can express
  ///     (magic ships no `Url` rule, and an [In] approximation would refuse
  ///     every valid value): it runs AFTER `validate()` passes (so a blank
  ///     url is still `Required`'s job) and publishes through
  ///     [NotificationChannelController.setFieldError] under the same
  ///     namespaced key the field reads.
  ///
  /// A server 422 the write action publishes onto
  /// [NotificationChannelController.validationErrors] also lands in
  /// `getError` via the same namespaced key; what remains after that (a
  /// field this form has no slot for) surfaces as a generic toast, mirroring
  /// the fallback in `status_page_editor_view`, `incident_create_view` and
  /// `escalation_policy_editor_view`. Read only when the write answers
  /// `false`: a `true` means it was written and there is nothing left to
  /// surface.
  Future<void> _save(ChannelType type, NotificationChannelRecord? record) async {
    // Per type, not one flag for the screen: this page hosts four independent
    // channel forms and a single `isSubmitting` would freeze the other three
    // while one saves. That is why `SubmitsOnce` does not fit here.
    //
    // The guard is not cosmetic. `POST /notification-channels` creates
    // unconditionally and the table carries only an index, no unique
    // constraint, on (team_id, channel_type), so a double tap on Connect gave
    // the team two Slack channels: every incident then paged Slack twice, and
    // `channelOfType` returns only the first, so the duplicate was invisible in
    // the UI and could not be deleted from it.
    if (_saving.contains(type)) return;
    setState(() => _saving.add(type));

    try {
      await _saveInner(type, record);
    } finally {
      // Released on every exit, including the two early returns the validation
      // legs take, or a refused save would lock its own Connect button.
      if (mounted) setState(() => _saving.remove(type));
    }
  }

  /// The body of [_save], separated so the in-flight guard above can release on
  /// every path without threading a flag through five early returns.
  Future<void> _saveInner(
    ChannelType type,
    NotificationChannelRecord? record,
  ) async {
    final _ChannelDraft draft = _drafts[type]!;
    final NotificationChannelController controller =
        NotificationChannelController.instance;
    final String prefix = type.name;

    try {
      controller.validate(
        _validationData(type, draft),
        _rulesFor(type, isNew: record == null),
      );
    } on ValidationException {
      return;
    }

    if (_hasUrlField(type)) {
      final String url = draft.url.trim();
      if (url.isNotEmpty && !_isValidWebhookUrl(url)) {
        controller.setFieldError(
          '$prefix.credentials.url',
          trans('uptizm.teams.channels_url_invalid'),
        );
        return;
      }
    }

    final Map<String, dynamic> fields = _buildFields(type, record, draft);
    final bool ok = record == null
        ? await controller.create(fields)
        : await controller.update(record.id, type, fields);

    if (!mounted || ok) return;

    final Set<String> owned = _rulesFor(type, isNew: true).keys.toSet();
    final Map<String, String> unmapped = <String, String>{
      for (final MapEntry<String, String> entry
          in controller.validationErrors.entries)
        if (!owned.contains(entry.key)) entry.key: entry.value,
    };

    if (unmapped.isNotEmpty) {
      // `common.error_occurred` rather than a channels-specific title, matching
      // NotificationChannelController._toastError, whose docblock notes the
      // channels namespace carries no dedicated error strings.
      Magic.error(trans('common.error_occurred'), unmapped.values.first);
    }
  }

  /// The client-side mirror of `StoreNotificationChannelRequest::rules()`'s
  /// per-type credential shape (`POST /notification-channels`), one arm per
  /// [ChannelType] because the backend keys every credential rule
  /// `required_if:channel_type,...`
  /// (`backend/app/Http/Requests/StoreNotificationChannelRequest.php:63,74,81,87`)
  /// and magic has no `required_if` at all: a missing arm here is a compile
  /// error, never a silently-unvalidated new channel type.
  ///
  /// Keys are namespaced `<type>.credentials.<field>` (matching
  /// [_validationData] and [NotificationChannelController]'s own
  /// `_namespace`) rather than the bare wire key, because this screen holds
  /// one [_ChannelDraft] PER type open at once and `credentials.url` is the
  /// wire key for both the webhook and Teams card; an unnamespaced key could
  /// not say which card's error a failure was.
  ///
  /// [Required] applies only when [isNew] (connecting for the first time):
  /// `UpdateNotificationChannelRequest` carries no `required_if` on any
  /// credential field at all, so an already-connected channel may resave
  /// with every credential field left blank (the stored credential stays
  /// untouched). [Max] mirrors the backend's exact bound unconditionally,
  /// since it passes on `null` and an absent field costs nothing to check.
  ///
  /// Deliberately absent, and why: the SSRF host-guard on `credentials.url`
  /// (`HostGuard::resolveAndAssertAllowed`, an async DNS resolution no
  /// synchronous client [Rule] can approximate) and the URL-SHAPE check
  /// [_isValidWebhookUrl] runs instead, since magic ships no `Url` rule and
  /// an [In] approximation would refuse every valid value.
  ///
  /// A fresh map per call, not a shared constant: [Max] remembers the value
  /// type it last measured and `message()` reads it back, so one shared
  /// instance would let one submit's type pick another's message (see
  /// `MonitorController._createRules`'s docblock for the same reasoning).
  Map<String, List<Rule>> _rulesFor(ChannelType type, {required bool isNew}) {
    final String prefix = type.name;

    return switch (type) {
      ChannelType.slack => <String, List<Rule>>{
        '$prefix.credentials.token': [
          if (isNew) Required(),
          Max(255),
        ],
        '$prefix.credentials.channel': [Max(200)],
      },
      ChannelType.webhook => <String, List<Rule>>{
        '$prefix.credentials.url': [
          if (isNew) Required(),
          Max(2048),
        ],
        '$prefix.credentials.secret': [
          if (isNew) Required(),
          Max(255),
        ],
      },
      ChannelType.pagerduty => <String, List<Rule>>{
        '$prefix.credentials.routing_key': [
          if (isNew) Required(),
          Max(64),
        ],
      },
      ChannelType.teams => <String, List<Rule>>{
        '$prefix.credentials.url': [
          if (isNew) Required(),
          Max(2048),
        ],
      },
    };
  }

  /// Builds the flat, namespaced data map [_rulesFor] validates against, read
  /// straight off [draft] rather than the assembled wire `fields`.
  ///
  /// `Validator` looks up each rule's key literally in the data map — it
  /// never walks a nested map (see `magic`'s `Validator._runValidation`) —
  /// and only the keys [_rulesFor] actually files a rule under are ever
  /// checked, so including every credential field here unconditionally
  /// (rather than switching on [type] a second time) is safe: an
  /// irrelevant key for this type is simply never read.
  Map<String, dynamic> _validationData(ChannelType type, _ChannelDraft draft) {
    final String prefix = type.name;

    return <String, dynamic>{
      '$prefix.credentials.token': draft.token.trim(),
      '$prefix.credentials.channel': draft.channel.trim(),
      '$prefix.credentials.url': draft.url.trim(),
      '$prefix.credentials.secret': draft.secret.trim(),
      '$prefix.credentials.routing_key': draft.routingKey.trim(),
    };
  }

  /// Whether [type]'s config form carries a `credentials.url` field
  /// (webhook and Teams both reuse [_ChannelDraft.url]).
  bool _hasUrlField(ChannelType type) =>
      type == ChannelType.webhook || type == ChannelType.teams;

  /// Whether [value] is a well-formed `https://` URL with a non-empty host.
  ///
  /// Mirrors the one STATELESS half of the backend's SSRF `HostGuard` check
  /// on `credentials.url`
  /// (`StoreNotificationChannelRequest::webhookUrlRule`): a non-`https`
  /// scheme is rejected outright, no network resolution required. The
  /// host-resolution half (the private/loopback/metadata denylist) stays
  /// server-only; approximating IT client-side would need a DNS lookup this
  /// form cannot make. Not a magic [Rule] because magic ships no `Url` rule
  /// and an [In] approximation would refuse every valid value.
  bool _isValidWebhookUrl(String value) {
    final Uri? uri = Uri.tryParse(value);
    return uri != null && uri.scheme == 'https' && uri.host.isNotEmpty;
  }

  /// Assembles the create/update field map for [type] from [draft], omitting
  /// `credentials` entirely when the user left every credential field blank
  /// (so an enabled/severity-only resave never clobbers the stored secret).
  Map<String, dynamic> _buildFields(
    ChannelType type,
    NotificationChannelRecord? record,
    _ChannelDraft draft,
  ) {
    final Map<String, dynamic> fields = {
      'name': type.label,
      'channel_type': type.name,
      'is_enabled': record?.isEnabled ?? true,
      'severity': record?.severity ?? draft.severity,
    };

    switch (type) {
      case ChannelType.slack:
        if (draft.token.trim().isNotEmpty) {
          fields['credentials'] = {
            'token': draft.token.trim(),
            if (draft.channel.trim().isNotEmpty)
              'channel': draft.channel.trim(),
          };
        }
      case ChannelType.webhook:
        if (draft.url.trim().isNotEmpty) {
          fields['credentials'] = {
            'url': draft.url.trim(),
            if (draft.secret.trim().isNotEmpty) 'secret': draft.secret.trim(),
          };
        }
      case ChannelType.pagerduty:
        if (draft.routingKey.trim().isNotEmpty) {
          fields['credentials'] = {'routing_key': draft.routingKey.trim()};
        }
      case ChannelType.teams:
        if (draft.url.trim().isNotEmpty) {
          fields['credentials'] = {'url': draft.url.trim()};
        }
    }

    return fields;
  }

  /// Sends a test alert through [record] via
  /// [NotificationChannelController.sendTest]. The controller surfaces its
  /// own honest success/failure toast, so this stays silent beyond firing it.
  Future<void> _sendTest(NotificationChannelRecord record) async {
    await NotificationChannelController.instance.sendTest(record.id);
  }

  // ---------------------------------------------------------------------------
  // Delete confirmation
  // ---------------------------------------------------------------------------

  /// Opens the delete [MagicStarterConfirmDialog]; on confirm, fires
  /// [NotificationChannelController.delete] (`DELETE
  /// /notification-channels/{id}`), which reloads the roster on success and
  /// raises its own toast only on failure. A successful delete is deliberately
  /// silent: the row collapsing back to "Connect" is the confirmation.
  /// Mirrors `escalation_policies_view.dart`'s
  /// `_confirmDelete`, including the `if (!mounted) return;` guard after the
  /// awaited dialog: this view stays mounted on its own route, but the guard
  /// costs nothing and matches every other delete in this codebase.
  Future<void> _confirmDelete(
    ChannelType type,
    NotificationChannelRecord record,
  ) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.channels_delete_confirm_title', {
        'name': type.label,
      }),
      description: trans('uptizm.teams.channels_delete_confirm_description'),
      confirmLabel: trans('uptizm.teams.channels_delete_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    if (!mounted) return;

    await NotificationChannelController.instance.delete(record.id);
  }
}
