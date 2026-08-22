import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_payments/magic_payments.dart'
    show
        BillingEntitlement,
        BillingException,
        BillingInvoicesPage,
        BillingService,
        Invoice,
        InvoiceStatus,
        ManageVia,
        PaymentMethod,
        Payments,
        StoreBillingService,
        UnsupportedPlatformException,
        UsageStat,
        WebBillingService;
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/billing_types.dart' show Plan;
import '../../../app/support/formatters.dart' show formatCount;
import '../../../app/support/team_types.dart' show withUsageCopy;
import '../../../app/models/team.dart' show Team;
import '../../../app/models/user.dart' show User;
import '../../../ui/components/usage_meter/usage_meter.dart';

/// The billing cycle a plan's price is shown for.
enum BillingCycle {
  /// Full monthly rate (`Plan.monthly`).
  monthly,

  /// Discounted effective-per-month rate when billed annually (`Plan.annual`).
  annual,
}

/// **Plan & billing screen (`/teams/billing`).**
///
/// A faithful Flutter port of the React `PlanBillingPage.tsx`, wired live
/// against the four `api/v1` billing read endpoints: the current plan with
/// live [UsageMeter]s over [BillingService.getUsage], the tier comparison
/// with a monthly/annual [SegmentedControl] cycle toggle over a responsive
/// grid of [BillingService.getPlans] plan cards, the on-file payment method
/// from [BillingService.getPaymentMethod], and the billing history from
/// [BillingService.getInvoices].
///
/// - **Current plan card**: the active [Plan]'s name + a "Current" [Badge],
///   the renewal line, and a two-column [UsageMeter] grid over the fetched
///   usage stats. Renders a loading skeleton until the plan catalog resolves
///   (the active plan is looked up by id inside it).
/// - **Plans section**: a centered heading and a monthly/annual cycle toggle,
///   then a responsive grid of one card per fetched plan, in the
///   cheapest-to-priciest order the backend serves (load-bearing for the
///   upgrade/downgrade CTA). Each card shows the name/tagline, the price for
///   the selected cycle (`"Custom"` when [Plan.monthly] is null), the AI hero
///   line on a soft-tone tile, the feature list with a check glyph, the
///   responder add-on line when present, a "Recommended" badge when
///   [Plan.recommended], and a CTA: "Current plan" (disabled) for the active
///   plan, else "Upgrade"/"Downgrade"/"Contact sales" decided by comparing the
///   plan's position to the current plan's. A priced-tier CTA buys on whichever
///   rail this build serves, a live Stripe Checkout session via
///   [WebBillingService.checkout] or the platform's own purchase sheet via
///   [StoreBillingService.purchase], and renders only where one of them exists
///   (see the third axis below); the custom (Enterprise) tier only surfaces a
///   "contact sales" toast, spends nothing and therefore needs no rail; nothing
///   navigates or persists there. A store build also shows the store's own
///   price statement instead of the catalogue's USD figure, drops the
///   monthly/annual toggle (the store sells the monthly SKUs only) and carries
///   the "Restore purchases" affordance the stores require.
/// - **Payment method card**: the fetched [PaymentMethod]'s brand tile, the
///   masked card number + expiry + renewal date, and an "Update" [Button]
///   that opens the Stripe billing portal via [WebBillingService.openPortal].
///   Fetched independently of the rest of the screen (the only Stripe-live
///   read, soft-failing server-side to an all-null payload), so it carries
///   its own loading/error state and never blocks the rest of the screen. On a
///   store rail this whole section is replaced by the store-managed statement
///   (see below); there is no Stripe card to show.
/// - **Billing history card**: one row per fetched [Invoice]: date + number, a
///   token-tinted [InvoiceStatus] pill (a `WDiv` + `WText` mapping
///   paid -> up-soft, pending -> degraded-soft, failed -> down-soft with `dark:`
///   pairs, NOT [StatusBadge], which takes a monitoring [StatusKey]), the
///   amount, and a "Receipt" [Button] that opens the Stripe billing portal.
///
/// The current-plan id is sourced solely from the live `GET /billing`
/// entitlement (via [BillingService.currentEntitlement]); it stays `null`
/// (genuinely unknown, not seeded from a guess) until that read resolves, and
/// no card claims to be the current plan or an upgrade/downgrade target while
/// it is unresolved. Every other section (plan catalog, usage, payment
/// method, invoices) is fully live; every read degrades to its last-known
/// state (empty before the first successful fetch) on failure instead of
/// throwing out of `initState`.
///
/// Two of the read shapes arrive undecorated, because `magic_payments` carries
/// no display copy: [Invoice.date] and [PaymentMethod.renewalDate] are instants
/// this screen formats ([_PlanBillingViewState._formatDate]), the card expiry is
/// built from the rail's own two numbers, each [UsageStat] is paired with its
/// localised label by `withUsageCopy`, and the [InvoiceStatus] pill resolves its
/// own word beside its own tone.
///
/// ### The three axes that gate every affordance
///
/// **The rail, never the running platform.** Which management surface this
/// screen offers comes from the entitlement's [BillingEntitlement.manageVia],
/// computed server-side from the rail. The two are independent: a subscription
/// bought on an iPhone is still managed in the App Store when its owner opens
/// the web app, so a branch on the RUNNING platform (the web compilation flag,
/// a `dart:io` host check, the framework's target-platform value) would offer
/// the wrong surface to a real customer, and the step that introduced this gate
/// pins that with a grep. [ManageVia.portal] keeps the three Stripe-portal
/// affordances (the payment-method "Update" in both its states, and every
/// invoice "Receipt"); the two store rails replace the payment-method section
/// with a statement naming the store, plus a link to the server-passed
/// [BillingEntitlement.manageUrl] when there is one; [ManageVia.none] offers
/// none of them, because `GET /billing/portal` answers 409
/// `no_billing_account` for exactly the teams the server reports as `none`, so
/// the button would be a dead end rather than an action.
///
/// **The owner, for anything that spends money.** The four billing write
/// routes are the team owner's server-side, so a member sees the plan grid
/// read-only with an owner-only notice instead of a purchase CTA it would take
/// a 403 to discover. The gate is tri-state on purpose (see [_isOwner]): only
/// a KNOWN non-owner loses the CTA, since an unresolved membership must not
/// stand between an owner and paying.
///
/// **The rail this BUILD can serve, for anything it has to call.** The
/// purchase-affecting calls live on [WebBillingService] and
/// [StoreBillingService], each of which `magic_payments` resolves to `null` where
/// the build cannot serve it, and no build serves both (the `dart:io` arm has no
/// hosted checkout, the web arm has no store). That absence gates the checkout
/// CTA, all three portal affordances and the whole store purchase surface, and it
/// is a different question from `manage_via`: one asks where the customer's
/// subscription is managed, the other whether this binary has an implementation
/// to invoke. A web build therefore renders no store purchase at all and a store
/// build renders no checkout button, where it used to render one that threw
/// [UnsupportedPlatformException] when tapped.
///
/// ### One store account funds exactly one team
///
/// The two store SKUs share a subscription group so that upgrade and downgrade
/// work, and a store account holds at most one active subscription per group. So
/// a second purchase from the same account does not open a second subscription:
/// it TRANSFERS the one that exists and silently stops funding the team that had
/// it. Before offering to buy, this screen therefore asks the backend whether
/// another of the caller's teams is already funded that way
/// ([_loadStoreFundedTeam]) and refuses by NAME when one is, because "a store
/// account can fund only one team" is unactionable without saying which team
/// holds it.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/billing', () => const PlanBillingView());
/// ```
@immutable
class PlanBillingView extends StatefulWidget {
  /// Creates the [PlanBillingView].
  ///
  /// [billingService] overrides [Payments.billing] for tests; omit it in
  /// production to get the rail this build resolved. [isOwner] overrides the
  /// resolved team membership, likewise for tests.
  const PlanBillingView({super.key, this.billingService, this.isOwner});

  /// Injectable [BillingService], overriding [Payments.billing].
  ///
  /// A fake that also implements [WebBillingService] supplies the web rail as
  /// well (see [_PlanBillingViewState._resolveWebRail]); one that does not models
  /// a build with no web rail, which is exactly the state where no purchase or
  /// portal affordance may render.
  @visibleForTesting
  final BillingService? billingService;

  /// Injectable team ownership, overriding the resolution in [_isOwner].
  ///
  /// A widget test mounts this screen without an auth container, where
  /// membership is genuinely unresolvable, so the owner gate needs a seam to be
  /// testable at all. `null` (the production value) means "resolve it".
  @visibleForTesting
  final bool? isOwner;

  @override
  State<PlanBillingView> createState() => _PlanBillingViewState();
}

