import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/notification_channel_controller.dart';
import '../../../app/enums/channel_type.dart' show ChannelType;
import '../../../ui/layouts/page_container.dart';

/// **The team notification channels screen (`/teams/notifications`).**
///
/// The team-level integrations a team's monitoring and incident alerts route
/// to: Slack and a generic webhook (email/push are per-user preferences at
/// `/settings/notifications`; Microsoft Teams and SMS are phase 2, see
/// `docs/uptizm-system/`). One [MSCard] holds a row per [ChannelType] (Slack,
/// webhook): a channel icon tile, its name (with a severity summary [MSBadge]
/// once connected), the masked detail line, and a trailing [MSSwitch] once
/// connected or a "Connect" [MSButton] otherwise.
///
/// Live-wired against S9's `api/v1/notification-channels/*` endpoints through
/// [NotificationChannelController]: the widget wraps the card in a
/// [ListenableBuilder] on the controller singleton, so a create/update/delete
/// write's internal reload rebuilds the roster directly, with no local mirror
/// state. Tapping a row expands an inline config form (type-conditional
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

  /// Typed webhook endpoint URL (never pre-filled; the backend masks it).
  String url = '';

  /// Typed webhook signing secret (optional).
  String secret = '';

  /// Severity pick before the first connect. `'all'` or `'critical'`.
  String severity = 'all';

  /// Inline validation error for the Slack token field, or `null`.
  String? tokenError;

  /// Inline validation error for the webhook URL field, or `null`.
  String? urlError;
}

class _NotificationChannelsViewState extends State<NotificationChannelsView> {
  /// The two channel types this screen configures, in display order.
  static const List<ChannelType> _types = [
    ChannelType.slack,
    ChannelType.webhook,
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
    // Warms the controller's roster cache so the first build already reflects
    // any already-configured channel, without blocking this build.
    NotificationChannelController.instance;
  }

  /// Resolves the leading icon for [type].
  IconData _iconFor(ChannelType type) => switch (type) {
    ChannelType.slack => Icons.tag,
    ChannelType.webhook => Icons.webhook,
  };

  /// Resolves the localized description line for [type].
  String _descriptionFor(ChannelType type) => switch (type) {
    ChannelType.slack => trans('uptizm.teams.channels_slack_desc'),
    ChannelType.webhook => trans('uptizm.teams.channels_webhook_desc'),
  };

  /// Resolves the localized severity summary label for [severity].
  String _severityLabel(String severity) => severity == 'critical'
      ? trans('uptizm.teams.channels_severity_critical')
      : trans('uptizm.teams.channels_severity_all');

