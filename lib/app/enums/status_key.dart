/// Monitor and component health state vocabulary.
///
/// Single source of truth for every status badge, uptime bar, incident impact,
/// and metric tone in the Uptizm UI. The six values mirror the CSS token
/// families (`--color-up`, `--color-down`, ...) defined in `DESIGN.md`.
///
/// ## Usage
///
/// ```dart
/// final StatusKey status = StatusKey.up;
/// print(status.label); // "Operational"
/// ```
enum StatusKey {
  /// All checks passing; no known issues.
  up,

  /// One or more checks are failing; service is unavailable.
  down,

  /// Service is slower or partially impaired but still responding.
  degraded,

  /// Monitoring paused by the user; no checks are running.
  paused,

  /// Active but awaiting its first probe result; no health verdict yet.
  ///
  /// A freshly created monitor sits here until its first check lands. It reads
  /// as a neutral grey (like [paused]), NOT as [info]/"Maintenance": a monitor
  /// is never administratively in maintenance, so a missing `last_status` must
  /// not borrow that label.
  pending,

  /// Informational maintenance window; no health impact.
  info,

  /// Handled autonomously by Uptizm AI.
  ai;

  /// Human-readable label shown in badges and detail headers.
  String get label => switch (this) {
    StatusKey.up => 'Operational',
    StatusKey.down => 'Major outage',
    StatusKey.degraded => 'Degraded',
    StatusKey.paused => 'Paused',
    StatusKey.pending => 'Pending',
    StatusKey.info => 'Maintenance',
    StatusKey.ai => 'AI',
  };
}

/// Ordered list of all [StatusKey] values; mirrors the canonical TypeScript
/// `STATUS_KEYS` constant. Suitable for preview pickers and export tables.
const List<StatusKey> statusKeys = [
  StatusKey.up,
  StatusKey.down,
  StatusKey.degraded,
  StatusKey.paused,
  StatusKey.pending,
  StatusKey.info,
  StatusKey.ai,
];

/// Decodes a backend status wire value (`"up"`, `"down"`, `"degraded"`,
/// `"paused"`, `"info"`, `"ai"`) into a [StatusKey], falling back to
/// [fallback] when [raw] is `null` or does not match a known value.
///
/// A stale client must never crash on a status value it does not yet know
/// about, so every enum decode in the model layer goes through this helper.
StatusKey statusKeyFromWire(String? raw, {StatusKey fallback = StatusKey.info}) {
  if (raw == null) return fallback;
  return StatusKey.values.firstWhere(
    (key) => key.name == raw,
    orElse: () => fallback,
  );
}