class _PlanBillingViewState extends State<PlanBillingView> {
  /// The cycle-toggle options, in [BillingCycle] order (Monthly, then Annual).
  static const List<BillingCycle> _cycles = <BillingCycle>[
    BillingCycle.monthly,
    BillingCycle.annual,
  ];

  /// The check glyph rendered before each plan feature.
  static const IconData _checkIcon = Icons.check;

  /// The sparkle glyph rendered on the AI hero tile.
  static const IconData _sparkleIcon = Icons.auto_awesome;

  /// The glyph rendered on the store-managed and owner-only statements.
  static const IconData _infoIcon = Icons.info_outline;

  /// The membership role [Team.userRole] carries for a team's owner, as
  /// `TeamResource::resolveUserRole()` emits it.
  static const String _ownerRole = 'owner';

  /// The route the back affordance returns to.
  static const String _backFallback = '/';

  /// The short month names [_formatDate] indexes by `DateTime.month`.
  ///
  /// This table and the US day-then-year order it feeds are DISPLAY COPY, which
  /// is why they live on this side of the package boundary: `magic_payments`
  /// hands both dates over as instants precisely so a published package does not
  /// freeze one language and one date convention into every consumer.
  static const List<String> _monthAbbreviations = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];

  /// The endpoint answering which of the caller's teams a store account already
  /// funds. See [_loadStoreFundedTeam] for why this screen calls it directly.
  static const String _storeFundedTeamPath = '/billing/store-funded-team';

  /// The one key that endpoint answers with: `null`, or an object naming the
  /// team.
  static const String _storeFundedTeamKey = 'store_funded_team';

  /// The app's canonical web origin, used to build the checkout
  /// `success_url`/`cancel_url` Stripe redirects back to.
  ///
  /// A fixed constant rather than `Uri.base` (which is not a valid checkout
  /// redirect target off the web platform, and throws on a non-http(s)
  /// scheme, e.g. under `flutter test`'s `file://` origin).
  static const String _webOrigin = 'https://uptizm.com';

  /// The selected billing cycle. Defaults to annual (mirrors the React
  /// `useState<BillingCycle>("annual")`), so the toggle opens on the second
  /// segment and prices read the discounted annual column. Local display
  /// state only: the backend plan map has no monthly/annual price dimension
  /// yet, so [_selectPlan] never encodes this into the checkout payload (see
  /// the `### Deviations` note on this step).
  BillingCycle _cycle = BillingCycle.annual;

  /// The five entitlement READS, resolved once:
  /// [PlanBillingView.billingService] when injected (tests), else the rail this
  /// build resolved through [Payments.billing]. A read is honourable on every
  /// platform, so this is never null.
  late final BillingService _billing = widget.billingService ?? Payments.billing;

  /// The WEB rail, or `null` in a build that cannot serve one.
  ///
  /// The two purchase-affecting calls this screen makes ([WebBillingService
  /// .checkout] and [WebBillingService.openPortal]) live here rather than on the
  /// read contract, because a rail is not available everywhere. `null` is the
  /// answer every affordance below is gated on: a store build renders no
  /// checkout button at all instead of one that fails when tapped.
  late final WebBillingService? _web = _resolveWebRail();

  /// The STORE rail, or `null` in a build that cannot serve one.
  ///
  /// The mobile purchase path: `magic_payments` answers this only on iOS and
  /// Android, so it is `null` on the web and on desktop. It is the gate on every
  /// store affordance below for the same reason [_web] gates the Stripe ones,
  /// and the two are mutually exclusive in practice, which is what keeps a store
  /// build from offering web checkout and a web build from offering a purchase
  /// the device cannot make.
  late final StoreBillingService? _store = _resolveStoreRail();

  /// Resolves the web rail for this mount.
  ///
  /// With nothing injected it is the build's own rail. With a fake injected it is
  /// that fake WHEN the fake serves the contract, and `null` otherwise: reaching
  /// for [Payments.web] behind an injected read fake would hand a test the real
  /// driver for half its calls.
  WebBillingService? _resolveWebRail() {
    // Typed `Object?` rather than `BillingService?`, and that is load-bearing
    // rather than loose: the two contracts are unrelated (neither extends the
    // other, so one object may serve both), and Dart only promotes a variable
    // to a SUBTYPE of its declared type. A `BillingService?` local therefore
    // never promotes to `WebBillingService` and the test below would need a
    // cast to compile.
    final Object? injected = widget.billingService;
    if (injected == null) return Payments.web;

    return injected is WebBillingService ? injected : null;
  }

  /// Resolves the store rail for this mount, on the same terms as
  /// [_resolveWebRail]: the build's own rail with nothing injected, and an
  /// injected fake only when that fake serves the contract.
  ///
  /// Typed `Object?` for the same reason as there: [BillingService] and
  /// [StoreBillingService] are unrelated contracts, so a `BillingService?` local
  /// would never promote to the subtype and the test below would need a cast.
  StoreBillingService? _resolveStoreRail() {
    final Object? injected = widget.billingService;
    if (injected == null) return Payments.store;

    return injected is StoreBillingService ? injected : null;
  }

  /// The active plan id, or `null` while it is genuinely unknown: before
  /// [_loadEntitlement]'s `GET /billing` read resolves, and permanently on a
  /// failed read (there is no fixture to fall back to; an unresolved current
  /// plan must read as unresolved, never as a guess). Republished by
  /// [_loadEntitlement] once the live read succeeds.
  String? _currentPlanId;

  /// The plan catalog from `GET /billing/plans`, cheapest-to-priciest as
  /// served by the backend. Empty until [_loadPlans] resolves; stays empty
  /// (last-known state) on a fetch failure.
  List<Plan> _plans = const [];

  /// Whether the live entitlement read resolved, so [_currentPlanId] is the
  /// team's real plan rather than the seeded fixture id.
  bool _entitlementLoaded = false;

  /// The management surface the server computed from the rail, or `null` while
  /// no `GET /billing` read has resolved one.
  ///
  /// `null` is not [ManageVia.none]: one means "no rail has answered yet" and
  /// the other means "the rail answered, and there is nowhere to send you".
  /// Every gate below treats the unresolved state permissively, matching
  /// `EntitlementController`'s permissive-until-loaded default: a screen that
  /// hid its own management affordances for the duration of one fetch would
  /// flicker them in, and a slow or failed read would leave a paying customer
  /// with no way to reach their card.
  ManageVia? _manageVia;

  /// The store-management destination the server passed through from the rail,
  /// or `null` when the rail reported none (which is always, on Stripe).
  String? _manageUrl;

  /// The NAME of another team of the caller's that a store account already
  /// funds, or `null` when none does and while the read has not resolved.
  ///
  /// One field rather than a `bool` beside a name, because the two could
  /// disagree: a refusal this screen cannot name is a refusal it cannot explain,
  /// and every team has a name, so "blocked" and "named" are the same state.
  /// `null` is therefore permissive, matching how [_manageVia] treats its own
  /// unresolved state; the backend's TRANSFER handling is what keeps the
  /// entitlement itself honest either way, so this gate exists to stop a
  /// customer being surprised, not to stop the data being wrong.
  String? _storeFundedTeam;

  /// Whether this mount has already acted on a `?upgrade=<plan>` deep link, so
  /// a second resolving fetch cannot reopen checkout.
  bool _upgradeRequestHandled = false;

  /// The upgrade-intent token already acted on, shared across mounts.
  ///
  /// Static because one arrival mounts this screen more than once (see
  /// [_startRequestedUpgrade]); a per-instance flag cannot dedupe across them.
  static String? _consumedUpgradeIntent;

  /// The team's current-cycle usage stats from `GET /billing/usage`. Empty
  /// until [_loadUsage] resolves; the meter grid simply renders no rows
  /// instead of crashing on a fetch failure.
  List<UsageStat> _usage = const [];

  /// The team's billing history from `GET /billing/invoices`. Empty until
  /// [_loadInvoices] resolves; stays empty (last-known state) on failure.
  List<Invoice> _invoices = const [];

  /// The team's on-file payment method from `GET /billing/payment-method`.
  /// `null` until [_loadPaymentMethod] resolves (see [_pmLoading]/[_pmError]
  /// for that section's own loading/error state).
  PaymentMethod? _paymentMethod;

  /// Whether [_loadPaymentMethod] is still in flight. Gates only the payment
  /// method card, never the rest of the screen (it is the lazy Stripe-backed
  /// read).
  bool _pmLoading = true;

  /// Whether [_loadPaymentMethod] failed (network error, non-2xx, or a
  /// malformed payload). The backend itself soft-fails a Stripe outage to an
  /// all-null 200, so this only fires on a transport-level failure.
  bool _pmError = false;

  /// The active plan, resolved from [_currentPlanId]; `null` while [_plans]
  /// is still empty (loading, or the catalog fetch failed) or while
  /// [_currentPlanId] itself has not resolved yet.
  Plan? get _current {
    final String? planId = _currentPlanId;
    if (_plans.isEmpty || planId == null) return null;
    return _findPlan(planId);
  }

  // ---------------------------------------------------------------------------
  // The two gates: the rail, and the owner
  // ---------------------------------------------------------------------------

  /// Whether a store owns this subscription, so the store owns its management
  /// too and nothing here may offer a competing purchase or a Stripe surface.
  bool get _storeManaged =>
      _manageVia == ManageVia.appStore || _manageVia == ManageVia.playStore;

  /// Whether the Stripe billing portal is a surface this caller can reach.
  ///
  /// True while [_manageVia] is unresolved (see its docblock), false on
  /// [ManageVia.none] because the portal endpoint refuses a team with no
  /// billing account, and false on both store rails.
  ///
  /// A known non-owner loses it too: `GET /billing/portal` resolves its team
  /// through the same owner check as the three other write routes, so a
  /// member's "Update" and "Receipt" buttons are 403s waiting to happen rather
  /// than actions. The rail decides WHERE management lives; the membership
  /// decides WHETHER this caller may go there.
  ///
  /// A build with no [_web] rail loses it as well, and that is a separate fact
  /// from the entitlement's: the portal is a call this build has no
  /// implementation for, so the button would have nothing to invoke.
  bool get _portalAvailable =>
      _web != null &&
      (_manageVia == null || _manageVia == ManageVia.portal) &&
      _isOwner != false;

  /// Whether the signed-in user owns the current team, or `null` when that is
  /// genuinely unresolved.
  ///
  /// Tri-state rather than a bool, because the two negative answers lead
  /// somewhere different: a KNOWN non-owner is told the owner handles billing,
  /// while an UNRESOLVED membership must not stand between an owner and paying
  /// us. The auth read is guarded behind the container binding, mirroring
  /// `LocalePromptBanner.shouldShow`: the running app always binds `auth`, but
  /// a widget test mounting this screen without one must not crash on the
  /// probe, and "no auth container" is unresolved rather than "not the owner".
  ///
  /// `user_role` is preferred over the owner-id comparison because it is the
  /// caller's OWN membership as the server resolved it, which is the same
  /// question the server's `BillingPolicy` answers; the `owner_id` comparison
  /// is the fallback for a payload that carried no role.
  bool? get _isOwner {
    final bool? injected = widget.isOwner;
    if (injected != null) return injected;

    if (!Magic.bound('auth') || !Auth.check()) return null;

    final Team? team = User.current.currentTeam;
    if (team == null) return null;

    final String? role = team.userRole;
    if (role != null) return role == _ownerRole;

    return team.ownerId == null ? null : team.isOwner;
  }

  /// Whether this screen may offer to start or change a paid plan through the
  /// WEB rail.
  ///
  /// Three independent refusals: no web rail in this build (there is nothing to
  /// call), a store already charging this team (a second rail must not open a
  /// parallel subscription, which `POST /billing/checkout` also refuses with a
  /// 409), and a member who is not the owner (which the four write routes refuse
  /// with a 403). The last two are affordances rather than enforcement; the
  /// server still decides.
  bool get _canPurchaseViaWeb =>
      _web != null && !_storeManaged && _isOwner != false;

  /// Whether this screen may offer to buy through the STORE rail.
  ///
  /// Four refusals, and the last two are what this rail adds. No store rail in
  /// this build; a member who is not the owner; Stripe already charging this
  /// team, which is the mirror image of the refusal above (whichever rail is
  /// second must not open a parallel subscription, and unlike an upgrade WITHIN
  /// the store's own subscription group that would be a second charge); and
  /// another of the caller's teams already funded by a store account, which a
  /// second purchase would transfer rather than duplicate.
  ///
  /// The two store `manage_via` values are deliberately NOT refusals: the two
  /// SKUs share one subscription group, so buying the other tier there IS the
  /// upgrade path, and the store replaces rather than adds.
  bool get _canPurchaseViaStore =>
      _store != null &&
      _isOwner != false &&
      _manageVia != ManageVia.portal &&
      _storeFundedTeam == null;

  /// Whether this screen may offer to start or change a paid plan on ANY rail.
  ///
  /// The plan grid's CTA gate. No build serves both rails, so this is a union of
  /// two mutually exclusive answers rather than a choice between them; which one
  /// is live decides what a tap does (see [_selectPlan]).
  bool get _canPurchase => _canPurchaseViaWeb || _canPurchaseViaStore;

  @override
  void initState() {
    super.initState();
    _loadEntitlement();
    _loadPlans();
    _loadUsage();
    _loadInvoices();
    _loadPaymentMethod();
    _loadStoreFundedTeam();
  }

  /// Reads whether a store account already funds another of the caller's teams,
  /// and republishes [_storeFundedTeam] with its name when one does.
  ///
  /// Asked only on a build with a store rail, because it exists to gate a store
  /// purchase and a web build has none to gate: a screen with no store
  /// affordance would be spending a request on an answer it cannot use.
  ///
  /// It goes through [Http] rather than through [_billing], and that is a
  /// deliberate exception rather than a shortcut. The question is uptizm's own
  /// (it is about teams, which `magic_payments` knows nothing about), the
  /// package's [BillingService] is a published contract this screen must not
  /// widen for one consumer, and inventing a second app-side billing client
  /// beside the package's would give the screen two sources for one subject.
  ///
  /// Deliberate degradation on failure and on a payload this build cannot read:
  /// [_storeFundedTeam] keeps whatever it held, which for a first read means
  /// `null` and therefore permissive. See its docblock for why permissive is the
  /// right side to fail on here.
  ///
  /// It only ever SETS a name and never clears one, which is a consequence
  /// rather than an oversight: this runs at mount and again before a purchase,
  /// and the second call is only reachable while the answer was already `null`
  /// (a named refusal renders no CTA to tap), so a name-to-null transition has no
  /// trigger on this screen. A future caller that could produce one has to
  /// publish the empty answer too.
  Future<void> _loadStoreFundedTeam() async {
    if (_store == null) return;

    try {
      final MagicResponse response = await Http.get(_storeFundedTeamPath);
      if (!response.successful) return;

      final Object? funded = response[_storeFundedTeamKey];
      if (funded is! Map) return;

      final Object? name = funded['name'];
      if (name is! String || name.isEmpty) return;
      if (!mounted) return;

      setState(() => _storeFundedTeam = name);
    } catch (error) {
      // Degradation stays deliberate (see the docblock above), but it stops
      // being SILENT. This read is the only thing standing between a store
      // purchase and transferring another team's subscription away, it fails
      // permissive, and `_purchaseInStore` awaits it again at the moment money
      // moves. Swallowing it left a 500 on this endpoint letting the transfer
      // through with nothing in any log to explain it afterwards.
      Log.error(
        '[PlanBillingView] $_storeFundedTeamPath failed, so the '
        'cross-team store check is permissive for this mount: $error',
      );
    }
  }

  /// Reads the team's current plan from `GET /billing` via
  /// [BillingService.currentEntitlement] and republishes [_currentPlanId].
  ///
  /// Deliberate degradation on failure (network error, non-2xx, malformed
  /// payload): leaves [_currentPlanId] `null` instead of throwing, mirroring
  /// `MonitorController.reload`'s read-path convention, so a transient read
  /// failure never crashes this screen. It also never fabricates a guessed
  /// plan id; the current-plan card and every plan-card CTA simply stay in
  /// their unresolved state until a retry succeeds.
  ///
  /// [_manageVia] and [_manageUrl] are republished whether or not the payload
  /// names a plan, because the rail is a separate fact from the tier: a team
  /// whose `plan` is absent can still be billed through a store, and gating the
  /// rail behind a non-null plan would have left it unresolved for exactly the
  /// teams whose management surface is hardest to guess.
  Future<void> _loadEntitlement() async {
    try {
      final BillingEntitlement entitlement = await _billing
          .currentEntitlement();
      if (!mounted) return;
      final String? plan = entitlement.plan;
      setState(() {
        _manageVia = entitlement.manageVia;
        _manageUrl = entitlement.manageUrl;
        if (plan != null) {
          _currentPlanId = plan;
          _entitlementLoaded = true;
        }
      });
      if (plan != null) _startRequestedUpgrade();
    } catch (_) {
      // Deliberate degradation: keeps the fixture plan id as last-known
      // state (see the docblock above) instead of throwing.
    }
  }

  /// Reads the plan catalog from `GET /billing/plans` via
  /// [BillingService.getPlans], decodes uptizm's own [Plan] from its rows and
  /// republishes [_plans].
  ///
  /// The contract answers rows verbatim: a tier's prices, feature bullets and
  /// in-product caps are what uptizm sells rather than anything a payment rail
  /// understands, so [Plan] stays on this side and the decode happens here.
  ///
  /// Deliberate degradation on failure: [_plans] stays empty (last-known
  /// state before the first successful fetch), so the current-plan card and
  /// the plans grid render their loading/empty state instead of crashing.
  Future<void> _loadPlans() async {
    try {
      final List<Map<String, dynamic>> rows = await _billing.getPlans();
      final List<Plan> plans = rows.map(Plan.fromMap).toList();
      if (!mounted) return;
      setState(() => _plans = plans);
      _startRequestedUpgrade();
    } catch (_) {
      // Deliberate degradation: see the docblock above.
    }
  }

  /// Starts checkout for the plan named in the `?upgrade=<plan>` query, so a
  /// gated action elsewhere in the app can hand the user straight into the
  /// purchase instead of dropping them on the grid to find the tier themselves.
  ///
  /// Runs once per mount, only for a tier the catalog actually serves and only
  /// when it is above the current plan (an `?upgrade=free` or a stale link to
  /// the tier the team already pays for must not open a checkout). Silently
  /// does nothing otherwise: the grid is still there to pick from by hand.
  ///
  /// Both the catalog and the live entitlement have to be in before it fires:
  /// the plan id is seeded from a fixture, so acting on the seed could open a
  /// downgrade checkout for a team whose real plan had not arrived yet. Called
  /// from both loaders, whichever resolves last wins.
  void _startRequestedUpgrade() {
    if (_upgradeRequestHandled) return;
    if (_plans.isEmpty || !_entitlementLoaded) return;
    // The same gate the CTA renders behind. A deep link is the one path that
    // can reach checkout without a tap, so a store-billed team or a non-owner
    // arriving on `?upgrade=business` would otherwise be handed a purchase the
    // server is about to refuse.
    if (!_canPurchase) return;

    final Map<String, String> query = MagicRouter.instance.queryParameters;
    final String? requested = query[PlanUpgradeRequirement.planQueryKey];
    if (requested == null || requested.isEmpty) return;

    // The token is consumed process-wide, not per mount: this screen mounts
    // twice per arrival and both mounts resolve their fetches at about the same
    // instant, so a per-mount guard (and clearing the query, which the second
    // mount had already read) let one arrival open two checkout sessions. A
    // hand-typed URL with no token falls back to the plan id, so it still
    // fires once. A later Upgrade tap mints a new token and fires again.
    final String token =
        query[PlanUpgradeRequirement.intentQueryKey] ?? requested;
    if (_consumedUpgradeIntent == token) return;

    final Plan? target = _plans
        .where((Plan plan) => plan.id == requested)
        .firstOrNull;
    if (target == null || _direction(target) <= 0) return;

    _upgradeRequestHandled = true;
    _consumedUpgradeIntent = token;
    // Also drop the query from the URL, so a reload of what the user now sees
    // in the address bar does not read as a fresh purchase intent.
    MagicRoute.replace(MagicStarterConfig.billingRoute());
    _selectPlan(target);
  }

  /// Reads the team's usage stats from `GET /billing/usage` via
  /// [BillingService.getUsage], pairs uptizm's display copy onto them and
  /// republishes [_usage].
  ///
  /// The contract carries the numbers and no label: a resource's display name is
  /// product copy, so `withUsageCopy` matches it on by [UsageStat.key], the one
  /// handle that does not move with the language.
  ///
  /// Deliberate degradation on failure: [_usage] stays empty, so the meter
  /// grid simply renders no rows instead of crashing.
  Future<void> _loadUsage() async {
    try {
      final List<UsageStat> usage = withUsageCopy(await _billing.getUsage());
      if (!mounted) return;
      setState(() => _usage = usage);
    } catch (_) {
      // Deliberate degradation: see the docblock above.
    }
  }

  /// Reads the first page of the team's billing history from
  /// `GET /billing/invoices` via [BillingService.getInvoices] and republishes
  /// [_invoices].
  ///
  /// Deliberate degradation on failure: [_invoices] stays empty, so the
  /// billing-history card simply renders no rows instead of crashing.
  Future<void> _loadInvoices() async {
    try {
      final BillingInvoicesPage page = await _billing.getInvoices();
      if (!mounted) return;
      setState(() => _invoices = page.invoices);
    } catch (_) {
      // Deliberate degradation: see the docblock above.
    }
  }

  /// Reads the team's on-file payment method from
  /// `GET /billing/payment-method` via [BillingService.getPaymentMethod] and
  /// republishes [_paymentMethod].
  ///
  /// This is the only Stripe-live billing read (see the class docblock), so
  /// it carries its own [_pmLoading]/[_pmError] state instead of gating the
  /// rest of the screen. A transport-level failure sets [_pmError]; the
  /// backend's own soft-fail (an all-null 200 on a Stripe outage) decodes
  /// cleanly into an all-null [PaymentMethod] instead, which the card renders
  /// as its empty/updatable state.
  Future<void> _loadPaymentMethod() async {
    try {
      final PaymentMethod paymentMethod = await _billing.getPaymentMethod();
      if (!mounted) return;
      setState(() {
        _paymentMethod = paymentMethod;
        _pmLoading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _pmLoading = false;
        _pmError = true;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return MSPageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          MSPageHeader(
            title: trans('uptizm.teams.billing_title'),
            subtitle: trans('uptizm.teams.billing_description'),
            backLabel: trans('uptizm.nav.dashboard'),
            backFallback: _backFallback,
          ),
          const SizedBox(height: 24),
          _buildCurrentPlanCard(),
          const SizedBox(height: 40),
          _buildPlansSection(),
          const SizedBox(height: 40),
          _buildPaymentMethodSection(),
          const SizedBox(height: 32),
          _buildInvoicesSection(),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Current plan + usage
  // ---------------------------------------------------------------------------

  /// Builds the current-plan [Card]: name + "Current" badge, the renewal line,
  /// and a responsive two-column grid of [UsageMeter]s over the fetched usage
  /// stats. Renders a loading skeleton in place of the name/badge/renewal
  /// block while [_current] is `null` (the plan catalog has not resolved yet,
  /// its fetch failed, or the live entitlement itself has not resolved), so
  /// no plan name or "Current" claim is ever shown before it is confirmed.
  /// The usage grid does not depend on [_current] (usage stats are not
  /// plan-scoped) and renders as soon as [_usage] itself resolves.
  Widget _buildCurrentPlanCard() {
    final Plan? current = _current;

    return MSCard(
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: [
          if (current == null)
            const WDiv(
              className: 'flex flex-col gap-1',
              children: [
                MSSkeleton(shape: SkeletonShape.text, width: 160, height: 20),
                MSSkeleton(height: 16, width: 220),
              ],
            )
          else
            WDiv(
              className: 'flex flex-col gap-1',
              children: [
                WDiv(
                  className: 'flex flex-row items-center gap-2',
                  children: [
                    WText(
                      current.name,
                      className: 'text-sm font-semibold text-fg',
                    ),
                    MSBadge(
                      trans('uptizm.teams.billing_plan_current_badge'),
                      tone: BadgeTone.primary,
                    ),
                  ],
                ),
                WText(
                  _renewalLine(current),
                  className: 'text-sm text-fg-muted',
                ),
              ],
            ),
          WDiv(
            className: 'grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2',
            children: [
              // A stat `withUsageCopy` could not name gets NO meter, rather than
              // one labelled with its raw wire key. The backend reports every
              // resource it meters and this app names three of them, so a fourth
              // arriving early would otherwise put `checks_this_month` on a
              // customer's screen; a gate still reads it by key either way.
              for (final UsageStat stat in _usage)
                if (stat.label != null)
                  UsageMeter(
                    label: stat.label!,
                    used: stat.used,
                    limit: stat.limit,
                    unit: stat.unit.isEmpty ? null : stat.unit,
                  ),
            ],
          ),
        ],
      ),
    );
  }

  /// The one line under the current plan's name, in its three states.
  ///
  /// A free plan never renews and carries no payment method, so it reads "free
  /// forever" rather than a `renews <date>` line that said "renews Unknown" with
  /// no card on file.
  ///
  /// A STORE-BILLED plan gets its own sentence, and the reason is the same one
  /// that suppresses the price on a store purchase card: the amount here comes
  /// from the USD catalogue and the cadence is this screen's own annual default,
  /// while the store charged a localised price on its own SKU's cadence and the
  /// renewal date behind it is a Stripe read that answers null for a store
  /// subscription. Every number in the sentence would therefore be wrong at
  /// once, which is worse than not showing it.
  ///
  /// A paid tier that NO rail is billing gets its own sentence too, and it is
  /// the state this method used to get wrong: it fell through to the live
  /// sentence and rendered "renews Unknown", which reads as a renewal whose
  /// date was mislaid rather than as no renewal at all. Measured on the dev box
  /// against a Business team with `plan_provider` null.
  ///
  /// Otherwise the live sentence, whose date comes from
  /// `GET /billing/payment-method` and falls back to a neutral label while the
  /// lazy fetch is pending or after its Stripe soft-fail, never to a fabricated
  /// date. That neutral label survives on purpose: "pending" and "there is
  /// none" are different answers, and only the second one is settled.
  String _renewalLine(Plan current) {
    if (current.monthly == 0 && current.annual == 0) {
      return trans('uptizm.teams.billing_renewal_free');
    }

    if (_storeManaged) return trans('uptizm.teams.billing_renewal_store');

    // A PAID tier that no rail is billing: granted directly, or a provider
    // recorded before a customer ever existed. Nothing renews, so nothing is
    // promised. The discriminator is that the payment-method read RESOLVED and
    // carried no date, which is not the same as `manage_via` being `none`: that
    // word also covers a Stripe team whose `stripe_id` has not been created yet,
    // and half this file's fixtures leave it at its default.
    final PaymentMethod? resolved = _paymentMethod;
    if (resolved != null && resolved.renewalDate == null) {
      return trans('uptizm.teams.billing_renewal_unbilled');
    }

    return trans('uptizm.teams.billing_renewal_text', {
      'price': _priceLabel(current, BillingCycle.annual),
      'cycle': trans('uptizm.teams.billing_renewal_cycle_annual'),
      'date':
          _formatDate(_paymentMethod?.renewalDate) ?? trans('common.unknown'),
    });
  }

  // ---------------------------------------------------------------------------
  // Plans section
  // ---------------------------------------------------------------------------

  /// Builds the tier-comparison section: a centered heading + cycle toggle,
  /// then a responsive grid of one plan card per fetched plan (a loading
  /// skeleton grid while [_plans] is still empty).
  ///
  /// A known non-owner gets one notice here rather than the same sentence
  /// repeated on every card: the grid stays fully readable (comparing tiers is
  /// not a write), it just stops offering to buy. A store account already
  /// funding another team gets its own notice beside it, naming that team.
  ///
  /// The monthly/annual toggle is absent on a store build, and that absence is
  /// correctness rather than tidiness: the store catalogue carries the two
  /// MONTHLY SKUs only, so a customer who picked "Annual" and tapped Upgrade
  /// would be charged monthly by the sheet that opened.
  Widget _buildPlansSection() {
    return WDiv(
      className: 'flex flex-col gap-5',
      children: [
        if (_isOwner == false)
          _buildStatementTile(trans('uptizm.teams.billing_owner_only_notice')),
        if (_storeFundedTeam != null)
          _buildStatementTile(
            trans('uptizm.teams.billing_store_bound_text', {
              'team': _storeFundedTeam!,
            }),
          ),
        WDiv(
          className: 'flex flex-col items-center gap-2 text-center',
          children: [
            WText(
              trans('uptizm.teams.billing_plans_heading'),
              className: 'text-lg font-semibold text-fg',
            ),
            if (_store == null)
              MSSegmentedControl<BillingCycle>(
                size: SegmentedControlSize.sm,
                options: [
                  trans('uptizm.teams.billing_plans_monthly'),
                  trans('uptizm.teams.billing_plans_annual'),
                ],
                selectedIndex: _cycles.indexOf(_cycle),
                onChanged: (index) => setState(() => _cycle = _cycles[index]),
              ),
          ],
        ),
        if (_plans.isEmpty)
          const WDiv(
            className: 'grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4',
            children: [
              MSSkeleton(height: 280),
              MSSkeleton(height: 280),
              MSSkeleton(height: 280),
              MSSkeleton(height: 280),
            ],
          )
        else
          WDiv(
            className: 'grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4',
            children: [for (final Plan plan in _plans) _buildPlanCard(plan)],
          ),
        // Restore sits behind the same gate as the purchase, not behind the
        // store rail alone. Restoring hands a subscription this store account
        // owns to whichever team the rail is currently identified as, so on a
        // team the account must not fund it would be the transfer the refusal
        // above exists to prevent, arriving through a different button.
        if (_canPurchaseViaStore)
          WDiv(
            className: 'flex flex-col items-center',
            children: [
              MSButton(
                intent: ButtonIntent.ghost,
                size: ButtonSize.sm,
                onPressed: () => _restoreStorePurchases(),
                child: WText(
                  trans('uptizm.teams.billing_store_restore_button'),
                ),
              ),
            ],
          ),
      ],
    );
  }

  /// Builds one plan card: name/tagline, price for the selected cycle, the AI
  /// hero tile, the feature list, an optional responder add-on line, an optional
  /// "Recommended" badge, and the CTA.
  Widget _buildPlanCard(Plan plan) {
    final bool isCurrent = _currentPlanId != null && plan.id == _currentPlanId;
    final bool isCustom = plan.monthly == null;
    // The catalogue's price is an integer of US dollars, and a store charges a
    // storefront-localised amount in the customer's own currency, so on a store
    // build that figure is not the price of anything. The rail's own localised
    // string is the right source and the driver does not expose one yet
    // (recorded as a follow-up), so this states where the price comes from
    // instead: the sheet shows the real one before anybody is charged, and
    // showing a wrong price is worse than showing none. Free stays `$0`, which
    // is true in every currency, and the custom tier keeps its own label.
    final bool storePriced = _store != null && !isCustom && plan.monthly != 0;

    return WDiv(
      className: plan.recommended
          ? 'relative flex flex-col gap-4 rounded-lg border '
                'border-primary bg-surface p-5'
          : 'relative flex flex-col gap-4 rounded-lg border '
                'border-color-border bg-surface p-5',
      children: [
        if (plan.recommended)
          WDiv(
            className: 'absolute -top-2.5 left-5',
            child: MSBadge(
              trans('uptizm.teams.billing_plan_recommended_badge'),
              tone: BadgeTone.primary,
            ),
          ),
        // 1. Name + tagline.
        WDiv(
          className: 'flex flex-col gap-0.5',
          children: [
            WText(plan.name, className: 'text-base font-semibold text-fg'),
            WText(plan.tagline, className: 'text-xs text-fg-muted'),
          ],
        ),
        // 2. Price + billing note for the selected cycle.
        WDiv(
          className: 'flex flex-col gap-0.5',
          children: [
            if (storePriced)
              WText(
                trans('uptizm.teams.billing_plan_price_store'),
                className: 'text-base font-medium text-fg',
              )
            else
              WDiv(
                className: 'flex flex-row items-baseline gap-1',
                children: [
                  WText(
                    _priceLabel(plan, _cycle),
                    className: 'text-3xl font-semibold tabular-nums text-fg',
                  ),
                  if (!isCustom)
                    WText(
                      trans('uptizm.teams.billing_plan_price_monthly'),
                      className: 'text-sm text-fg-muted',
                    ),
                ],
              ),
            WText(_billingNote(plan), className: 'text-xs text-fg-muted'),
          ],
        ),
        // 3. AI hero tile: the value each upgrade buys.
        WDiv(
          className:
              'flex flex-row items-start gap-2 rounded-md '
              'bg-ai-soft p-2.5',
          children: [
            WIcon(_sparkleIcon, className: 'text-[16px] text-ai'),
            WText(
              plan.aiLine,
              className: 'flex-1 text-xs leading-relaxed text-fg',
            ),
          ],
        ),
        // 4. Feature list.
        WDiv(
          className: 'flex flex-col gap-2',
          children: [
            for (final String feature in plan.features)
              WDiv(
                className: 'flex flex-row items-start gap-2',
                children: [
                  WIcon(_checkIcon, className: 'text-[16px] text-primary'),
                  WText(feature, className: 'flex-1 text-sm text-fg'),
                ],
              ),
          ],
        ),
        // 5. Optional responder add-on line.
        if (plan.responderAddOn != null)
          WText(plan.responderAddOn!, className: 'text-xs text-fg-muted'),
        // 6. CTA. A block column stretches the button full-width without a
        //    flex-row w-full (MUST NOT). Current plan is disabled.
        //
        //    Rendered for three different reasons, and only one of them is a
        //    purchase: the active tier's disabled "Current plan" label (a
        //    read-out, and the only marker of which card is theirs once the
        //    grid stops offering to buy), the custom tier's sales handoff
        //    (driven by the GRID, since `teams.plan` can never hold
        //    `enterprise`, so it survives both gates), and an actual purchase,
        //    which needs the rail and the membership to allow it.
        if (isCurrent || isCustom || _canPurchase)
          WDiv(
            className: 'flex flex-col pt-1',
            children: [
              MSButton(
                intent: (isCurrent || !plan.recommended)
                    ? ButtonIntent.secondary
                    : ButtonIntent.primary,
                disabled: isCurrent,
                onPressed: isCurrent ? null : () => _selectPlan(plan),
                child: WText(_ctaLabel(plan)),
              ),
            ],
          ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Payment method
  // ---------------------------------------------------------------------------

  /// Builds the payment-method section: a heading + a [Card] with the brand
  /// tile, the masked number, the expiry + renewal date, and an "Update"
  /// [Button] that opens the Stripe billing portal.
  ///
  /// This card owns its own loading/error state (see [_pmLoading]/[_pmError])
  /// since `GET /billing/payment-method` is the only Stripe-live billing
  /// read: it never blocks the rest of the screen, and a soft-fail (an
  /// all-null [PaymentMethod], whether from a genuine "no card on file" or
  /// the endpoint's own Stripe-outage degradation) renders as an
  /// empty/updatable state instead of crashing.
  ///
  /// On a store rail the section is replaced wholesale by
  /// [_buildStoreManagedSection]: there is no Stripe card behind a store
  /// subscription, so a "Payment method" card would be describing an object
  /// that does not exist.
  Widget _buildPaymentMethodSection() {
    if (_storeManaged) return _buildStoreManagedSection();

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.teams.billing_payment_header'),
          className: 'text-sm font-semibold text-fg',
        ),
        MSCard(child: _buildPaymentMethodContent()),
      ],
    );
  }

  /// Builds the store-managed section: a heading, the statement naming the
  /// store that sold this subscription, and either a link to the
  /// server-supplied [BillingEntitlement.manageUrl] or, when the rail reported
  /// none, the sentence telling the customer where to look instead.
  ///
  /// A null [_manageUrl] deliberately renders NO button, not a disabled one: a
  /// disabled button invites a tap and explains nothing, while the sentence
  /// answers the question the tap would have asked. The URL itself comes from
  /// the rail rather than from a hardcoded Apple or Google address, so a store
  /// moving its subscriptions page does not need an app release.
  Widget _buildStoreManagedSection() {
    final String? manageUrl = _manageUrl;

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.teams.billing_manage_header'),
          className: 'text-sm font-semibold text-fg',
        ),
        MSCard(
          child: WDiv(
            className: 'flex flex-col gap-3',
            children: [
              _buildStatementTile(_storeStatement()),
              if (manageUrl != null && manageUrl.isNotEmpty)
                WDiv(
                  className: 'flex flex-col',
                  children: [
                    MSButton(
                      intent: ButtonIntent.secondary,
                      size: ButtonSize.sm,
                      onPressed: () => _openStoreManagement(manageUrl),
                      child: WText(
                        trans('uptizm.teams.billing_manage_store_button'),
                      ),
                    ),
                  ],
                )
              else
                WText(
                  trans('uptizm.teams.billing_manage_store_no_url'),
                  className: 'text-xs text-fg-muted',
                ),
            ],
          ),
        ),
      ],
    );
  }

  /// The statement naming the store that sold the active subscription.
  ///
  /// Exhaustive over [ManageVia] with no `default`, so adding a fifth rail is a
  /// compile error here rather than a screen that silently names the wrong
  /// store. The two non-store cases are unreachable (this is only called from
  /// [_buildStoreManagedSection], behind [_storeManaged]) and fall back to the
  /// generic "look in your store account" sentence rather than inventing a
  /// vendor.
  String _storeStatement() {
    return switch (_manageVia) {
      ManageVia.appStore => trans('uptizm.teams.billing_manage_app_store_text'),
      ManageVia.playStore => trans(
        'uptizm.teams.billing_manage_play_store_text',
      ),
      ManageVia.portal ||
      ManageVia.none ||
      null => trans('uptizm.teams.billing_manage_store_no_url'),
    };
  }

  /// Builds the soft-tinted informational tile the store statement and the
  /// owner-only notice share.
  ///
  /// A [WDiv] + [WIcon] + [WText] on the `info` status family, mirroring the
  /// AI hero tile in [_buildPlanCard]; every token carries its `dark:` pair
  /// through `uptizmStatusAliases`. There is no registry component for a
  /// one-line notice (checked `docs/component-registry.md`: it has EmptyState
  /// and ErrorState, both of which want a title, a glyph and an action).
  Widget _buildStatementTile(String statement) {
    return WDiv(
      className:
          'flex flex-row items-start gap-2 rounded-md '
          'bg-info-soft p-2.5',
      children: [
        WIcon(_infoIcon, className: 'text-sm text-info'),
        WText(
          statement,
          className: 'flex-1 text-xs leading-relaxed text-info-soft-foreground',
        ),
      ],
    );
  }

  /// Opens the store's own subscription-management page.
  ///
  /// [Launch.url] answers false rather than throwing when nothing can handle
  /// the URL, so a failure surfaces the same sentence the null-URL branch
  /// renders: the customer still learns where to go.
  Future<void> _openStoreManagement(String manageUrl) async {
    final bool opened = await Launch.url(manageUrl);
    if (opened) return;

    MagicFeedback.info(
      trans('uptizm.teams.billing_manage_header'),
      trans('uptizm.teams.billing_manage_store_no_url'),
    );
  }

  /// Builds the payment-method card's body for its four states: loading
  /// skeleton, error text, resolved-with-nothing-on-file, or the card.
  ///
  /// The third state used to fall through to the fourth, and it rendered as a
  /// card: the brand tile said "Unknown" and the number row, having no `last4`,
  /// fell back to the SECTION HEADING, so a team with no card saw "Payment
  /// method" twice beside a tile implying a real card whose brand had been lost.
  /// A resolved non-answer is not a value; it gets named. Measured on the dev
  /// box against a Business team with `plan_provider` null, whose
  /// `GET /billing/payment-method` answers every field null.
  ///
  /// "Resolved with nothing" is deliberately BOTH `brand` and `last4` being
  /// absent, not either: a rail that returned one without the other has partly
  /// answered, and claiming "no card on file" over a partial answer would be a
  /// second wrong sentence rather than a fix for the first.
  Widget _buildPaymentMethodContent() {
    if (_pmLoading) {
      return WDiv(
        className: 'flex flex-row items-center gap-4',
        children: const [
          MSSkeleton(width: 48, height: 36),
          Expanded(child: MSSkeleton(shape: SkeletonShape.text, height: 16)),
        ],
      );
    }

    if (_pmError) {
      return WDiv(
        className: 'flex flex-row items-center gap-4',
        children: [
          Expanded(
            child: WText(
              trans('common.error_occurred'),
              className: 'text-sm text-fg-muted',
            ),
          ),
          if (_portalAvailable)
            MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: () => _openBillingPortal(),
              child: WText(trans('uptizm.teams.billing_payment_update_button')),
            ),
        ],
      );
    }

    final PaymentMethod? paymentMethod = _paymentMethod;

    if (paymentMethod == null ||
        (paymentMethod.brand == null && paymentMethod.last4 == null)) {
      return WDiv(
        className: 'flex flex-row items-center gap-4',
        children: [
          Expanded(
            child: WText(
              trans('uptizm.teams.billing_payment_none'),
              className: 'text-sm text-fg-muted',
            ),
          ),
          if (_portalAvailable)
            MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: () => _openBillingPortal(),
              child: WText(trans('uptizm.teams.billing_payment_update_button')),
            ),
        ],
      );
    }

    final String? last4 = paymentMethod.last4;
    final String? expiry = _cardExpiry(paymentMethod);

    return WDiv(
      className: 'flex flex-row items-center gap-4',
      children: [
        WDiv(
          className:
              'h-9 w-12 shrink-0 overflow-hidden '
              'rounded-md border border-color-border '
              'bg-surface-container-high',
          // Centred in Flutter, not in Wind: this tile said
          // `grid ... place-items-center`, and Wind has no `place-items-*`, so
          // the brand sat against the top-left edge of the card. See
          // `_buildAvatarTile` in on_call_schedule_view.dart for the measurement.
          child: Center(
            child: WText(
              // Reachable only on a PARTIAL answer now (a `last4` with no
              // brand); the all-null case returns above.
              paymentMethod.brand ?? trans('common.unknown'),
              className: 'text-xs font-semibold text-fg',
            ),
          ),
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText(
                last4 != null
                    ? '•••• •••• •••• $last4'
                    : trans('uptizm.teams.billing_payment_header'),
                className: 'font-mono text-sm tabular-nums text-fg',
              ),
              if (expiry != null)
                WText(
                  trans('uptizm.teams.billing_payment_expires', {
                    'date': expiry,
                  }),
                  className: 'font-mono text-xs tabular-nums text-fg-muted',
                ),
            ],
          ),
        ),
        if (_portalAvailable)
          MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: () => _openBillingPortal(),
            child: WText(trans('uptizm.teams.billing_payment_update_button')),
          ),
      ],
    );
  }

  /// Opens the Stripe billing portal via [WebBillingService.openPortal],
  /// surfacing the same deferred/failure toasts as [_selectPlan]'s checkout
  /// path. Shared by the payment-method "Update" button and every invoice
  /// row's "Receipt" button (both are Stripe-portal actions; the portal
  /// itself deep-links a customer straight to their invoice history).
  ///
  /// A null [_web] returns without a word, and cannot be reached from the UI:
  /// [_portalAvailable] gates every affordance that calls this on the same rail.
  /// The guard is here because a null rail is not an error to report to a
  /// customer, it is a button that was never rendered.
  ///
  /// A failure re-reads the entitlement. The endpoint has two refusals this
  /// screen's own gate is supposed to have made unreachable (409
  /// `managed_by_store` and 409 `no_billing_account`, both of which imply a
  /// [_manageVia] other than [ManageVia.portal]), so reaching one means the
  /// rail changed under a mounted screen. Re-reading the authority is the fix,
  /// and it keys off the server's `manage_via` rather than off the refusal's
  /// English sentence.
  Future<void> _openBillingPortal() async {
    final WebBillingService? web = _web;
    if (web == null) return;

    try {
      await web.openPortal(returnUrl: '$_webOrigin/teams/billing');
    } on UnsupportedPlatformException catch (error) {
      MagicFeedback.info(
        trans('uptizm.teams.billing_toast_deferred_title'),
        error.message,
      );
    } on BillingException catch (error) {
      Magic.error(
        trans('uptizm.teams.billing_toast_checkout_failed_title'),
        error.message,
      );
      await _loadEntitlement();
    }
  }

  // ---------------------------------------------------------------------------
  // Billing history
  // ---------------------------------------------------------------------------

  /// Builds the billing-history section: a heading + a full-bleed [Card] with
  /// one row per fetched [Invoice] (no rows until [_loadInvoices] resolves).
  Widget _buildInvoicesSection() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.teams.billing_invoices_header'),
          className: 'text-sm font-semibold text-fg',
        ),
        MSCard(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (final (int index, Invoice invoice) in _invoices.indexed)
                _buildInvoiceRow(
                  invoice,
                  isLast: index == _invoices.length - 1,
                ),
            ],
          ),
        ),
      ],
    );
  }

  /// Builds one invoice row: date + number, the status pill, the amount, and,
  /// on the Stripe rail only, a "Receipt" [Button] that opens the billing
  /// portal.
  Widget _buildInvoiceRow(Invoice invoice, {required bool isLast}) {
    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 '
                'border-b border-color-border',
      children: [
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText(
                _formatDate(invoice.date) ?? '',
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(
                invoice.number,
                className: 'truncate text-xs text-fg-muted',
              ),
            ],
          ),
        ),
        _buildStatusPill(invoice.status),
        WText(
          invoice.amount,
          className: 'font-mono text-sm tabular-nums text-fg',
        ),
        // The receipt is a portal deep link, not a stored PDF URL, so it is
        // gated on the portal being this team's surface at all.
        if (_portalAvailable)
          MSButton(
            intent: ButtonIntent.ghost,
            size: ButtonSize.sm,
            onPressed: () => _openBillingPortal(),
            child: WText(trans('uptizm.teams.billing_invoice_receipt_button')),
          ),
      ],
    );
  }

  /// Builds a small token-tinted [InvoiceStatus] pill.
  ///
  /// A rounded [WDiv] + [WText] carrying [status]'s label, NOT [StatusBadge]
  /// (which takes a monitoring [StatusKey] rather than an [InvoiceStatus]).
  /// Maps paid -> up-soft, pending -> degraded-soft, failed -> down-soft; every
  /// pair carries its `dark:` counterpart via `uptizmStatusAliases`.
  ///
  /// The copy switch sits beside the colour switch on purpose. `magic_payments`
  /// carries the enum as a pure vocabulary with no label getter, because a
  /// display word would ship one vendor's catalogue key into every consumer; the
  /// two switches being adjacent is what keeps the tone and the word from
  /// drifting apart. Both are exhaustive with no `default`, so a fourth
  /// settlement state is a compile error here rather than an unlabelled pill.
  Widget _buildStatusPill(InvoiceStatus status) {
    const String base =
        'flex flex-row items-center rounded-full px-2 py-0.5 '
        'text-xs font-medium';

    final String tone = switch (status) {
      InvoiceStatus.paid => 'bg-up-soft text-up-soft-foreground',
      InvoiceStatus.pending => 'bg-degraded-soft text-degraded-soft-foreground',
      InvoiceStatus.failed => 'bg-down-soft text-down-soft-foreground',
    };

    final String label = switch (status) {
      InvoiceStatus.paid => trans('uptizm.enums.invoice_status.paid'),
      InvoiceStatus.pending => trans('uptizm.enums.invoice_status.pending'),
      InvoiceStatus.failed => trans('uptizm.enums.invoice_status.failed'),
    };

    return WDiv(className: '$base $tone', child: WText(label));
  }

  // ---------------------------------------------------------------------------
  // CTA + toast
  // ---------------------------------------------------------------------------

  /// Selects [plan]: hands off to sales for the custom tier, buys through the
  /// STORE rail where this build has one ([_purchaseInStore]), and otherwise
  /// starts a live Stripe Checkout session via [WebBillingService.checkout] for
  /// [plan.id], the backend `Plan` enum value (`'pro'`/`'business'`; the
  /// backend rejects anything else with a 422). Both rails are keyed by that
  /// same plan id, never by a store product id: the SKU a plan maps to belongs
  /// to the rail's catalogue, and a client naming one would need a release to
  /// add or reprice a product.
  ///
  /// The monthly/annual [_cycle] toggle is local display state only: the
  /// backend plan map is cycle-agnostic (one Stripe price per plan, no
  /// monthly/annual price dimension yet), so it is never encoded into the
  /// checkout payload (see the `### Deviations` note on this step). The store
  /// rail never sees it either, and its toggle is not even rendered.
  ///
  /// The custom tier's sales handoff runs on a build with no rail at all, because
  /// it spends nothing and calls nothing. A priced tier with neither rail returns
  /// silently: [_canPurchase] gates every CTA that reaches it, so there is no
  /// button to explain a refusal to.
  ///
  /// [WebBillingService.checkout] opens the returned checkout URL in an in-app
  /// browser tab; this only surfaces the local confirmation toast afterward. An
  /// [UnsupportedPlatformException] (a rail that is present but cannot serve the
  /// running device) is surfaced as a friendly info toast rather than an error;
  /// any other [BillingException] (a failed request) surfaces an error toast.
  /// Neither failure is swallowed silently. Mirrors the React `selectPlan`.
  Future<void> _selectPlan(Plan plan) async {
    // 1. Custom tier: hand off to sales, no live billing call.
    if (plan.monthly == null) {
      Magic.success(
        trans('uptizm.teams.billing_toast_contact_title'),
        trans('uptizm.teams.billing_toast_contact_description'),
      );
      return;
    }

    // 2. A store build buys in the store, and it is asked FIRST: no build serves
    //    both rails, so this is a routing decision rather than a preference, and
    //    the store must never fall through to a web checkout the store forbids
    //    steering to.
    if (_store != null) return _purchaseInStore(plan);

    // 3. Priced tier: start checkout for the plan, redirecting Stripe back to
    //    this screen on completion/abort.
    final WebBillingService? web = _web;
    if (web == null) return;

    final bool isUpgrade = _direction(plan) > 0;
    try {
      await web.checkout(
        plan: plan.id,
        successUrl: '$_webOrigin/teams/billing?checkout=success',
        cancelUrl: '$_webOrigin/teams/billing?checkout=cancel',
      );
      Magic.success(
        trans(
          isUpgrade
              ? 'uptizm.teams.billing_toast_upgrade_title'
              : 'uptizm.teams.billing_toast_switch_title',
          {'name': plan.name},
        ),
        trans('uptizm.teams.billing_toast_change_description', {
          'cycle': _cycleLabel(_cycle),
        }),
      );
    } on UnsupportedPlatformException catch (error) {
      MagicFeedback.info(
        trans('uptizm.teams.billing_toast_deferred_title'),
        error.message,
      );
    } on BillingException catch (error) {
      Magic.error(
        trans('uptizm.teams.billing_toast_checkout_failed_title'),
        error.message,
      );
    }
  }

  /// Buys [plan] through the STORE rail: the platform's own purchase sheet, on
  /// the SKU the rail's catalogue keys by the plan id.
  ///
  /// The one-team refusal is re-ASKED here rather than read off the mount-time
  /// answer, because this is the point where the money moves: a tap can arrive
  /// long after the screen loaded, and the deep-link path
  /// ([_startRequestedUpgrade]) can arrive before that read resolved at all. One
  /// extra GET on a tap is cheaper than a transfer nobody asked for. It names the
  /// team, for the same reason the notice above the grid does.
  ///
  /// `false` from the rail is the ordinary outcome of a customer closing the
  /// sheet, so it reports nothing at all: an "it did not work" toast for a
  /// deliberate dismissal would be this screen inventing a failure. `true` is the
  /// STORE's word and not the backend's, which is why the confirmation says the
  /// plan updates when the store confirms it and the entitlement is re-read
  /// rather than assumed: the rail's webhook is the authority and it may not have
  /// arrived yet.
  Future<void> _purchaseInStore(Plan plan) async {
    final StoreBillingService? store = _store;
    if (store == null) return;

    await _loadStoreFundedTeam();
    if (!mounted) return;

    final String? fundedTeam = _storeFundedTeam;
    if (fundedTeam != null) {
      Magic.error(
        trans('uptizm.teams.billing_store_bound_title'),
        trans('uptizm.teams.billing_store_bound_text', {'team': fundedTeam}),
      );

      return;
    }

    try {
      final bool bought = await store.purchase(plan: plan.id);
      if (!bought || !mounted) return;

      Magic.success(
        trans('uptizm.teams.billing_store_purchase_title'),
        trans('uptizm.teams.billing_store_purchase_text'),
      );
      await _loadEntitlement();
    } on UnsupportedPlatformException catch (error) {
      MagicFeedback.info(
        trans('uptizm.teams.billing_toast_deferred_title'),
        error.message,
      );
    } on BillingException catch (error) {
      Magic.error(
        trans('uptizm.teams.billing_toast_checkout_failed_title'),
        error.message,
      );
    }
  }

  /// Asks the store for a subscription this account already owns, which is the
  /// customer's only route back after a reinstall or onto a second device, and
  /// is required of any app that sells through the store.
  ///
  /// Both answers are reported, and neither is a failure: `false` means the
  /// store had nothing for this account, which is an answer the customer needs
  /// rather than an error to log. A restore that DID hand something back carries
  /// the same non-promise as a purchase, so it re-reads the entitlement instead
  /// of claiming the plan changed.
  Future<void> _restoreStorePurchases() async {
    final StoreBillingService? store = _store;
    if (store == null) return;

    try {
      final bool restored = await store.restore();
      if (!mounted) return;

      if (!restored) {
        MagicFeedback.info(
          trans('uptizm.teams.billing_store_restore_none_title'),
          trans('uptizm.teams.billing_store_restore_none_text'),
        );

        return;
      }

      Magic.success(
        trans('uptizm.teams.billing_store_restore_found_title'),
        trans('uptizm.teams.billing_store_purchase_text'),
      );
      await _loadEntitlement();
    } on UnsupportedPlatformException catch (error) {
      MagicFeedback.info(
        trans('uptizm.teams.billing_toast_deferred_title'),
        error.message,
      );
    } on BillingException catch (error) {
      Magic.error(
        trans('uptizm.teams.billing_toast_checkout_failed_title'),
        error.message,
      );
    }
  }

  /// Resolves the CTA label for [plan] against the current plan.
  ///
  /// "Current plan" for the active tier; "Contact sales" for a custom tier;
  /// otherwise "Upgrade" (higher tier) or "Downgrade" (lower tier). While
  /// [_currentPlanId] is still unresolved, no card may claim to be the active
  /// tier or an upgrade/downgrade target, so every priced tier falls back to
  /// the neutral "View plan" label instead (the custom tier still reads
  /// "Contact sales", since that copy never depended on the current plan).
  /// Mirrors the React `ctaLabel`.
  String _ctaLabel(Plan plan) {
    if (plan.monthly == null) {
      return trans('uptizm.teams.billing_plan_button_contact');
    }
    if (_currentPlanId == null) {
      return trans('uptizm.teams.billing_plan_button_unresolved');
    }
    if (plan.id == _currentPlanId) {
      return trans('uptizm.teams.billing_plan_button_current');
    }
    return _direction(plan) > 0
        ? trans('uptizm.teams.billing_plan_button_upgrade')
        : trans('uptizm.teams.billing_plan_button_downgrade');
  }

  /// The tier distance of [plan] from the current plan: positive when [plan] is
  /// higher (an upgrade), negative when lower. `0` while [_currentPlanId] is
  /// still unresolved, so no caller can read a false direction; [_ctaLabel]
  /// never calls this until [_currentPlanId] is non-null. Mirrors the React
  /// `PLAN_ORDER.indexOf(plan.id) - PLAN_ORDER.indexOf(currentPlanId)`, against
  /// the live [_currentPlanId] rather than a fixture.
  int _direction(Plan plan) {
    final String? planId = _currentPlanId;
    if (planId == null) return 0;
    return _planIndex(plan.id) - _planIndex(planId);
  }

  // ---------------------------------------------------------------------------
  // Price helpers
  // ---------------------------------------------------------------------------

  /// The big price label for [plan] at [cycle]: `"$<n>"`, or the "Custom" label
  /// when the plan carries no numeric price. Mirrors the React `priceLabel`.
  String _priceLabel(Plan plan, BillingCycle cycle) {
    final int? price = cycle == BillingCycle.annual
        ? plan.annual
        : plan.monthly;
    if (price == null) {
      return trans('uptizm.teams.billing_plan_price_custom');
    }
    return '\$${formatCount(price)}';
  }

  /// The under-price billing note for [plan] at the selected cycle: "Tailored to
  /// your scale" (custom), "billed annually" (annual with a discount), "free
  /// forever" (zero), else "billed monthly". Mirrors the React ternary.
  String _billingNote(Plan plan) {
    if (plan.monthly == null) {
      return trans('uptizm.teams.billing_plan_billing_custom');
    }
    // `_store == null` and not `!storePriced`: the store catalogue carries the
    // monthly SKUs only, so an annual note on a store build would describe a
    // cadence nothing there sells.
    if (_store == null && _cycle == BillingCycle.annual && plan.annual != null) {
      return trans('uptizm.teams.billing_plan_billing_annual');
    }
    if (plan.monthly == 0) {
      return trans('uptizm.teams.billing_plan_billing_free');
    }
    return trans('uptizm.teams.billing_plan_billing_monthly');
  }

  /// The renewal/description cycle word for [cycle].
  String _cycleLabel(BillingCycle cycle) {
    return switch (cycle) {
      BillingCycle.monthly => trans(
        'uptizm.teams.billing_renewal_cycle_monthly',
      ),
      BillingCycle.annual => trans('uptizm.teams.billing_renewal_cycle_annual'),
    };
  }

  // ---------------------------------------------------------------------------
  // Display copy the package deliberately does not carry
  // ---------------------------------------------------------------------------

  /// Formats [instant] as `"Jun 1, 2026"`, or `null` when there is no date.
  ///
  /// `magic_payments` hands `Invoice.date` and `PaymentMethod.renewalDate` over
  /// as `DateTime?` rather than as formatted strings, so this is the whole of
  /// what the move left behind: the same table, the same US day-then-year order,
  /// and the same one screen rendering it.
  ///
  /// Returns `null` rather than an empty string so a caller can tell "no date"
  /// from a formatted one with a plain `!= null` check; the invoice row, which
  /// wants a string either way, falls back with `?? ''`.
  String? _formatDate(DateTime? instant) {
    if (instant == null) return null;

    return '${_monthAbbreviations[instant.month - 1]} '
        '${instant.day}, ${instant.year}';
  }

  /// The card-expiry line for [paymentMethod] (`"08 / 27"`), or `null` when the
  /// rail reported no card.
  ///
  /// Built from the rail's own two numbers, which is what the package carries: a
  /// separator, a zero pad and a two- versus four-digit year are rendering
  /// decisions, and a package freezing them would hand every consumer the same
  /// card row. Both numbers are required, because a month with no year is not an
  /// expiry.
  String? _cardExpiry(PaymentMethod? paymentMethod) {
    final int? month = paymentMethod?.expMonth;
    final int? year = paymentMethod?.expYear;
    if (month == null || year == null) return null;

    return '${month.toString().padLeft(2, '0')} / '
        '${(year % 100).toString().padLeft(2, '0')}';
  }

  // ---------------------------------------------------------------------------
  // Plan lookup
  // ---------------------------------------------------------------------------

  /// The index of the plan with [id] in [_plans], or `0` when not found.
  int _planIndex(String id) {
    for (int i = 0; i < _plans.length; i++) {
      if (_plans[i].id == id) return i;
    }
    return 0;
  }

  /// The plan with [id] in [_plans], or `_plans.first` when not found. Only
  /// called once [_plans] is non-empty (see [_current]).
  Plan _findPlan(String id) {
    return _plans[_planIndex(id)];
  }
}
