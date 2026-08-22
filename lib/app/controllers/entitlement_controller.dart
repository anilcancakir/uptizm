import 'package:magic/magic.dart';
import 'package:magic_payments/magic_payments.dart'
    show
        BillingProvider,
        BillingService,
        ManageVia,
        Payments,
        UsageStat;
import 'package:magic_starter/magic_starter.dart';

import '../enums/ai_level.dart' show AiLevel;
import '../support/billing_types.dart' show Plan, PlanLimits;

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
/// The same `GET /billing` read also carries the rail-neutral subscription
/// facts ([provider], [manageVia], [renews], [currentPeriodEnd]), republished
/// here so a billing surface reads them off this controller instead of issuing
/// a second entitlement fetch. They describe the subscription; they gate
/// nothing.
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
class EntitlementController extends MagicController
    implements SessionScopedController {
  /// Creates the controller. [billing] is injectable for tests; production
  /// resolves the platform [BillingService] through [Payments], so a test can
  /// also swap the whole role with `Payments.extend` instead of threading a
  /// parameter through every caller.
  EntitlementController({BillingService? billing})
    : _billing = billing ?? Payments.billing;

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
    regions: null,
    ai: AiLevel.custom,
    whiteLabel: true,
    privatePages: true,
    sso: true,
  );

  String? _planId;
  List<Plan> _plans = const [];
  List<UsageStat> _usage = const [];
  bool _loaded = false;

  /// Metered AI monitor setups left per `GET /billing`, or null when the tier
  /// entitles AI analysis outright. Null before the first load too, so a view
  /// must treat null as "no allowance to display" rather than "none left".
  int? _aiAnalysisTrialsRemaining;

  /// The rail-neutral subscription facts from the same `GET /billing` read as
  /// [_planId]: who bills the team, where the subscription is managed, whether
  /// it rolls over, and until when.
  ///
  /// The two enums start on their `none` case (the same landing place their
  /// `*FromWire()` decoders use for a word this build cannot name) and the two
  /// nullables start null, so the pre-load state states no rail rather than
  /// guessing one. None of the four gates anything; a view pairs them with
  /// [isLoaded] before treating `none` as an answer.
  BillingProvider _provider = BillingProvider.none;
  ManageVia _manageVia = ManageVia.none;
  bool? _renews;
  DateTime? _currentPeriodEnd;

  /// Guards the one-shot initial load kicked off by [instance].
  bool _loadStarted = false;

  /// Kicks off the initial [reload] the first time the singleton is resolved.
  ///
  /// [EntitlementController] is never a [MagicView]'s backing controller (views
  /// consult it as a secondary via [instance]), so magic's `onInit` lifecycle
  /// hook, which fires only for a view's backing controller, never runs for it.
  /// The load is therefore triggered here on first access instead.
  ///
  /// Returns the in-flight load so [resetForSession] can await the refetch it
  /// re-arms the guard for; [instance] fires it and does not wait.
  Future<void> _ensureLoading() {
    if (_loadStarted) return Future<void>.value();

    _loadStarted = true;
    return reload();
  }

  /// Drops the previous session's plan, catalog, usage, AI allowance, and
  /// subscription rail, publishes the cleared (permissive) state, then refetches
  /// the entitlement of the identity that is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): each [reload]
  /// leg keeps its last-known-good value on failure, so a failed refetch across
  /// an identity change would otherwise gate the new team by the previous
  /// team's plan, and offer it the management surface of the previous team's
  /// purchase. The cleared state is the permissive [_loadingLimits] one, so
  /// nothing is wrongly locked while the new plan is in flight. [_loadStarted]
  /// is re-armed as part of the reset and immediately claimed again by
  /// [_ensureLoading], so the awaited refetch IS the new session's one-shot
  /// load and a later [instance] read does not fire a second one.
  ///
  /// [_billing] is an injected collaborator, not session data, and is left
  /// untouched.
  @override
  Future<void> resetForSession() async {
    _planId = null;
    _plans = const [];
    _usage = const [];
    _loaded = false;
    _aiAnalysisTrialsRemaining = null;
    _provider = BillingProvider.none;
    _manageVia = ManageVia.none;
    _renews = null;
    _currentPeriodEnd = null;
    _loadStarted = false;
    refreshUI();

    await _ensureLoading();
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
      _aiAnalysisTrialsRemaining = entitlement.aiAnalysisTrialsRemaining;
      // Published unconditionally, unlike [_planId]: `none` and null are real
      // readings here (a team whose subscription ended IS billed by nobody), so
      // keeping the previous value on a successful read would strand the view
      // on a rail the backend has stopped reporting.
      _provider = entitlement.provider;
      _manageVia = entitlement.manageVia;
      _renews = entitlement.renews;
      _currentPeriodEnd = entitlement.currentPeriodEnd;
    } catch (error) {
      // Deliberate degradation: a transport failure leaves the last-known plan
      // and rail (or the permissive pre-load default) so gating never crashes a
      // view. Every assignment above sits inside the try for that reason: a
      // partial write would publish half of one read next to half of another.
      Log.error('[EntitlementController._reloadEntitlement] $error');
    }
  }

  /// Reads the catalogue and decodes uptizm's own [Plan] from it.
  ///
  /// The contract answers rows verbatim: a tier's prices, feature bullets and
  /// in-product caps are what uptizm sells rather than anything a payment rail
  /// understands, so [Plan], [PlanLimits] and [AiLevel] stay on this side and the
  /// decode happens at the call site that wants them.
  Future<void> _reloadPlans() async {
    try {
      final List<Map<String, dynamic>> rows = await _billing.getPlans();
      _plans = rows.map(Plan.fromMap).toList();
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
  // Subscription rail (from GET /billing, alongside the plan id)
  // ---------------------------------------------------------------------------

  /// Which rail granted the current entitlement, or [BillingProvider.none]
  /// before the first load resolves and for a team no rail has ever charged.
  ///
  /// Published as the enum itself rather than as a derived convenience (an
  /// `isStoreRail`, say): the enum already answers the question, and a second
  /// definition of one predicate is how two answers start disagreeing. A view
  /// that needs the store/card distinction matches the cases it cares about.
  BillingProvider get provider => _provider;

  /// Where the customer manages this subscription, as the SERVER computed it
  /// from the rail, or [ManageVia.none] before the first load resolves.
  ///
  /// A function of the rail and not of the running platform: a subscription
  /// bought on an iPhone stays managed in the App Store when the customer opens
  /// the web app, so a view branches on this and never on `kIsWeb`. The
  /// destination that pairs with a store surface arrives as the entitlement's
  /// `manageUrl`, which this controller does not republish.
  ManageVia get manageVia => _manageVia;

  /// Whether the subscription rolls over at [currentPeriodEnd], or `null` when
  /// no rail has said (including before the first load).
  ///
  /// Null is not `false`: "nobody reported a renewal" and "this will not renew"
  /// are different sentences, and only the second one may tell a customer their
  /// plan is ending.
  bool? get renews => _renews;

  /// When the current paid period ends, whether or not it renews, or `null`
  /// when no rail has reported one (including before the first load).
  DateTime? get currentPeriodEnd => _currentPeriodEnd;

  // ---------------------------------------------------------------------------
  // Usage (from GET /billing/usage, every resource the producer reports)
  // ---------------------------------------------------------------------------

  /// Current usage of the resource carried under the wire [key]
  /// (`GET /billing/usage`'s own field names), or 0 when the usage read has not
  /// landed yet.
  ///
  /// Keyed on [UsageStat.key] and never on [UsageStat.label], and the label is
  /// in fact null on every stat this controller holds: display copy is paired on
  /// by the screen that renders it (`withUsageCopy`), so it moves with the
  /// language. Matching a gate on it found nothing in any non-English session,
  /// every gate below read zero usage, and a team at its cap kept creating
  /// monitors until the backend refused.
  int _usedFor(String key) {
    for (final stat in _usage) {
      if (stat.key == key) return stat.used;
    }
    return 0;
  }

  /// Monitors currently used by the team.
  int get monitorsUsed => _usedFor('monitors');

  /// Responders (distinct members) currently used by the team.
  int get respondersUsed => _usedFor('responders');

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

  /// The plan's maximum probe regions selectable per monitor, or null when
  /// unlimited. Mirrors the backend's `PlanGate::maxRegionsPerMonitor()`.
  int? get maxRegionsPerMonitor => currentLimits.regions;

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

  /// Metered AI monitor setups the team has left, or `null` when the tier
  /// entitles AI analysis outright (and before the first entitlement load).
  ///
  /// Republished by [reload] from `GET /billing`; a client that just spent one
  /// can also pass the fresher count from the analyze response through
  /// [noteAiAnalysisTrialsRemaining] instead of re-reading the entitlement.
  int? get aiAnalysisTrialsRemaining => _aiAnalysisTrialsRemaining;

  /// Records the allowance an analyze response reported, so the counter the
  /// user sees counts down without a second entitlement read.
  void noteAiAnalysisTrialsRemaining(int? remaining) {
    if (_aiAnalysisTrialsRemaining == remaining) return;

    _aiAnalysisTrialsRemaining = remaining;
    refreshUI();
  }

  /// The catalog id of the cheapest plan whose limits satisfy [pred]
  /// (`pro`, `business`, `enterprise`), for an upgrade action that has to name
  /// the tier to the billing endpoints rather than to the user.
  ///
  /// The id counterpart of [planNameUnlocking], resolved from the same
  /// cheapest-first walk so the label a nudge shows and the tier its Upgrade
  /// button buys can never disagree. Empty until the catalog loads or when no
  /// tier satisfies [pred]; an empty id means "no upgrade target", so a caller
  /// must not route on it.
  String planIdUnlocking(bool Function(PlanLimits limits) pred) {
    for (final plan in _plans) {
      if (pred(plan.limits)) return plan.id;
    }
    return '';
  }
}
