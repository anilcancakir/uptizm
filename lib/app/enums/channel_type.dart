import 'package:magic/magic.dart';

/// Where a team's monitoring + incident alerts can be delivered.
///
/// Only Slack and a generic webhook are real, backend-registered team channels
/// (`notification_channels.channel_type`). Email and push are per-user
/// preferences at `/settings/notifications`; SMS and Microsoft Teams are phase
/// 2 and are deliberately not modeled here.
enum ChannelType {
  slack,
  webhook;

  /// Localized channel name shown as the row title.
  String get label => switch (this) {
    ChannelType.slack => trans('uptizm.enums.channel_type.slack'),
    ChannelType.webhook => trans('uptizm.enums.channel_type.webhook'),
  };
}