  @override
  Widget build(BuildContext context) {
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 1. Page header.
          MSPageHeader(
            title: trans('uptizm.teams.channels_title'),
            subtitle: trans('uptizm.teams.channels_description'),
          ),
          const SizedBox(height: 24),

          // 2. One full-bleed card with a divided row per channel type,
          // rebuilding whenever the controller's roster changes.
          ListenableBuilder(
            listenable: NotificationChannelController.instance,
            builder: (context, _) => _buildChannelsCard(),
          ),
        ],
      ),
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

  /// Builds a single channel: the header row and, when expanded, its inline
  /// config form. [hasDivider] draws a hairline bottom border between rows.
  Widget _buildChannel(ChannelType type, bool hasDivider) {
    final NotificationChannelRecord? record = NotificationChannelController
        .instance
        .channelOfType(type);
    final _ChannelDraft draft = _drafts[type]!;

    return WDiv(
      className: hasDivider
          ? 'flex flex-col border-b border-color-border dark:border-color-border'
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
                'bg-ai-soft dark:bg-ai-soft'
          : 'size-9 shrink-0 rounded-lg flex items-center justify-center '
                'bg-surface-container-high dark:bg-surface-container-high',
      child: WIcon(
        _iconFor(type),
        className: enabled
            ? 'text-[18px] text-ai dark:text-ai'
            : 'text-[18px] text-fg-muted dark:text-fg-muted',
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
              className: 'text-sm font-medium text-fg dark:text-fg',
            ),
            if (record != null) MSBadge(_severityLabel(record.severity)),
          ],
        ),
        WText(
          _descriptionFor(type),
          className: 'text-xs text-fg-muted dark:text-fg-muted',
        ),
        if (record != null && (record.detail ?? '').isNotEmpty)
          WText(
            record.detail!,
            className:
                'truncate font-mono text-xs text-fg-muted dark:text-fg-muted',
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
          'flex flex-col gap-4 border-t border-color-border '
          'dark:border-color-border px-5 py-4',
      children: [
        ..._buildTypeFields(type, draft),
        _buildSeverityField(type, record, draft),
        _buildActions(type, record),
      ],
    );
  }

  /// Resolves the type-conditional credential fields for [type], one arm per
  /// channel shape (Slack: bot token + channel; webhook: URL + secret).
  List<Widget> _buildTypeFields(ChannelType type, _ChannelDraft draft) {
    return switch (type) {
      ChannelType.slack => [
        MSFormField(
          // The channels namespace has no dedicated Slack-token label string
          // and the lang assets are out of this step's file scope; see
          // `### Deviations`.
          label: 'Bot token',
          error: draft.tokenError,
          child: MSInput(
            value: draft.token,
            onChanged: (value) => setState(() {
              draft.token = value;
              draft.tokenError = null;
            }),
            type: InputType.password,
            placeholder: 'xoxb-...',
          ),
        ),
        MSFormField(
          label: trans('uptizm.teams.channels_slack_channel_label'),
          child: MSInput(
            value: draft.channel,
            onChanged: (value) => setState(() => draft.channel = value),
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
            onChanged: (value) => setState(() {
              draft.url = value;
              draft.urlError = null;
            }),
            placeholder: 'https://...',
          ),
        ),
        MSFormField(
          label: trans('uptizm.teams.channels_webhook_secret_label'),
          hint: trans('uptizm.teams.channels_webhook_secret_hint'),
          child: MSInput(
            value: draft.secret,
            onChanged: (value) => setState(() => draft.secret = value),
            type: InputType.password,
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
  /// token / webhook URL is required only when connecting for the first
  /// time; an already-connected channel may resave with the credential
  /// fields left blank), then creates or updates the channel through
  /// [NotificationChannelController]. A server 422 maps its
  /// `credentials.token`/`credentials.url` key back onto the matching inline
  /// error slot.
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
    });
  }

  /// Runs the client-side required check for [type]'s credential field,
  /// painting its inline error slot, and returns whether the form may be
  /// submitted. Only enforced when [isNew] (connecting for the first time);
  /// an already-connected channel may resave with blank credential fields
  /// (the stored credential stays untouched).
  bool _validate(ChannelType type, _ChannelDraft draft, {required bool isNew}) {
    if (!isNew) return true;

    if (type == ChannelType.slack) {
      final String? error = draft.token.trim().isEmpty
          ? trans('validation.required', {'attribute': 'Bot token'})
          : null;
      setState(() => draft.tokenError = error);
      return error == null;
    }

    final String? error = draft.url.trim().isEmpty
        ? trans('validation.required', {
            'attribute': trans('uptizm.teams.channels_webhook_url_label'),
          })
        : null;
    setState(() => draft.urlError = error);
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

    if (type == ChannelType.slack) {
      if (draft.token.trim().isNotEmpty) {
        fields['credentials'] = {
          'token': draft.token.trim(),
          if (draft.channel.trim().isNotEmpty) 'channel': draft.channel.trim(),
        };
      }
    } else if (type == ChannelType.webhook) {
      if (draft.url.trim().isNotEmpty) {
        fields['credentials'] = {
          'url': draft.url.trim(),
          if (draft.secret.trim().isNotEmpty) 'secret': draft.secret.trim(),
        };
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
