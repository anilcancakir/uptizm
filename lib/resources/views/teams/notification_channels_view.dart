import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/teams_data.dart';
import '../../../ui/layouts/page_container.dart';

/// **The team notification channels screen (`/teams/notifications`).**
///
/// A faithful Flutter port of the design lab's `NotificationChannelsPage.tsx`:
/// team-level integrations that the whole team's monitoring and incident alerts
/// route to (email, SMS, Slack, Microsoft Teams, webhook). One [Card] holds a
/// row per [NotificationChannelConfig]: a channel icon tile, its name (with a
/// severity summary [Badge] when connected), the detail line, and a trailing
/// control that is a [Switch] when the channel is connected or a "Connect"
/// [Button] when it is not.
///
/// Tapping a row toggles an inline config form (the toggle-reveals-config
/// pattern from `TwoFactorSettingsView`): type-conditional fields resolved by a
/// switch on [ChannelType] (email recipients, SMS number, Slack workspace and
/// channel, Teams webhook URL, webhook endpoint and signing secret), a severity
/// [SegmentedControl] (All alerts / Critical only), plus Save and Send-test
/// [Button]s.
///
/// This is a pure UI mock: connecting, toggling, changing severity, saving, and
/// sending a test only mutate local state and show a [Magic.success] toast.
/// There is no real integration and nothing is persisted.
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

/// Per-channel local UI state, seeded from a [NotificationChannelConfig].
///
/// Holds only the mutable bits the view drives (connection, delivery toggle,
/// expansion, severity); the immutable channel identity (type, name, detail)
/// stays on the fixture and is looked up alongside this state.
class _ChannelState {
  /// Whether the integration has been set up.
  bool connected;

  /// Whether alerts are currently delivered here.
  bool enabled;

  /// Whether the inline config form is expanded.
  bool expanded;

  /// Minimum severity this channel delivers: `'all'` or `'critical'`.
  String severity;

  _ChannelState({
    required this.connected,
    required this.enabled,
    required this.expanded,
    required this.severity,
  });
}

class _NotificationChannelsViewState extends State<NotificationChannelsView> {
  /// The two severity options, in [SegmentedControl] display order. Index 0 is
  /// `'all'`, index 1 is `'critical'`.
  static const List<String> _severityValues = ['all', 'critical'];

  /// Per-channel local state, keyed by [ChannelType] and seeded once from
  /// [notificationChannels] in [initState].
  late final Map<ChannelType, _ChannelState> _states;

  @override
  void initState() {
    super.initState();
    _states = {
      for (final NotificationChannelConfig channel in notificationChannels)
        channel.type: _ChannelState(
          connected: channel.connected,
          enabled: channel.enabled,
          expanded: false,
          severity: channel.severity,
        ),
    };
  }

  /// Resolves the leading icon for [type].
  IconData _iconFor(ChannelType type) => switch (type) {
    ChannelType.email => Icons.mail_outline,
    ChannelType.sms => Icons.sms_outlined,
    ChannelType.slack => Icons.tag,
    ChannelType.teams => Icons.groups_outlined,
    ChannelType.webhook => Icons.webhook,
  };

  /// Resolves the localized description line for [type].
  String _descriptionFor(ChannelType type) => switch (type) {
    ChannelType.email => trans('uptizm.teams.channels_email_desc'),
    ChannelType.sms => trans('uptizm.teams.channels_sms_desc'),
    ChannelType.slack => trans('uptizm.teams.channels_slack_desc'),
    ChannelType.teams => trans('uptizm.teams.channels_teams_desc'),
    ChannelType.webhook => trans('uptizm.teams.channels_webhook_desc'),
  };

  /// Resolves the localized severity summary label for [severity].
  String _severityLabel(String severity) => severity == 'critical'
      ? trans('uptizm.teams.channels_severity_critical')
      : trans('uptizm.teams.channels_severity_all');

  /// Connects [type] (mock: flips connected + enabled on and shows a toast).
  void _connect(ChannelType type) {
    setState(() {
      final _ChannelState state = _states[type]!;
      state.connected = true;
      state.enabled = true;
      state.expanded = true;
    });
    _channelToast(type, trans('uptizm.teams.channels_connect_button'));
  }

