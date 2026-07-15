/// Depth of AI capability a tier unlocks, in ascending order.
///
/// - [inbox]: anomaly inbox only (Free tier).
/// - [analysis]: full incident analysis with evidence and drafted updates (Pro).
/// - [auto]: AI Auto mode, weekly digest, and similar-incident matching (Business).
/// - [custom]: custom guardrails and dedicated AI capacity (Enterprise).
enum AiLevel {
  inbox,
  analysis,
  auto,
  custom,
}
