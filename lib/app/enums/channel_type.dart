import 'package:magic/magic.dart';

/// Where a team's monitoring + incident alerts can be delivered.
///
/// The backend-registered team channels (`notification_channels.channel_type`):
/// Slack, a generic webhook, PagerDuty (Events API v2), and Microsoft Teams
/// (Workflows incoming webhook). Email and push are per-user preferences at
/// `/settings/notifications`; SMS is an opt-in per-user preference and is
/// deliberately not modeled as a team channel here.
enum ChannelType {
  slack,
  webhook,
  pagerduty,
  teams;

  /// Localized channel name shown as the row title.
  String get label => switch (this) {
    ChannelType.slack => trans('uptizm.enums.channel_type.slack'),
    ChannelType.webhook => trans('uptizm.enums.channel_type.webhook'),
    ChannelType.pagerduty => trans('uptizm.enums.channel_type.pagerduty'),
    ChannelType.teams => trans('uptizm.enums.channel_type.teams'),
  };
}
