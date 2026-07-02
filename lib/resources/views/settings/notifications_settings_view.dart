import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/settings.dart';
import '../../../app/mocks/teams.dart';

/// **Notifications settings sub-page (`/settings/notifications`).**
///
/// A faithful Flutter port of the React `NotificationsSettingsPage.tsx`:
/// personal delivery preferences for THIS account and device (in-app bell,
/// browser web push, email), rendered as a single [SettingsSection] of three
/// [SettingsRow]s. Team-wide channels (Slack, SMS, webhook) live under the
/// team's notification channels (`/teams/notifications`), linked from the
/// footer note.
///
/// Toggling in-app/email flips a [Switch] over local state. Web push starts
/// off and shows a secondary "Enable" [Button] instead of a switch (mirroring
/// the browser-permission-request framing); once enabled it renders as an
/// on [Switch] that can be turned back off. Nothing persists (matches the
/// app-wide mock convention for settings).
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/notifications', () => const NotificationsSettingsView());
/// ```
@immutable
class NotificationsSettingsView extends StatefulWidget {
  /// Creates the [NotificationsSettingsView].
  const NotificationsSettingsView({super.key});

  @override
  State<NotificationsSettingsView> createState() =>
      _NotificationsSettingsViewState();
}

class _NotificationsSettingsViewState extends State<NotificationsSettingsView> {
  /// Mutable copy of [defaultNotificationPrefs], toggled via [_toggle].
  late List<NotificationPref> _prefs;

  @override
  void initState() {
    super.initState();
    _prefs = List<NotificationPref>.of(defaultNotificationPrefs);
  }

  /// Reads the current enabled state for [channel].
  bool _isEnabled(NotificationChannel channel) {
    return _prefs
        .firstWhere((NotificationPref pref) => pref.channel == channel)
        .enabled;
  }

  /// Flips [channel] to [enabled], replacing the fixture entry in place.
  void _toggle(NotificationChannel channel, bool enabled) {
    setState(() {
      _prefs = [
        for (final NotificationPref pref in _prefs)
          if (pref.channel == channel)
            NotificationPref(channel: channel, enabled: enabled)
          else
            pref,
      ];
    });
  }

  /// Resolves the leading icon for [channel].
  IconData _iconFor(NotificationChannel channel) => switch (channel) {
    NotificationChannel.inApp => Icons.notifications_outlined,
    NotificationChannel.webPush => Icons.public_outlined,
    NotificationChannel.email => Icons.mail_outline,
  };

  /// Resolves the row title for [channel].
  String _titleFor(NotificationChannel channel) => switch (channel) {
    NotificationChannel.inApp => trans(
      'uptizm.settings.notifications_inapp_title',
    ),
    NotificationChannel.webPush => trans(
      'uptizm.settings.notifications_webpush_title',
    ),
    NotificationChannel.email => trans(
      'uptizm.settings.notifications_email_title',
    ),
  };

  /// Resolves the row subtitle for [channel], reflecting live toggle state
  /// for web push and interpolating the account email for the email row.
  String _subtitleFor(NotificationChannel channel) => switch (channel) {
    NotificationChannel.inApp => trans(
      'uptizm.settings.notifications_inapp_subtitle',
    ),
    NotificationChannel.webPush =>
      _isEnabled(channel)
          ? trans('uptizm.settings.notifications_webpush_subtitle_on')
          : trans('uptizm.settings.notifications_webpush_subtitle_off'),
    NotificationChannel.email => trans(
      'uptizm.settings.notifications_email_subtitle',
      {'email': currentUser.email},
    ),
  };

  /// Builds the trailing control for [channel].
  ///
  /// Web push, while off, shows an "Enable" [Button] instead of a switch
  /// (mirrors the browser-permission framing in the React source); once
  /// enabled it renders as any other row's [Switch].
  Widget _trailingFor(NotificationChannel channel) {
    final bool enabled = _isEnabled(channel);

    if (channel == NotificationChannel.webPush && !enabled) {
      return Button(
        intent: ButtonIntent.secondary,
        size: ButtonSize.sm,
        onPressed: () => _toggle(channel, true),
        child: WText(
          trans('uptizm.settings.notifications_webpush_enable_button'),
        ),
      );
    }

    return Switch(
      value: enabled,
      onChanged: (bool value) => _toggle(channel, value),
    );
  }

  @override
  Widget build(BuildContext context) {
    return SettingsScaffold(
      title: trans('uptizm.settings.notifications_title'),
      subtitle: trans('uptizm.settings.notifications_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        SettingsSection(
          children: [
            for (final NotificationPref pref in _prefs)
              SettingsRow(
                icon: _iconFor(pref.channel),
                title: _titleFor(pref.channel),
                subtitle: _subtitleFor(pref.channel),
                trailing: _trailingFor(pref.channel),
              ),
          ],
        ),

        // Footer note: team-wide channels live under the team's notification
        // channels, a ComingSoon stub wired in Step 10.
        WText(
          trans('uptizm.settings.notifications_footer'),
          className: 'px-1 text-xs text-fg-muted',
        ),
      ],
    );
  }
}
