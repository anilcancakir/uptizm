/// Actor that authored a timeline entry.
enum TimelineActor {
  /// A human operator or on-call engineer.
  human,

  /// Uptizm AI.
  ai,

  /// The Uptizm platform itself (threshold triggers, auto-resolution).
  system,
}

/// Decodes the backend `actor` wire value into a [TimelineActor], falling
/// back to [TimelineActor.system] on an unknown value.
TimelineActor timelineActorFromWire(String? raw) {
  if (raw == null) return TimelineActor.system;
  return TimelineActor.values.firstWhere(
    (v) => v.name == raw,
    orElse: () => TimelineActor.system,
  );
}
