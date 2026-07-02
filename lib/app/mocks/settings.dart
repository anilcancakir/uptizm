import 'package:flutter/widgets.dart' show immutable;

/// **Settings-domain mock fixtures.**
///
/// Ported from the design lab's `src/pages/settings/*.tsx` pages. Backs the
/// account-level Settings vertical (sessions, language, timezone,
/// notifications, changelog, help, and the two legal docs). Mirrors the
/// fixture shape of `status_pages.dart`/`incidents.dart`: immutable value
/// classes, `const` fixtures, tiny helper functions.
///
/// Timezones are a special case: [timezonesFromApi] is a curated, hardcoded
/// list framed as an API response, not a client-side `Intl` enumeration
/// (the React source uses `Intl.supportedValuesOf('timeZone')`; the Flutter
/// mock deliberately does not, per the ported design decision).

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

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

/// Active-session fixtures. One current device, two others. Mirrors the
/// `SESSIONS` fixture in the React `SessionsSettingsPage`.
const List<DeviceSession> deviceSessions = [
  DeviceSession(
    id: 'cur',
    device: 'MacBook Pro · Chrome',
    location: 'Istanbul, TR',
    lastActive: 'current session',
    current: true,
  ),
  DeviceSession(
    id: 'iphone',
    device: 'iPhone 15 · Uptizm app',
    location: 'Istanbul, TR',
    lastActive: '2h ago',
    current: false,
  ),
  DeviceSession(
    id: 'win',
    device: 'Windows · Edge',
    location: 'Berlin, DE',
    lastActive: '3d ago',
    current: false,
  ),
];

// ---------------------------------------------------------------------------
// Language
// ---------------------------------------------------------------------------

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

/// Supported app languages, matching the React source's seven entries.
const List<AppLanguage> appLanguages = [
  AppLanguage(code: 'en', native: 'English', label: 'English'),
  AppLanguage(code: 'tr', native: 'Türkçe', label: 'Turkish'),
  AppLanguage(code: 'de', native: 'Deutsch', label: 'German'),
  AppLanguage(code: 'es', native: 'Español', label: 'Spanish'),
  AppLanguage(code: 'fr', native: 'Français', label: 'French'),
  AppLanguage(code: 'pt', native: 'Português', label: 'Portuguese'),
  AppLanguage(code: 'ja', native: '日本語', label: 'Japanese'),
];

// ---------------------------------------------------------------------------
// Timezone
// ---------------------------------------------------------------------------

/// A selectable IANA timezone. Mirrors the `Zone` type in the React
/// `TimezoneSettingsPage`, minus the `minutes` sort key (the mock list below
/// is already ordered west to east).
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

