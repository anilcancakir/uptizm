/// Why an AI surface answered from a deterministic baseline instead of the
/// model, mirroring the backend `App\Enums\AiDegradeReason`.
enum AiDegradeReason {
  /// The team's daily AI budget was already spent, so the model was never
  /// called.
  budgetExhausted,

  /// The model answered, but its output did not conform past the retry.
  outputUntrusted,

  /// The provider did not answer: an outage, a timeout, a missing key, or an
  /// error body delivered in-band on an HTTP 200.
  serviceUnreachable,
}

/// Decodes the backend `degrade_reason` wire value into an [AiDegradeReason].
///
/// Diverges from the other `*FromWire` helpers in two ways, both deliberate.
///
/// A `null` input decodes to `null` rather than to a fallback case, because
/// nothing degraded is a REAL state and the key is always present on the wire:
/// answering with a reason there would put a failure notice on a screen whose
/// analysis came straight from the model.
///
/// An UNRECOGNISED non-null value falls back to [AiDegradeReason.serviceUnreachable],
/// the most conservative reading of "the backend told us something went wrong
/// and we do not know what", so a client older than the backend still says that
/// the answer is a baseline instead of silently presenting it as the model's.
///
/// The values are matched EXPLICITLY rather than against `.name`: the backend
/// enum is snake_case (`'budget_exhausted'`) and Dart is camelCase, so a `.name`
/// comparison would miss every case and quietly return the fallback for all
/// three.
AiDegradeReason? aiDegradeReasonFromWire(String? raw) {
  if (raw == null) return null;

  return switch (raw) {
    'budget_exhausted' => AiDegradeReason.budgetExhausted,
    'output_untrusted' => AiDegradeReason.outputUntrusted,
    'service_unreachable' => AiDegradeReason.serviceUnreachable,
    _ => AiDegradeReason.serviceUnreachable,
  };
}
