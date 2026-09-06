import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../support/digest_types.dart';

/// The render phase of the weekly digest read.
///
/// Five rather than a nullable digest plus a bool, because the screen has five
/// genuinely different things to say and four of them look alike as an absent
/// digest: still asking, the server has none yet, the read broke, and the
/// team's plan does not include it. A plan refusal in particular is NOT a read
/// failure, and folding it into [error] offered a Retry that could never
/// succeed.
enum DigestPhase {
  /// The read is in flight and nothing has answered yet.
  loading,

  /// A digest resolved and is ready to render.
  ready,

  /// The server answered 404: no digest has been generated for this team yet.
  empty,

  /// The read did not land. Retrying is the right affordance.
  error,

  /// The team's plan does not include the digest. Upgrading is the affordance;
  /// retrying is not.
  gated,
}

/// Controller behind the weekly AI digest screen (`/incidents/digest`).
///
/// Owns the `GET /incidents/digest` read and the phase machine over it, so the
/// view renders and nothing else. The screen used to be a bare
/// [StatefulWidget] holding the digest in widget state and calling [Http]
/// itself, which put it outside every piece of session machinery this app has:
/// [SessionScopedController.onSessionChanged] could not clear it, so a digest
/// is TEAM-SCOPED data that survived a team switch until something happened to
/// refetch it, and no second surface could read last week's numbers without
/// fetching them again.
///
/// The read distinguishes four outcomes deliberately, and [DigestPhase] carries
/// the reason each is its own case.
class DigestController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller on first access.
  static DigestController get instance =>
      Magic.findOrPut(DigestController.new);

  /// The digest currently on screen, or null in every phase but
  /// [DigestPhase.ready].
  WeeklyDigest? _digest;

  /// The current phase of the read.
  DigestPhase _phase = DigestPhase.loading;

  /// The plan wall the read hit, present only in [DigestPhase.gated].
  PlanUpgradeRequirement? _gate;

  /// The loaded digest, or null when none is on screen.
  WeeklyDigest? get digest => _digest;

  /// What the screen should currently render.
  DigestPhase get phase => _phase;

  /// The plan wall to render in [DigestPhase.gated].
  PlanUpgradeRequirement? get gate => _gate;

  /// Bootstraps the read the first time this controller backs the screen.
  @override
  void onInit() {
    super.onInit();
    load();
  }

  /// Fetches the live digest and publishes the phase it resolved to.
  ///
  /// A 404 is [DigestPhase.empty] rather than an error: the server saying it
  /// has not generated one is an answer. Any other non-2xx carrying a plan
  /// refusal is [DigestPhase.gated]; anything else, including a thrown
  /// transport error, is [DigestPhase.error], so a read that did not land is
  /// never rendered as a team with no digest.
  Future<void> load() async {
    _phase = DigestPhase.loading;
    _gate = null;
    refreshUI();

    try {
      final MagicResponse response = await Http.get('/incidents/digest');
      final Object? payload = response.data;
      final Object? data = payload is Map<String, dynamic>
          ? payload['data']
          : null;

      if (response.successful && data is Map<String, dynamic>) {
        _digest = WeeklyDigest.fromMap(data);
        _phase = DigestPhase.ready;
      } else if (response.statusCode == 404) {
        _digest = null;
        _phase = DigestPhase.empty;
      } else {
        _digest = null;
        _gate = PlanUpgradeRequirement.fromResponse(response);
        _phase = _gate != null ? DigestPhase.gated : DigestPhase.error;
      }
    } catch (e, stackTrace) {
      // Logged, not swallowed: the phase below is what the screen renders, and
      // this line is what says why when someone asks.
      Log.error('[DigestController.load] $e\n$stackTrace');
      _digest = null;
      _phase = DigestPhase.error;
    }

    refreshUI();
  }

  @override
  Future<void> resetForSession() async {
    // A digest describes ONE team's week. Cleared before the refetch, because
    // [load] publishes `loading` first and a failed refetch must not leave the
    // outgoing team's numbers on the incoming team's screen.
    _digest = null;
    _gate = null;
    _phase = DigestPhase.loading;
    refreshUI();

    await load();
  }

  /// Seeds the phase and digest directly, for widget tests that render the
  /// screen without a network.
  @visibleForTesting
  void seedForTest({
    required DigestPhase phase,
    WeeklyDigest? digest,
    PlanUpgradeRequirement? gate,
  }) {
    _phase = phase;
    _digest = digest;
    _gate = gate;
    refreshUI();
  }
}
