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

  /// Inline validation error for the Slack token field, or `null`.
  String? tokenError;

  /// Inline validation error for the webhook / Teams URL field, or `null`.
  String? urlError;

  /// Inline validation error for the PagerDuty routing key field, or `null`.
  String? routingKeyError;
}

class _NotificationChannelsViewState extends State<NotificationChannelsView> {
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
    return MSPageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 1. Page header.
          MSPageHeader(
            title: trans('uptizm.teams.channels_title'),
            subtitle: trans('uptizm.teams.channels_description'),
          ),
          const SizedBox(height: 24),

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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!controller.pushProvisioned) ...[
          _buildPushHint(),
          const SizedBox(height: 16),
        ],
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

    return MSSwitch(
      value: record.isEnabled,
      onChanged: (bool value) => _setEnabled(record, value),
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
  void _setEnabled(NotificationChannelRecord record, bool value) {
    NotificationChannelController.instance.update(record.id, {
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
  List<Widget> _buildTypeFields(ChannelType type, _ChannelDraft draft) {
    return switch (type) {
      ChannelType.slack => [
        MSFormField(
          label: trans('uptizm.teams.channels_slack_token_label'),
          error: draft.tokenError,
          child: MSInput(
            value: draft.token,
            onChanged: (String value) => setState(() {
              draft.token = value;
              draft.tokenError = null;
            }),
            type: InputType.password,
            // The Slack token prefix itself, identical in every locale.
            placeholder: trans('uptizm.teams.channels_slack_token_placeholder'),
          ),
        ),
        MSFormField(
          label: trans('uptizm.teams.channels_slack_channel_label'),
          child: MSInput(
            value: draft.channel,
            onChanged: (String value) => setState(() => draft.channel = value),
            placeholder: trans('uptizm.teams.channels_slack_channel_placeholder'),
          ),
        ),
      ],
      ChannelType.webhook => [
        MSFormField(
          label: trans('uptizm.teams.channels_webhook_url_label'),
          error: draft.urlError,
          child: MSInput(
            value: draft.url,
            onChanged: (String value) => setState(() {
              draft.url = value;
              draft.urlError = null;
            }),
            // A bare URL scheme, identical in every locale.
            placeholder: trans('uptizm.teams.channels_webhook_url_placeholder'),
          ),
        ),
        MSFormField(
          label: trans('uptizm.teams.channels_webhook_secret_label'),
          hint: trans('uptizm.teams.channels_webhook_secret_hint'),
          child: MSInput(
            value: draft.secret,
            onChanged: (String value) => setState(() => draft.secret = value),
            type: InputType.password,
          ),
        ),
      ],
      ChannelType.pagerduty => [
        MSFormField(
          label: trans('uptizm.teams.channels_pagerduty_routing_key_label'),
          error: draft.routingKeyError,
          child: MSInput(
            value: draft.routingKey,
            onChanged: (String value) => setState(() {
              draft.routingKey = value;
              draft.routingKeyError = null;
            }),
            type: InputType.password,
          ),
        ),
      ],
      ChannelType.teams => [
        MSFormField(
          label: trans('uptizm.teams.channels_teams_webhook_label'),
          hint: trans('uptizm.teams.channels_teams_webhook_hint'),
          error: draft.urlError,
          child: MSInput(
            value: draft.url,
            onChanged: (String value) => setState(() {
              draft.url = value;
              draft.urlError = null;
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
            NotificationChannelController.instance.update(record.id, {
              'severity': value,
            });
          }
        },
      ),
    );
  }

  /// Builds the Save + Send-test action row. Send-test only renders once the
  /// channel exists ([record] non-null; there is nothing to test before the
  /// first connect).
  Widget _buildActions(ChannelType type, NotificationChannelRecord? record) {
    return WDiv(
      className: 'flex flex-row flex-wrap gap-2',
      children: [
        MSButton(
          size: ButtonSize.sm,
          onPressed: () => _save(type, record),
          child: WText(trans('uptizm.teams.channels_save_button')),
        ),
        if (record != null)
          MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: () => _sendTest(record),
            child: WText(trans('uptizm.teams.channels_test_button')),
          ),
      ],
    );
  }

  /// Validates the client-side required field for [type] (a fresh Slack
  /// token / webhook URL / PagerDuty routing key / Teams URL is required only
  /// when connecting for the first time; an already-connected channel may
  /// resave with the credential fields left blank), then creates or updates
  /// the channel through [NotificationChannelController]. A server 422 maps
  /// its `credentials.token`/`credentials.url`/`credentials.routing_key` key
  /// back onto the matching inline error slot.
  Future<void> _save(ChannelType type, NotificationChannelRecord? record) async {
    final _ChannelDraft draft = _drafts[type]!;
    if (!_validate(type, draft, isNew: record == null)) return;

    final NotificationChannelController controller =
        NotificationChannelController.instance;
    final Map<String, dynamic> fields = _buildFields(type, record, draft);

    final Map<String, String> errors = record == null
        ? await controller.create(fields)
        : await controller.update(record.id, fields);

    if (!mounted || errors.isEmpty) return;

    setState(() {
      draft.tokenError = errors['credentials.token'];
      draft.urlError = errors['credentials.url'];
      draft.routingKeyError = errors['credentials.routing_key'];
    });
  }

  /// Runs the client-side required check for [type]'s credential field,
  /// painting its inline error slot, and returns whether the form may be
  /// submitted. Only enforced when [isNew] (connecting for the first time);
  /// an already-connected channel may resave with blank credential fields
  /// (the stored credential stays untouched). One arm per type so a new
  /// channel shape is a compile error, never a silently skipped check.
  bool _validate(ChannelType type, _ChannelDraft draft, {required bool isNew}) {
    if (!isNew) return true;

    return switch (type) {
      ChannelType.slack => _requireCredential(
        draft.token,
        trans('uptizm.teams.channels_slack_token_label'),
        (String? error) => setState(() => draft.tokenError = error),
      ),
      ChannelType.webhook => _requireCredential(
        draft.url,
        trans('uptizm.teams.channels_webhook_url_label'),
        (String? error) => setState(() => draft.urlError = error),
      ),
      ChannelType.pagerduty => _requireCredential(
        draft.routingKey,
        trans('uptizm.teams.channels_pagerduty_routing_key_label'),
        (String? error) => setState(() => draft.routingKeyError = error),
      ),
      ChannelType.teams => _requireCredential(
        draft.url,
        trans('uptizm.teams.channels_teams_webhook_label'),
        (String? error) => setState(() => draft.urlError = error),
      ),
    };
  }

  /// Runs the client-side required check on [value], painting [paintError]
  /// with a localized "required" message (or `null` when present) and
  /// returning whether the field is filled. [attribute] is the human field
  /// name interpolated into the `validation.required` copy.
  bool _requireCredential(
    String value,
    String attribute,
    void Function(String?) paintError,
  ) {
    final String? error = value.trim().isEmpty
        ? trans('validation.required', {'attribute': attribute})
        : null;
    paintError(error);
    return error == null;
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
}
