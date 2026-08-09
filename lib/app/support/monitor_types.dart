import 'package:flutter/foundation.dart';

import '../enums/status_key.dart' show StatusKey, statusKeyFromWire;
import 'formatters.dart' show formatTimeOfDay;

/// A single segment of the 90-day uptime history bar.
///
/// Each segment carries the health [status] at that point in time and a
/// human-readable [label] (e.g. `"7d ago"`) for tooltips.
@immutable
class UptimeSegment {
  /// Health state for this day, or `null` when no check ran that day (a
  /// no-data gap the bar renders as a neutral segment rather than green).
  final StatusKey? status;

  /// Tooltip label, e.g. `"7d ago"`.
  final String label;

  const UptimeSegment({required this.status, required this.label});
}

/// A single row in the recent-checks table.
///
/// Represents the outcome of one probe from a specific region.
@immutable
class CheckRow {
  /// Time of the check, e.g. `"14:32:05"`.
  final String time;

  /// Region identifier, e.g. `"us-east"`.
  final String region;

  /// Result status of this individual check.
  final StatusKey status;

  /// Response time in milliseconds. `null` when the probe timed out or failed
  /// before receiving a response.
  final int? responseMs;

  /// HTTP status code returned by the probe target.
  final int? statusCode;

  const CheckRow({
    required this.time,
    required this.region,
    required this.status,
    this.responseMs,
    this.statusCode,
  });

  /// Builds a [CheckRow] from a `MonitorCheckResource` payload (backend
  /// `api/v1` snake_case keys).
  ///
  /// `checked_at` is an ISO-8601 timestamp; it is reduced to the wall-clock
  /// `HH:mm:ss` string the table renders. An unparsable or missing timestamp
  /// falls back to `'—'` rather than throwing.
  factory CheckRow.fromMap(Map<String, dynamic> map) {
    return CheckRow(
      time: formatTimeOfDay(map['checked_at'] as String?),
      region: (map['region'] as String?) ?? '',
      status: statusKeyFromWire(map['status'] as String?),
      responseMs: (map['response_ms'] as num?)?.toInt(),
      statusCode: (map['status_code'] as num?)?.toInt(),
    );
  }
}

// ---------------------------------------------------------------------------
// The asynchronous AI analyze run.
//
// `POST /monitors/analyze` answers 202 and a worker does the model calls, so a
// run has a lifecycle the client has to render rather than a body it can
// decode in one go. These four types are the client half of that wire
// contract; `MonitorController.analyze` / `fetchAnalyzeRun` /
// `noteAnalyzeProgress` are the only things that build them.
// ---------------------------------------------------------------------------

/// The lifecycle of one `POST /monitors/analyze` run.
///
/// Mirrors the backend `AnalyzeRunStatus` enum, which is the vocabulary of both
/// the 202 body and `GET /monitors/analyze/{run}`. Decode through
/// [analyzeRunStatusFromWire] so a backend that ships a sixth case does not
/// crash an older client.
enum AnalyzeRunStatus {
  /// Accepted and queued. The relay probe already ran (it happens inside the
  /// accepting request), but no model call has started.
  queued,

  /// A worker is running the model calls and reporting step ticks.
  analyzing,

  /// Finished, with a result the create form can prefill from.
  completed,

  /// Ended without a result. See [AnalyzeRunProgress.failure] for why.
  failed;

  /// Whether nothing further will ever be reported for this run.
  ///
  /// What stops the poll. A status that is not terminal means "still working",
  /// and the ONLY other thing that ends a run client-side is the poll budget
  /// in [MonitorController.fetchAnalyzeRun]'s caller.
  bool get isTerminal =>
      this == AnalyzeRunStatus.completed || this == AnalyzeRunStatus.failed;
}

/// Decodes a run status from `data.status`.
///
/// Two different fallbacks, deliberately:
/// - ABSENT (`null`) resolves to [AnalyzeRunStatus.queued], matching the
///   backend's own default in `MonitorController::runPayload()`.
/// - UNKNOWN (a string this client has no case for) resolves to
///   [AnalyzeRunStatus.analyzing], i.e. "still working". A value this client
///   does not know is far more likely to be a new INTERMEDIATE state than a
///   new terminal one, and reading it as terminal would abandon a run that is
///   about to answer. The poll budget bounds the wrong guess either way, so
///   this cannot become an eternal spinner.
AnalyzeRunStatus analyzeRunStatusFromWire(String? wire) {
  return switch (wire) {
    null => AnalyzeRunStatus.queued,
    'queued' => AnalyzeRunStatus.queued,
    'analyzing' => AnalyzeRunStatus.analyzing,
    'completed' => AnalyzeRunStatus.completed,
    'failed' => AnalyzeRunStatus.failed,
    _ => AnalyzeRunStatus.analyzing,
  };
}

