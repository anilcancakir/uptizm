import 'package:flutter/foundation.dart';

import '../enums/status_key.dart' show StatusKey;
import 'formatters.dart' show formatRelativeAge;
import 'monitor_types.dart' show UptimeSegment;

/// A monitor resolved to a public component (name + current health + history).
///
/// Reuses [StatusKey] and the existing [UptimeSegment] from `monitor_types.dart`
/// so [segments] unifies with the monitors-layer type consumed by later status
/// components. Mirrors the `PublicComponent` interface in the React status mock.
@immutable
class PublicComponent {
  /// Public display name of the component.
  final String name;

  /// Current health status.
  final StatusKey status;

  /// Human-formatted trailing uptime string, e.g. `"99.94% uptime"`.
  final String uptime;

  /// 90-day uptime history bar segments.
  final List<UptimeSegment> segments;

  const PublicComponent({
    required this.name,
    required this.status,
    required this.uptime,
    required this.segments,
  });
}

/// A subscriber to a status page's email updates.
///
/// Mirrors the `Subscriber` interface in the React status mock. [id] and
/// [confirmed] were added when the subscriber roster went live against
/// `StatusPageSubscriberResource`; both default so the design-lab fixtures in
/// `mocks/status_pages.dart` (which predate the live endpoint and carry
/// neither field) keep compiling unchanged.
@immutable
class Subscriber {
  /// Backend identifier, empty for a fixture-only instance never persisted.
  final String id;

  /// Subscriber email address.
  final String email;

  /// Relative time string of when they subscribed, e.g. `"3 days ago"`.
  final String subscribedAt;

  /// Whether the subscription is confirmed (`confirmed_at != null` on the
  /// backend). Direct-added subscribers are confirmed immediately.
  final bool confirmed;

  const Subscriber({
    this.id = '',
    required this.email,
    required this.subscribedAt,
    this.confirmed = false,
  });

  /// Decodes a [Subscriber] from a `StatusPageSubscriberResource` row:
  /// `{id, email, subscribed_at (ISO8601 string), confirmed (bool),
  /// newsletter_opt_in (bool)}`. `newsletter_opt_in` has no client-side
  /// rendering yet, so it is not carried onto the value-object.
  factory Subscriber.fromMap(Map<String, dynamic> map) {
    return Subscriber(
      id: map['id'] as String? ?? '',
      email: map['email'] as String? ?? '',
      subscribedAt: _relativeSubscribedAt(map['subscribed_at'] as String?),
      confirmed: map['confirmed'] as bool? ?? false,
    );
  }
}

/// Formats an ISO-8601 `subscribed_at` timestamp as a localized relative age.
/// Returns `''` when [raw] is `null` or fails to parse.
///
/// Routed through [formatRelativeAge] rather than composed here. This used to
/// return the English literals `'today'`, `'1 day ago'` and `'N days ago'`,
/// which the subscribers screen interpolates into a translated sentence, so a
/// Turkish operator read "3 days ago tarihinde abone oldu". Every
/// English-language assertion in the suite passed, which is why nothing caught
/// it.
String _relativeSubscribedAt(String? raw) {
  if (raw == null) return '';
  final DateTime? parsed = DateTime.tryParse(raw);
  if (parsed == null) return '';

  return formatRelativeAge(parsed.toLocal());
}
