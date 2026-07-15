/// AI confidence level for an incident analysis.
enum AiConfidence { high, medium, low }

/// Decodes the backend `ai.confidence` wire value into an [AiConfidence],
/// falling back to [AiConfidence.low] on an unknown value so an unrecognized
/// confidence string never crashes the inbox and instead reads as the most
/// conservative tier.
AiConfidence aiConfidenceFromWire(String? raw) {
  if (raw == null) return AiConfidence.low;
  return AiConfidence.values.firstWhere(
    (v) => v.name == raw,
    orElse: () => AiConfidence.low,
  );
}