/// Curated, hardcoded timezone fixtures framed as an API response.
///
/// The React source enumerates the runtime's full IANA database via
/// `Intl.supportedValuesOf('timeZone')` (~400+ zones). This mock deliberately
/// does not enumerate zones client-side; it ships a curated ~30-40 common
/// zones as content data, the shape a real backend timezone endpoint would
/// return. Ordered west to east by offset, matching the React source's sort.
///
/// ```dart
/// final zones = timezonesFromApi();
/// print(zones.first.value); // "Pacific/Honolulu"
/// ```
List<AppTimezone> timezonesFromApi() {
  return const [
    AppTimezone(
      value: 'Pacific/Honolulu',
      city: 'Honolulu',
      region: 'Pacific',
      offset: 'GMT-10:00',
    ),
    AppTimezone(
      value: 'America/Anchorage',
      city: 'Anchorage',
      region: 'America',
      offset: 'GMT-09:00',
    ),
    AppTimezone(
      value: 'America/Los_Angeles',
      city: 'Los Angeles',
      region: 'America',
      offset: 'GMT-08:00',
    ),
    AppTimezone(
      value: 'America/Vancouver',
      city: 'Vancouver',
      region: 'America',
      offset: 'GMT-08:00',
    ),
    AppTimezone(
      value: 'America/Denver',
      city: 'Denver',
      region: 'America',
      offset: 'GMT-07:00',
    ),
    AppTimezone(
      value: 'America/Phoenix',
      city: 'Phoenix',
      region: 'America',
      offset: 'GMT-07:00',
    ),
    AppTimezone(
      value: 'America/Chicago',
      city: 'Chicago',
      region: 'America',
      offset: 'GMT-06:00',
    ),
    AppTimezone(
      value: 'America/Mexico_City',
      city: 'Mexico City',
      region: 'America',
      offset: 'GMT-06:00',
    ),
    AppTimezone(
      value: 'America/New_York',
      city: 'New York',
      region: 'America',
      offset: 'GMT-05:00',
    ),
    AppTimezone(
      value: 'America/Toronto',
      city: 'Toronto',
      region: 'America',
      offset: 'GMT-05:00',
    ),
    AppTimezone(
      value: 'America/Bogota',
      city: 'Bogota',
      region: 'America',
      offset: 'GMT-05:00',
    ),
    AppTimezone(
      value: 'America/Santiago',
      city: 'Santiago',
      region: 'America',
      offset: 'GMT-04:00',
    ),
    AppTimezone(
      value: 'America/Sao_Paulo',
      city: 'Sao Paulo',
      region: 'America',
      offset: 'GMT-03:00',
    ),
    AppTimezone(
      value: 'America/Buenos_Aires',
      city: 'Buenos Aires',
      region: 'America',
      offset: 'GMT-03:00',
    ),
    AppTimezone(
      value: 'Atlantic/Azores',
      city: 'Azores',
      region: 'Atlantic',
      offset: 'GMT-01:00',
    ),
    AppTimezone(
      value: 'UTC',
      city: 'Coordinated Universal Time',
      region: 'UTC',
      offset: 'GMT+00:00',
    ),
    AppTimezone(
      value: 'Europe/London',
      city: 'London',
      region: 'Europe',
      offset: 'GMT+00:00',
    ),
    AppTimezone(
      value: 'Europe/Lisbon',
      city: 'Lisbon',
      region: 'Europe',
      offset: 'GMT+00:00',
    ),
    AppTimezone(
      value: 'Europe/Paris',
      city: 'Paris',
      region: 'Europe',
      offset: 'GMT+01:00',
    ),
    AppTimezone(
      value: 'Europe/Berlin',
      city: 'Berlin',
      region: 'Europe',
      offset: 'GMT+01:00',
    ),
    AppTimezone(
      value: 'Europe/Madrid',
      city: 'Madrid',
      region: 'Europe',
      offset: 'GMT+01:00',
    ),
    AppTimezone(
      value: 'Africa/Lagos',
      city: 'Lagos',
      region: 'Africa',
      offset: 'GMT+01:00',
    ),
    AppTimezone(
      value: 'Europe/Athens',
      city: 'Athens',
      region: 'Europe',
      offset: 'GMT+02:00',
    ),
    AppTimezone(
      value: 'Africa/Cairo',
      city: 'Cairo',
      region: 'Africa',
      offset: 'GMT+02:00',
    ),
    AppTimezone(
      value: 'Africa/Johannesburg',
      city: 'Johannesburg',
      region: 'Africa',
      offset: 'GMT+02:00',
    ),
    AppTimezone(
      value: 'Europe/Istanbul',
      city: 'Istanbul',
      region: 'Europe',
      offset: 'GMT+03:00',
    ),
    AppTimezone(
      value: 'Europe/Moscow',
      city: 'Moscow',
      region: 'Europe',
      offset: 'GMT+03:00',
    ),
    AppTimezone(
      value: 'Asia/Dubai',
      city: 'Dubai',
      region: 'Asia',
      offset: 'GMT+04:00',
    ),
    AppTimezone(
      value: 'Asia/Karachi',
      city: 'Karachi',
      region: 'Asia',
      offset: 'GMT+05:00',
    ),
    AppTimezone(
      value: 'Asia/Kolkata',
      city: 'Kolkata',
      region: 'Asia',
      offset: 'GMT+05:30',
    ),
    AppTimezone(
      value: 'Asia/Dhaka',
      city: 'Dhaka',
      region: 'Asia',
      offset: 'GMT+06:00',
    ),
    AppTimezone(
      value: 'Asia/Bangkok',
      city: 'Bangkok',
      region: 'Asia',
      offset: 'GMT+07:00',
    ),
    AppTimezone(
      value: 'Asia/Singapore',
      city: 'Singapore',
      region: 'Asia',
      offset: 'GMT+08:00',
    ),
    AppTimezone(
      value: 'Asia/Shanghai',
      city: 'Shanghai',
      region: 'Asia',
      offset: 'GMT+08:00',
    ),
    AppTimezone(
      value: 'Asia/Tokyo',
      city: 'Tokyo',
      region: 'Asia',
      offset: 'GMT+09:00',
    ),
    AppTimezone(
      value: 'Asia/Seoul',
      city: 'Seoul',
      region: 'Asia',
      offset: 'GMT+09:00',
    ),
    AppTimezone(
      value: 'Australia/Sydney',
      city: 'Sydney',
      region: 'Australia',
      offset: 'GMT+10:00',
    ),
    AppTimezone(
      value: 'Pacific/Auckland',
      city: 'Auckland',
      region: 'Pacific',
      offset: 'GMT+12:00',
    ),
  ];
}