  /// Toggles alert delivery for [type] (mock: local state only).
  void _toggle(ChannelType type, bool enabled) {
    setState(() => _states[type]!.enabled = enabled);
  }

  /// Toggles the inline config form for [type].
  void _toggleExpanded(ChannelType type) {
    setState(() {
      final _ChannelState state = _states[type]!;
      state.expanded = !state.expanded;
    });
  }

  /// Sets the delivery severity for [type] from a [SegmentedControl] index.
  void _setSeverity(ChannelType type, int index) {
    setState(() => _states[type]!.severity = _severityValues[index]);
  }

  /// Saves the channel config (mock: no persistence, toast only).
  void _save(ChannelType type) {
    _channelToast(type, trans('uptizm.teams.channels_save_button'));
  }

  /// Sends a test alert (mock: no delivery, toast only).
  void _sendTest(ChannelType type) {
    _channelToast(type, trans('uptizm.teams.channels_test_button'));
  }

  /// Shows a [Magic.success] toast naming the [action] and the channel [type].
  void _channelToast(ChannelType type, String action) {
    Magic.success(action, type.label);
  }

  @override
  Widget build(BuildContext context) {
    // A plain Flutter Column scaffolds the page body so each descendant gets a
    // bounded full-width constraint from PageContainer (same discipline as the
    // list views); Wind utilities only appear on leaf containers.
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

          // 2. One full-bleed card with a divided row per channel.
          _buildChannelsCard(),
        ],
      ),
    );
  }

  /// Builds the full-bleed card holding one row (plus its inline config) per
  /// channel, with hairline dividers between rows.
  Widget _buildChannelsCard() {
    final int lastIndex = notificationChannels.length - 1;

    return MSCard(
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          for (int index = 0; index < notificationChannels.length; index++)
            _buildChannel(notificationChannels[index], index < lastIndex),
        ],
      ),
    );
  }

  /// Builds a single channel: the header row and, when expanded, its inline
  /// config form. [hasDivider] draws a hairline bottom border between rows.
  Widget _buildChannel(NotificationChannelConfig channel, bool hasDivider) {
    final _ChannelState state = _states[channel.type]!;

    return WDiv(
      className: hasDivider
          ? 'flex flex-col border-b border-color-border dark:border-color-border'
          : 'flex flex-col',
      children: [
        _buildRow(channel, state),
        if (state.expanded) _buildConfig(channel, state),
      ],
    );
  }

  /// Builds the tappable channel header row: icon tile + name/detail column +
  /// trailing control (a [Switch] when connected, a "Connect" [Button] when
  /// not). Tapping the row toggles the inline config form.
  Widget _buildRow(NotificationChannelConfig channel, _ChannelState state) {
    return WAnchor(
      onTap: () => _toggleExpanded(channel.type),
      child: WDiv(
        className: 'flex flex-row items-center gap-3 px-5 py-4',
        children: [
          _buildIconTile(channel.type, state.enabled),
          _buildDetails(channel, state),
          _buildTrailing(channel.type, state),
        ],
      ),
    );
  }

  /// Builds the square icon tile. It reads in the `ai` tint while the channel
  /// is enabled and in a muted tone otherwise, mirroring the React source.
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

  /// Builds the flexible name + detail column. Shows a severity summary [Badge]
  /// next to the name and the detail line only while the channel is connected.
  Widget _buildDetails(NotificationChannelConfig channel, _ChannelState state) {
    return WDiv(
      className: 'flex flex-col gap-0.5 flex-1 min-w-0',
      children: [
        WDiv(
          className: 'flex flex-row flex-wrap items-center gap-2',
          children: [
            WText(
              channel.name,
              className: 'text-sm font-medium text-fg dark:text-fg',
            ),
            if (state.connected) MSBadge(_severityLabel(state.severity)),
          ],
        ),
        WText(
          _descriptionFor(channel.type),
          className: 'text-xs text-fg-muted dark:text-fg-muted',
        ),
        if (state.connected && channel.detail.isNotEmpty)
          WText(
            channel.detail,
            className:
                'truncate font-mono text-xs text-fg-muted dark:text-fg-muted',
          ),
      ],
    );
  }

  /// Builds the trailing control: a [Switch] once connected, or a "Connect"
  /// [Button] while the integration is not yet set up.
  Widget _buildTrailing(ChannelType type, _ChannelState state) {
    if (!state.connected) {
      return MSButton(
        intent: ButtonIntent.secondary,
        size: ButtonSize.sm,
        onPressed: () => _connect(type),
        child: WText(trans('uptizm.teams.channels_connect_button')),
      );
    }

    return MSSwitch(
      value: state.enabled,
      onChanged: (bool value) => _toggle(type, value),
    );
  }

  /// Builds the inline config form: the type-conditional fields, the severity
  /// [SegmentedControl], and the Save + Send-test actions.
  Widget _buildConfig(NotificationChannelConfig channel, _ChannelState state) {
    return WDiv(
      className:
          'flex flex-col gap-4 border-t border-color-border '
          'dark:border-color-border px-5 py-4',
      children: [
        ..._buildTypeFields(channel),
        _buildSeverityField(channel.type, state.severity),
        _buildActions(channel.type),
      ],
    );
  }

  /// Resolves the type-conditional config fields for [channel] via a switch on
  /// its [ChannelType], one arm per channel shape.
  List<Widget> _buildTypeFields(NotificationChannelConfig channel) {
    return switch (channel.type) {
      ChannelType.email => [
        MSFormField(
          label: trans('uptizm.teams.channels_email_recipients_label'),
          hint: trans('uptizm.teams.channels_email_recipients_hint'),
          child: MSInput(
            placeholder: trans(
              'uptizm.teams.channels_email_recipients_placeholder',
            ),
          ),
        ),
      ],
      ChannelType.sms => [
        MSFormField(
          label: trans('uptizm.teams.channels_sms_phone_label'),
          child: MSInput(value: channel.detail, className: 'font-mono'),
        ),
      ],
      ChannelType.slack => [
        WDiv(
          className: 'grid grid-cols-1 sm:grid-cols-2 gap-4',
          children: [
            MSFormField(
              label: trans('uptizm.teams.channels_slack_workspace_label'),
              child: const MSInput(value: 'Acme', enabled: false),
            ),
            MSFormField(
              label: trans('uptizm.teams.channels_slack_channel_label'),
              child: MSInput(
                value: trans('uptizm.teams.channels_slack_channel_placeholder'),
                className: 'font-mono',
              ),
            ),
          ],
        ),
      ],
      ChannelType.teams => [
        MSFormField(
          label: trans('uptizm.teams.channels_teams_webhook_label'),
          hint: trans('uptizm.teams.channels_teams_webhook_hint'),
          child: MSInput(
            placeholder: trans(
              'uptizm.teams.channels_teams_webhook_placeholder',
            ),
            className: 'font-mono',
          ),
        ),
      ],
      ChannelType.webhook => [
        MSFormField(
          label: trans('uptizm.teams.channels_webhook_url_label'),
          child: MSInput(value: channel.detail, className: 'font-mono'),
        ),
        MSFormField(
          label: trans('uptizm.teams.channels_webhook_secret_label'),
          hint: trans('uptizm.teams.channels_webhook_secret_hint'),
          child: const MSInput(
            value: 'whsec_********',
            type: InputType.password,
            className: 'font-mono',
          ),
        ),
      ],
    };
  }

  /// Builds the severity delivery field: a [SegmentedControl] over the All /
  /// Critical options, wrapped in a labeled [MagicFormField].
  Widget _buildSeverityField(ChannelType type, String severity) {
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
        onChanged: (int index) => _setSeverity(type, index),
      ),
    );
  }

  /// Builds the Save + Send-test action row.
  Widget _buildActions(ChannelType type) {
    return WDiv(
      className: 'flex flex-row flex-wrap gap-2',
      children: [
        MSButton(
          size: ButtonSize.sm,
          onPressed: () => _save(type),
          child: WText(trans('uptizm.teams.channels_save_button')),
        ),
        MSButton(
          intent: ButtonIntent.secondary,
          size: ButtonSize.sm,
          onPressed: () => _sendTest(type),
          child: WText(trans('uptizm.teams.channels_test_button')),
        ),
      ],
    );
  }
}
