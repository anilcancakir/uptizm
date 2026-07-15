import 'package:flutter/foundation.dart';

import '../enums/change_kind.dart' show ChangeKind;
import '../enums/notification_channel.dart' show NotificationChannel;

/// A device currently signed in to the account. Mirrors the `SESSIONS`
/// fixture in the React `SessionsSettingsPage`.
@immutable
class DeviceSession {
  /// Stable identifier used for the sign-out action.
  final String id;

  /// Device and browser/app label, e.g. `"MacBook Pro · Chrome"`.
  final String device;

  /// City/country of the session, e.g. `"Istanbul, TR"`.
  final String location;

  /// Relative recency string, e.g. `"2h ago"` or `"current session"`.
  final String lastActive;

  /// Whether this is the session the viewer is currently using.
  final bool current;

  const DeviceSession({
    required this.id,
    required this.device,
    required this.location,
    required this.lastActive,
    required this.current,
  });
}

/// A selectable app language. Mirrors the `LANGUAGES` fixture in the React
/// `LanguageSettingsPage`.
@immutable
class AppLanguage {
  /// ISO 639-1 language code, e.g. `'en'`.
  final String code;

  /// Native-script display name, e.g. `'Türkçe'`.
  final String native;

  /// English display label, e.g. `'Turkish'`.
  final String label;

  const AppLanguage({
    required this.code,
    required this.native,
    required this.label,
  });
}

/// A selectable IANA timezone. Mirrors the `Zone` type in the React
/// `TimezoneSettingsPage`, minus the `minutes` sort key (the mock list is
/// already ordered west to east).
@immutable
class AppTimezone {
  /// IANA zone identifier, e.g. `'Europe/Istanbul'`.
  final String value;

  /// Display city, e.g. `'Istanbul'`.
  final String city;

  /// Display region, e.g. `'Europe'`.
  final String region;

  /// Current GMT offset label, e.g. `'GMT+03:00'`.
  final String offset;

  const AppTimezone({
    required this.value,
    required this.city,
    required this.region,
    required this.offset,
  });
}

/// A personal notification channel's on/off state.
@immutable
class NotificationPref {
  /// Which delivery channel this preference controls.
  final NotificationChannel channel;

  /// Whether the channel is currently enabled.
  final bool enabled;

  const NotificationPref({required this.channel, required this.enabled});
}

/// A single tagged line item within a [ChangelogRelease].
@immutable
class ChangelogChange {
  /// Classification badge shown before the text.
  final ChangeKind kind;

  /// The change description.
  final String text;

  const ChangelogChange({required this.kind, required this.text});
}

/// One versioned release entry in the changelog.
@immutable
class ChangelogRelease {
  /// Semantic version string, e.g. `'2.4.0'`.
  final String version;

  /// Release date string, e.g. `'Jun 20, 2026'`.
  final String date;

  /// Tagged changes shipped in this release.
  final List<ChangelogChange> changes;

  const ChangelogRelease({
    required this.version,
    required this.date,
    required this.changes,
  });
}

/// A single frequently-asked question and its answer.
@immutable
class FaqItem {
  /// The question text.
  final String question;

  /// The answer text.
  final String answer;

  const FaqItem({required this.question, required this.answer});
}

/// A single heading/body section within a legal document.
@immutable
class LegalSection {
  /// Section heading.
  final String heading;

  /// Section body text.
  final String body;

  const LegalSection({required this.heading, required this.body});
}