/// Filters [timezonesFromApi] by [query] against city, region, offset, or
/// value, case-insensitively. A pure function over the fixture list.
///
/// Mirrors the client-side filtering the React combobox performs on its
/// `itemToStringLabel` composite string.
///
/// ```dart
/// searchTimezones('istanbul').single.value; // "Europe/Istanbul"
/// ```
List<AppTimezone> searchTimezones(String query) {
  final String needle = query.trim().toLowerCase();
  if (needle.isEmpty) return timezonesFromApi();

  return [
    for (final AppTimezone zone in timezonesFromApi())
      if (zone.city.toLowerCase().contains(needle) ||
          zone.region.toLowerCase().contains(needle) ||
          zone.offset.toLowerCase().contains(needle) ||
          zone.value.toLowerCase().contains(needle))
        zone,
  ];
}

// ---------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------

/// A personal notification delivery channel. Mirrors the three `SettingRow`
/// entries in the React `NotificationsSettingsPage` (in-app, web push,
/// email); team-wide channels (Slack, SMS, webhook) live elsewhere.
enum NotificationChannel {
  /// The notification bell, on web and the mobile app.
  inApp,

  /// Browser push, even when the tab is closed.
  webPush,

  /// Email delivery to the signed-in account.
  email;

  /// Human-readable display label.
  String get label => switch (this) {
    NotificationChannel.inApp => 'In-app',
    NotificationChannel.webPush => 'Web push',
    NotificationChannel.email => 'Email',
  };
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

/// Default personal notification preferences: in-app on, web push off
/// (requires explicit browser permission), email on. Mirrors the React
/// `NotificationsSettingsPage` defaults (`defaultChecked` on in-app/email,
/// `useState(false)` on web push).
const List<NotificationPref> defaultNotificationPrefs = [
  NotificationPref(channel: NotificationChannel.inApp, enabled: true),
  NotificationPref(channel: NotificationChannel.webPush, enabled: false),
  NotificationPref(channel: NotificationChannel.email, enabled: true),
];

// ---------------------------------------------------------------------------
// Changelog
// ---------------------------------------------------------------------------

/// Tag classifying a single changelog entry. Mirrors the `ChangeType` union
/// in the React `ChangelogSettingsPage` (`"New"` renamed to [added] to avoid
/// colliding with Dart's `new` keyword).
enum ChangeKind {
  /// A newly shipped capability.
  added,

  /// An enhancement to existing behavior.
  improved,

  /// A bug fix.
  fixed;

  /// Human-readable display label matching the React source's badge text.
  String get label => switch (this) {
    ChangeKind.added => 'New',
    ChangeKind.improved => 'Improved',
    ChangeKind.fixed => 'Fixed',
  };
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

/// Changelog fixtures, newest first. Mirrors the `RELEASES` fixture in the
/// React `ChangelogSettingsPage`.
const List<ChangelogRelease> changelog = [
  ChangelogRelease(
    version: '2.4.0',
    date: 'Jun 20, 2026',
    changes: [
      ChangelogChange(
        kind: ChangeKind.added,
        text:
            'Uptizm AI assistant: ask about your monitors or set things up '
            'from any screen.',
      ),
      ChangelogChange(
        kind: ChangeKind.added,
        text:
            'Status page subscriptions with a subscriber list and custom '
            'logo upload.',
      ),
      ChangelogChange(
        kind: ChangeKind.improved,
        text:
            "Per-monitor response charts now reflect each monitor's own "
            'baseline.',
      ),
    ],
  ),
  ChangelogRelease(
    version: '2.3.0',
    date: 'Jun 6, 2026',
    changes: [
      ChangelogChange(
        kind: ChangeKind.added,
        text:
            'Notification channels: Slack, Microsoft Teams, SMS, and '
            'webhooks.',
      ),
      ChangelogChange(
        kind: ChangeKind.improved,
        text:
            'Incident timeline now separates public updates from internal '
            'activity.',
      ),
      ChangelogChange(
        kind: ChangeKind.fixed,
        text: 'Dark mode contrast on the AI analysis card.',
      ),
    ],
  ),
  ChangelogRelease(
    version: '2.2.0',
    date: 'May 22, 2026',
    changes: [
      ChangelogChange(
        kind: ChangeKind.added,
        text:
            'Custom metrics: point at your own endpoint and chart it under '
            'a monitor.',
      ),
      ChangelogChange(
        kind: ChangeKind.improved,
        text: 'Faster anomaly detection on regional latency.',
      ),
    ],
  ),
];

// ---------------------------------------------------------------------------
// Help
// ---------------------------------------------------------------------------

/// A single frequently-asked question and its answer.
@immutable
class FaqItem {
  /// The question text.
  final String question;

