import '../enums/change_kind.dart' show ChangeKind;
import '../enums/notification_channel.dart' show NotificationChannel;
import '../support/settings_types.dart'
    show
        AppLanguage,
        AppTimezone,
        ChangelogChange,
        ChangelogRelease,
        DeviceSession,
        NotificationPref;

/// **Settings-domain mock fixtures.**
///
/// Ported from the design lab's `src/pages/settings/*.tsx` pages. Backs the
/// account-level Settings vertical (sessions, language, timezone,
/// notifications, changelog, help). The value-object types live in
/// `lib/app/support/settings_types.dart`; this file holds only their fixtures
/// and the tiny accessors over them.
///
/// Timezones are a special case: [timezonesFromApi] is a curated, hardcoded
/// list framed as an API response, not a client-side `Intl` enumeration
/// (the React source uses `Intl.supportedValuesOf('timeZone')`; the Flutter
/// mock deliberately does not, per the ported design decision).

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

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

