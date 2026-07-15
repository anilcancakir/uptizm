import 'package:flutter/foundation.dart';

import '../enums/status_key.dart' show StatusKey;
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
/// Mirrors the `Subscriber` interface in the React status mock.
@immutable
class Subscriber {
  /// Subscriber email address.
  final String email;

  /// Relative time string of when they subscribed, e.g. `"3 days ago"`.
  final String subscribedAt;

  const Subscriber({required this.email, required this.subscribedAt});
}