  /// The answer text.
  final String answer;

  const FaqItem({required this.question, required this.answer});
}

/// FAQ fixtures from the React `HelpSettingsPage`.
const List<FaqItem> faqItems = [
  FaqItem(
    question: 'How does Uptizm detect incidents?',
    answer:
        'Uptizm runs synthetic checks from multiple regions and watches your '
        'custom metrics. When checks fail or a metric breaches its band, it '
        "opens an incident, automatically or for your approval, depending on "
        "your team's AI mode.",
  ),
  FaqItem(
    question: 'Where do alerts get delivered?',
    answer:
        'Team-level channels (email, SMS, Slack, Microsoft Teams, webhook) '
        "are set under your team's notification channels. Personal delivery "
        '(in-app, web push) is in your account notifications.',
  ),
  FaqItem(
    question: 'Can the AI act on my infrastructure?',
    answer:
        'No. Uptizm observes from the outside and only reasons from its own '
        'checks and your metrics. It suggests where to look and can manage '
        'the incident, but it never touches your systems.',
  ),
];

// ---------------------------------------------------------------------------
// Legal
// ---------------------------------------------------------------------------

/// A single heading/body section within a legal document.
@immutable
class LegalSection {
  /// Section heading.
  final String heading;

  /// Section body text.
  final String body;

  const LegalSection({required this.heading, required this.body});
}

/// Privacy Policy sections. Mirrors the React `PrivacySettingsPage` content.
const List<LegalSection> privacySections = [
  LegalSection(
    heading: 'Information we collect',
    body:
        'Account details you provide (name, email), the monitors and '
        'metrics you configure, and the check results Uptizm gathers on '
        "your behalf. We don't collect data from inside your own systems.",
  ),
  LegalSection(
    heading: 'How we use it',
    body:
        'To run your monitors, detect and analyze incidents, deliver '
        'notifications through your chosen channels, and render your '
        'status pages. We never sell your data.',
  ),
  LegalSection(
    heading: 'Sharing',
    body:
        'We share only with the integrations you connect (e.g. Slack, your '
        'webhook endpoint) and the infrastructure providers that run the '
        'service. Status page subscribers receive only what you publish.',
  ),
  LegalSection(
    heading: 'Data retention',
    body:
        "Check history and metrics are retained for your plan's window, "
        'then aggregated or deleted. You can delete a team at any time, '
        'which removes its monitors, incidents, and status pages.',
  ),
  LegalSection(
    heading: 'Your rights',
    body:
        'You can access, export, or delete your personal data from your '
        'account settings, or by contacting us. We honor regional privacy '
        'rights including GDPR and CCPA.',
  ),
  LegalSection(
    heading: 'Contact',
    body: 'Questions about privacy? Reach us at privacy@uptizm.com.',
  ),
];

/// Terms of Service sections. Mirrors the React `TermsSettingsPage` content.
const List<LegalSection> termsSections = [
  LegalSection(
    heading: 'Acceptance',
    body:
        'By creating a team or using Uptizm, you agree to these terms. If '
        "you're using Uptizm for an organization, you confirm you're "
        'authorized to accept on its behalf.',
  ),
  LegalSection(
    heading: 'Using the service',
    body:
        "Use Uptizm to monitor endpoints you own or are permitted to check. "
        "Don't use it to attack, overload, or probe systems without "
        'authorization.',
  ),
  LegalSection(
    heading: 'Your data',
    body:
        'You own the monitors, metrics, and content you create. You grant '
        'us the rights needed to operate the service on your behalf, as '
        'described in the Privacy Policy.',
  ),
  LegalSection(
    heading: 'Availability',
    body:
        "We work hard to keep Uptizm reliable but provide it 'as is'. "
        'Planned maintenance is posted in advance on our own status page.',
  ),
  LegalSection(
    heading: 'Liability',
    body:
        "To the extent permitted by law, Uptizm isn't liable for indirect "
        'or consequential damages. Monitoring is a safety net, not a '
        'guarantee against downtime.',
  ),
  LegalSection(
    heading: 'Changes',
    body:
        'We may update these terms; material changes are announced in-app '
        'and via the changelog. Continued use means you accept the '
        'updated terms.',
  ),
];