/// One analyze step's state, and every case is TERMINAL.
///
/// There is deliberately no `running`: the backend never writes one (see the
/// analyze job's `tick()`), and the client derives the step in flight as the
/// one after the last terminal tick ([AnalyzeRunProgress.inFlightStep]). That
/// asymmetry is the whole point, and it is what makes a spinner that never
/// stops structurally impossible instead of merely defended against: a step
/// that never reports cannot leave a row claiming to be working, because no row
/// ever claims that on its own behalf.
enum AnalyzeStepState {
  /// The step ran and finished.
  done,

  /// The step genuinely did not run this time (no request body to digest, no
  /// response time to detect against, no budget left for the suggestion).
  skipped,

  /// The step raised and ended the run.
  failed,
}

/// Decodes one step's state from a `data.steps` entry or a broadcast `state`.
///
/// Returns `null` for anything that is not terminal, INCLUDING the backend's
/// `running` constant: the store defines it, nothing writes it, and a client
/// that recorded it would have to invent a rule for un-recording it. A null
/// simply leaves that ordinal unrecorded, which is exactly what
/// [AnalyzeRunProgress.inFlightStep] already reads as "in flight".
AnalyzeStepState? analyzeStepStateFromWire(String? wire) {
  return switch (wire) {
    'done' => AnalyzeStepState.done,
    'skipped' => AnalyzeStepState.skipped,
    'failed' => AnalyzeStepState.failed,
    _ => null,
  };
}

/// Why an analyze run ended without a result.
///
/// [errored] and [stopped] are the backend's closed `data.reason` vocabulary
/// (never an exception message). [lost] and [timedOut] have no wire
/// counterpart: they are the two ways the CLIENT concludes a run is dead
/// without the backend ever saying so.
enum AnalyzeFailure {
  /// Something raised inside the worker.
  errored,

  /// The run was failed with no exception at all (a killed worker, a manual
  /// fail), so there is nothing to report beyond "it stopped".
  stopped,

  /// `GET /monitors/analyze/{run}` answered 404: the run's cache entry is
  /// gone, either evicted (Redis runs `volatile-lru` at a 512 MB ceiling) or
  /// expired past its 900-second TTL.
  ///
  /// A REAL failure state and never "not yet": the entry will never come back,
  /// so the only honest thing to tell the operator is to run it again.
  lost,

  /// The poll budget ran out with the run still not terminal. Either the job
  /// never reached a worker or the worker died without its `failed()` hook
  /// running.
  timedOut,
}

/// One analyze run's progress, as the create view renders it.
///
/// Decoded from the `data` block that `POST /monitors/analyze` (202) and
/// `GET /monitors/analyze/{run}` both return in the SAME shape, then folded
/// forward by [withTick] as broadcast ticks arrive.
///
/// [step] is the ordinal the backend last REPORTED on, which is not the ordinal
/// currently working; read [inFlightStep] for that.
@immutable
class AnalyzeRunProgress {
  /// The run this progress belongs to. The only thing that authorises a
  /// broadcast tick to touch it (the progress channel is team-wide, so a
  /// teammate's ticks arrive here too).
  final String runId;

  /// Where the run is in its lifecycle.
  final AnalyzeRunStatus status;

  /// The ordinal the backend last reported on, `0` before the first tick.
  final int step;

  /// The terminal state of every step that has reported, keyed by 1-based
  /// ordinal. A step that has not reported is simply absent.
  final Map<int, AnalyzeStepState> stepStates;

  /// Why the run failed, or `null` when it has not failed (or has failed and
  /// the reason has not been read back yet, which a broadcast-first failure
  /// looks like for the one poll it takes to fetch the reason).
  final AnalyzeFailure? failure;

  /// Creates an [AnalyzeRunProgress].
  const AnalyzeRunProgress({
    required this.runId,
    required this.status,
    this.step = 0,
    this.stepStates = const {},
    this.failure,
  });

  /// Decodes the `data` block of the 202 body or of the poll response.
  ///
  /// `data.steps` arrives in TWO json shapes for one PHP value and both have to
  /// decode: an empty run's `[]` (PHP encodes an empty array as a json array)
  /// and a started run's `{"1":"done","2":"skipped"}` (a PHP array keyed from 1
  /// encodes as an object). A list is read as "nothing has reported yet"; only
  /// the map shape can carry ordinals.
  factory AnalyzeRunProgress.fromMap(Map<String, dynamic> map) {
    final AnalyzeRunStatus status = analyzeRunStatusFromWire(
      map['status'] as String?,
    );

    return AnalyzeRunProgress(
      runId: map['run_id'] as String? ?? '',
      status: status,
      step: (map['step'] as num?)?.toInt() ?? 0,
      stepStates: _decodeStepStates(map['steps']),
      failure: status == AnalyzeRunStatus.failed
          ? _failureFromReason(map['reason'] as String?)
          : null,
    );
  }

