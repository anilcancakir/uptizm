import 'package:magic/magic.dart';

/// Where a team's monitoring + incident alerts can be delivered.
enum ChannelType {
  email,
  sms,
  slack,
  teams,
  webhook;

  /// Localized channel name shown as the row title.
  String get label => switch (this) {
    ChannelType.email => trans('uptizm.enums.channel_type.email'),
    ChannelType.sms => trans('uptizm.enums.channel_type.sms'),
    ChannelType.slack => trans('uptizm.enums.channel_type.slack'),
    ChannelType.teams => trans('uptizm.enums.channel_type.teams'),
    ChannelType.webhook => trans('uptizm.enums.channel_type.webhook'),
  };
}
