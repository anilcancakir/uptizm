import 'package:magic/magic.dart';

import '../enums/ai_level.dart' show AiLevel;
import '../services/billing/billing_service.dart';
import '../support/billing_types.dart' show Plan, PlanLimits;
import '../support/team_types.dart' show UsageStat;

/// App-wide source of the team's REAL billing entitlement, for proactive plan
/// gating across every view.
///
/// Fetches the current plan (`GET /billing`), the plan catalog with its limits
/// (`GET /billing/plans`), and current usage (`GET /billing/usage`) once, then
/// exposes synchronous gate predicates (`canCreateMonitor`,
/// `minCheckIntervalSec`, `aiLevelAllows`, `canUsePrivatePages`,
/// `canAddResponder`, ...) that views consult to disable or nudge an action
/// BEFORE the user hits the backend's hard 422/403.
///
/// Until the fetch resolves the gates are PERMISSIVE (nothing is client-gated):
/// the backend is the true enforcer, so an optimistic pre-load gate never lets
/// a real over-limit write through, and the UI never flashes a wrong "locked"
/// state on a team whose plan has not loaded yet. Each fetch degrades
/// independently, keeping the last-known-good value (logged, never thrown).
///
/// A [ChangeNotifier]; wrap a gated widget in a `ListenableBuilder` on
/// [EntitlementController.instance] so it re-gates the moment the real plan or
/// usage lands.
class EntitlementController extends MagicController {
  /// Creates the controller. [billing] is injectable for tests; production
  /// resolves the platform [BillingService] singleton.
  EntitlementController({BillingService? billing})
    : _billing = billing ?? BillingService.instance;

  final BillingService _billing;

  /// Singleton accessor, registering the controller and kicking off its
  /// one-shot initial load on first access.
  static EntitlementController get instance =>
      Magic.findOrPut(EntitlementController.new).._ensureLoading();

  /// Fully-permissive limits used until the real plan resolves, so no action
  /// is wrongly gated mid-load (the backend still enforces the true caps).
  static const PlanLimits _loadingLimits = PlanLimits(
    monitors: null,
    checkIntervalSec: 0,
    statusPages: null,
    subscribers: null,
    responders: null,
    ai: AiLevel.custom,
    whiteLabel: true,
    privatePages: true,
    sso: true,
  );

  String? _planId;
  List<Plan> _plans = const [];
  List<UsageStat> _usage = const [];
  bool _loaded = false;

  /// Guards the one-shot initial load kicked off by [instance].
  bool _loadStarted = false;

  /// Kicks off the initial [reload] the first time the singleton is resolved.
  ///
  /// [EntitlementController] is never a [MagicView]'s backing controller (views
  /// consult it as a secondary via [instance]), so magic's `onInit` lifecycle
  /// hook, which fires only for a view's backing controller, never runs for it.
  /// The load is therefore triggered here on first access instead.
  void _ensureLoading() {
    if (_loadStarted) return;
    _loadStarted = true;
    reload();
  }

  /// Non-destructive refresh of plan id + catalog + usage in parallel; each
  /// leg keeps its last-known-good value on failure.
  Future<void> reload() async {
    await Future.wait([_reloadEntitlement(), _reloadPlans(), _reloadUsage()]);
    _loaded = true;
    refreshUI();
  }

  Future<void> _reloadEntitlement() async {
    try {
      final entitlement = await _billing.currentEntitlement();
      if (entitlement.plan != null) {
        _planId = entitlement.plan;
      }
    } catch (error) {
      // Deliberate degradation: a transport failure leaves the last-known plan
      // (or the permissive pre-load default) so gating never crashes a view.
      Log.error('[EntitlementController._reloadEntitlement] $error');
    }
  }

  Future<void> _reloadPlans() async {
    try {
      _plans = await _billing.getPlans();
    } catch (error) {
      Log.error('[EntitlementController._reloadPlans] $error');
    }
  }

  Future<void> _reloadUsage() async {
    try {
      _usage = await _billing.getUsage();
    } catch (error) {
      Log.error('[EntitlementController._reloadUsage] $error');
    }
  }

  // ---------------------------------------------------------------------------
  // Resolved plan + limits
  // ---------------------------------------------------------------------------

  /// The entitled plan id, or null until `GET /billing` resolves.
  String? get planId => _planId;

  /// The resolved catalog plan for the current entitlement, or null when the
  /// catalog / plan id has not loaded (callers fall back to permissive gates).
  Plan? get currentPlan {
    final id = _planId;
    if (id == null || _plans.isEmpty) return null;
    for (final plan in _plans) {
      if (plan.id == id) return plan;
    }
    return null;
  }

  /// The current plan's caps, or the permissive [_loadingLimits] until the real
  /// plan resolves.
  PlanLimits get currentLimits => currentPlan?.limits ?? _loadingLimits;

  /// Human plan name (e.g. `"Free"`), empty until resolved.
  String get planName => currentPlan?.name ?? '';

  /// Whether the real entitlement has finished loading at least once.
  bool get isLoaded => _loaded;

  // ---------------------------------------------------------------------------
  // Usage (from GET /billing/usage; fixed order monitors, responders, checks)
  // ---------------------------------------------------------------------------

  int _usedFor(String label) {
    for (final stat in _usage) {
      if (stat.label == label) return stat.used;
    }
    return 0;
  }

  /// Monitors currently used by the team.
  int get monitorsUsed => _usedFor('Monitors');

  /// Responders (distinct members) currently used by the team.
  int get respondersUsed => _usedFor('Responders');

  // ---------------------------------------------------------------------------
  // Gate predicates
  // ---------------------------------------------------------------------------

  /// Whether the team can create another monitor under its plan cap.
  bool get canCreateMonitor {
    final limit = currentLimits.monitors;
    return limit == null || monitorsUsed < limit;
  }

  /// Monitors left before the cap, or null when unlimited.
  int? get monitorsRemaining {
    final limit = currentLimits.monitors;
    if (limit == null) return null;
    final remaining = limit - monitorsUsed;
    return remaining < 0 ? 0 : remaining;
  }

  /// The plan's fastest allowed check interval, in seconds.
  int get minCheckIntervalSec => currentLimits.checkIntervalSec;

  /// Whether the plan unlocks AI capability at or above [level].
  bool aiLevelAllows(AiLevel level) => currentLimits.ai.index >= level.index;

  /// Whether the plan allows private (access-controlled) status pages.
  bool get canUsePrivatePages => currentLimits.privatePages;

  /// Whether the plan allows white-label / branding removal.
  bool get canUseWhiteLabel => currentLimits.whiteLabel;

  /// Whether the team can add (invite) another responder under its cap.
  bool get canAddResponder {
    final limit = currentLimits.responders;
    return limit == null || respondersUsed < limit;
  }

  /// Responders left before the cap, or null when unlimited.
  int? get respondersRemaining {
    final limit = currentLimits.responders;
    if (limit == null) return null;
    final remaining = limit - respondersUsed;
    return remaining < 0 ? 0 : remaining;
  }

  /// The name of the cheapest plan whose limits satisfy [pred], for an
  /// "Available on `<Plan>`" nudge (e.g. the first tier that unlocks private
  /// pages). Walks the loaded [_plans] catalog cheapest-first (the order the
  /// backend serves `GET /billing/plans`), so the named plan tracks the REAL
  /// backend tiers rather than a static fixture. Empty until the catalog loads
  /// or when no tier satisfies [pred].
  String planNameUnlocking(bool Function(PlanLimits limits) pred) {
    for (final plan in _plans) {
      if (pred(plan.limits)) return plan.name;
    }
    return '';
  }
}