  /// This progress with one broadcast tick folded in.
  ///
  /// [state] is null for a tick this client does not record (see
  /// [analyzeStepStateFromWire]). The ordinal is still taken, so the row the view
  /// shows as in flight moves forward; the earlier steps are NOT back-filled as
  /// done, and this comment used to imply they were. Nothing needs them to be:
  /// [inFlightStep] is derived from the ordinal alone, and the poll that follows
  /// a tick rewrites the whole state map from the store anyway, which is where a
  /// missed step's real state comes from.
  ///
  /// [failure] is deliberately NOT derived here. A broadcast carries no reason
  /// (the payload is bounded by Reverb's 10,000-byte inbound ceiling), and
  /// guessing `errored` would name a cause the run may not have had; the
  /// immediate refetch a terminal tick triggers supplies the real one.
  AnalyzeRunProgress withTick({
    required int step,
    required AnalyzeStepState? state,
    required AnalyzeRunStatus status,
  }) {
    return AnalyzeRunProgress(
      runId: runId,
      status: status,
      step: step,
      stepStates: {
        ...stepStates,
        // Null-aware entry: a tick this client does not record leaves the
        // ordinal unrecorded rather than writing a state it had to invent.
        step: ?state,
      },
      failure: status == AnalyzeRunStatus.failed ? failure : null,
    );
  }

  /// This progress recast as a client-side failure, for the two causes the
  /// backend never reports ([AnalyzeFailure.lost], [AnalyzeFailure.timedOut]).
  AnalyzeRunProgress asFailure(AnalyzeFailure failure) {
    return AnalyzeRunProgress(
      runId: runId,
      status: AnalyzeRunStatus.failed,
      step: step,
      stepStates: stepStates,
      failure: failure,
    );
  }

  /// The 1-based ordinal of the step currently working, or `null` when none is
  /// (the run is terminal).
  ///
  /// Derived, not reported: ticks are terminal only, so the step in flight is
  /// the one after the highest ordinal that has reported. A run that has
  /// reported nothing is working on step 1.
  int? get inFlightStep {
    if (status.isTerminal) return null;

    int lastReported = 0;
    for (final int ordinal in stepStates.keys) {
      if (ordinal > lastReported) lastReported = ordinal;
    }

    return lastReported + 1;
  }

  /// The terminal state of the step at [ordinal], or `null` when it has not
  /// reported (it is pending, or it is the one in flight).
  AnalyzeStepState? stateOf(int ordinal) => stepStates[ordinal];

  /// Decodes the `steps` value into ordinal-keyed terminal states.
  ///
  /// Json object keys are strings even for an integer-keyed PHP array, so each
  /// key is parsed back to its ordinal; a key that is not an integer, or a
  /// state that is not terminal, is dropped rather than guessed at.
  static Map<int, AnalyzeStepState> _decodeStepStates(Object? raw) {
    if (raw is! Map) return const {};

    final Map<int, AnalyzeStepState> decoded = {};
    for (final MapEntry<Object?, Object?> entry in raw.entries) {
      final int? ordinal = int.tryParse('${entry.key}');
      final AnalyzeStepState? state = analyzeStepStateFromWire(
        entry.value as String?,
      );
      if (ordinal == null || state == null) continue;

      decoded[ordinal] = state;
    }

    return decoded;
  }

  /// Maps `data.reason` onto [AnalyzeFailure].
  ///
  /// An absent or unrecognised reason resolves to [AnalyzeFailure.errored]:
  /// the run IS failed either way (the status said so), and `errored` is the
  /// reading that does not claim the more specific "nothing raised".
  static AnalyzeFailure _failureFromReason(String? reason) {
    return switch (reason) {
      'stopped' => AnalyzeFailure.stopped,
      _ => AnalyzeFailure.errored,
    };
  }
}

/// A selectable probe region shown in the monitor form.
@immutable
class ProbeRegion {
  /// Machine identifier used in API payloads.
  final String value;

  /// Human-readable display name.
  final String label;

  /// Flag emoji for visual disambiguation in pickers.
  final String flag;

  const ProbeRegion({
    required this.value,
    required this.label,
    required this.flag,
  });
}
