/// Where a team's monitoring + incident alerts can be delivered.
enum ChannelType {
  email,
  sms,
  slack,
  teams,
  webhook;

  /// Human-readable channel name shown as the row title.
  String get label => switch (this) {
    ChannelType.email => 'Email',
    ChannelType.sms => 'SMS',
    ChannelType.slack => 'Slack',
    ChannelType.teams => 'Microsoft Teams',
    ChannelType.webhook => 'Webhook',
  };
}
