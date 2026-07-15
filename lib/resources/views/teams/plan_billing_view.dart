import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/billing_types.dart' show Plan;
import '../../../app/support/team_types.dart'
    show Invoice, PaymentMethod, UsageStat;
import '../../../app/mocks/billing.dart' show currentPlanId;
import '../../../app/enums/invoice_status.dart' show InvoiceStatus;
import '../../../app/services/billing/billing_service.dart';
import '../../../ui/components/usage_meter/usage_meter.dart';
import '../../../ui/layouts/page_container.dart';

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
///   plan's position to the current plan's. On web, a priced-tier CTA starts
///   a live Stripe Checkout session via [BillingService.checkout]; on mobile
///   it surfaces the deferred-billing message instead of erroring (store
///   rails deferred, see `BillingServiceIo`). The custom (Enterprise) tier
///   only surfaces a "contact sales" toast; nothing navigates or persists
///   there.
/// - **Payment method card**: the fetched [PaymentMethod]'s brand tile, the
///   masked card number + expiry + renewal date, and an "Update" [Button]
///   that opens the Stripe billing portal via [BillingService.openPortal].
///   Fetched independently of the rest of the screen (the only Stripe-live
///   read, soft-failing server-side to an all-null payload), so it carries
///   its own loading/error state and never blocks the rest of the screen.
/// - **Billing history card**: one row per fetched [Invoice]: date + number, a
///   token-tinted [InvoiceStatus] pill (a `WDiv` + `WText` mapping
///   paid -> up-soft, pending -> degraded-soft, failed -> down-soft with `dark:`
///   pairs, NOT [StatusBadge], which takes a monitoring [StatusKey]), the
///   amount, and a "Receipt" [Button] that opens the Stripe billing portal.
///
/// The current-plan id is sourced from the live `GET /billing` entitlement
/// (via [BillingService.currentEntitlement]), falling back to the design-lab
/// fixture ([currentPlanId]) until the read resolves or on failure. Every
/// other section (plan catalog, usage, payment method, invoices) is fully
/// live; every read degrades to its last-known state (empty before the first
/// successful fetch) on failure instead of throwing out of `initState`.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/billing', () => const PlanBillingView());
/// ```
@immutable
class PlanBillingView extends StatefulWidget {
  /// Creates the [PlanBillingView].
  ///
  /// [billingService] overrides [BillingService.instance] for tests; omit it
  /// in production to get the platform-resolved singleton.
  const PlanBillingView({super.key, this.billingService});

  /// Injectable [BillingService], overriding [BillingService.instance].
  @visibleForTesting
  final BillingService? billingService;

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

  /// The route the back affordance returns to.
  static const String _backFallback = '/';

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

  /// The [BillingService] resolved once for the entitlement read + checkout
  /// action: [PlanBillingView.billingService] when injected (tests), else the
  /// platform-resolved [BillingService.instance].
  late final BillingService _billing =
      widget.billingService ?? BillingService.instance;

  /// The active plan id. Seeded from the design-lab fixture ([currentPlanId])
  /// and republished by [_loadEntitlement] once the live `GET /billing` read
  /// resolves; keeps the fixture id as last-known state on any failure.
  String _currentPlanId = currentPlanId;

  /// The plan catalog from `GET /billing/plans`, cheapest-to-priciest as
  /// served by the backend. Empty until [_loadPlans] resolves; stays empty
  /// (last-known state) on a fetch failure.
  List<Plan> _plans = const [];

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
  /// is still empty (loading, or the catalog fetch failed).
  Plan? get _current => _plans.isEmpty ? null : _findPlan(_currentPlanId);

  @override
  void initState() {
    super.initState();
    _loadEntitlement();
    _loadPlans();
    _loadUsage();
    _loadInvoices();
    _loadPaymentMethod();
  }

  /// Reads the team's current plan from `GET /billing` via
  /// [BillingService.currentEntitlement] and republishes [_currentPlanId].
  ///
  /// Deliberate degradation on failure (network error, non-2xx, malformed
  /// payload): keeps the fixture plan id as last-known state instead of
  /// throwing, mirroring `MonitorController.reload`'s read-path convention, so
  /// a transient read failure never crashes this screen.
  Future<void> _loadEntitlement() async {
    try {
      final BillingEntitlement entitlement = await _billing
          .currentEntitlement();
      final String? plan = entitlement.plan;
      if (plan == null || !mounted) return;
      setState(() => _currentPlanId = plan);
    } catch (_) {
      // Deliberate degradation: keeps the fixture plan id as last-known
      // state (see the docblock above) instead of throwing.
    }
  }

  /// Reads the plan catalog from `GET /billing/plans` via
  /// [BillingService.getPlans] and republishes [_plans].
  ///
  /// Deliberate degradation on failure: [_plans] stays empty (last-known
  /// state before the first successful fetch), so the current-plan card and
  /// the plans grid render their loading/empty state instead of crashing.
  Future<void> _loadPlans() async {
    try {
      final List<Plan> plans = await _billing.getPlans();
      if (!mounted) return;
      setState(() => _plans = plans);
    } catch (_) {
      // Deliberate degradation: see the docblock above.
    }
  }

  /// Reads the team's usage stats from `GET /billing/usage` via
  /// [BillingService.getUsage] and republishes [_usage].
  ///
  /// Deliberate degradation on failure: [_usage] stays empty, so the meter
  /// grid simply renders no rows instead of crashing.
  Future<void> _loadUsage() async {
    try {
      final List<UsageStat> usage = await _billing.getUsage();
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
    return PageContainer(
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
  /// stats. Renders a loading skeleton while [_current] is `null` (the plan
  /// catalog has not resolved yet, or its fetch failed).
  Widget _buildCurrentPlanCard() {
    final Plan? current = _current;
    if (current == null) {
      return MSCard(
        child: WDiv(
          className: 'flex flex-col gap-5',
          children: const [
            MSSkeleton(shape: SkeletonShape.text, width: 160, height: 20),
            MSSkeleton(height: 16, width: 220),
          ],
        ),
      );
    }

    return MSCard(
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: [
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
                trans('uptizm.teams.billing_renewal_text', {
                  'price': _priceLabel(current, BillingCycle.annual),
                  'cycle': trans('uptizm.teams.billing_renewal_cycle_annual'),
                  'date': 'Jul 1, 2026',
                }),
                className: 'text-sm text-fg-muted',
              ),
            ],
          ),
          WDiv(
            className: 'grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2',
            children: [
              for (final UsageStat stat in _usage)
                UsageMeter(
                  label: stat.label,
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

  // ---------------------------------------------------------------------------
  // Plans section
  // ---------------------------------------------------------------------------

  /// Builds the tier-comparison section: a centered heading + cycle toggle,
  /// then a responsive grid of one plan card per fetched plan (a loading
  /// skeleton grid while [_plans] is still empty).
  Widget _buildPlansSection() {
    return WDiv(
      className: 'flex flex-col gap-5',
      children: [
        WDiv(
          className: 'flex flex-col items-center gap-2 text-center',
          children: [
            WText(
              trans('uptizm.teams.billing_plans_heading'),
              className: 'text-lg font-semibold text-fg',
            ),
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
      ],
    );
  }

  /// Builds one plan card: name/tagline, price for the selected cycle, the AI
  /// hero tile, the feature list, an optional responder add-on line, an optional
  /// "Recommended" badge, and the CTA.
  Widget _buildPlanCard(Plan plan) {
    final bool isCurrent = plan.id == _currentPlanId;
    final bool isCustom = plan.monthly == null;

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
  Widget _buildPaymentMethodSection() {
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

  /// Builds the payment-method card's body for its three states: loading
  /// skeleton, error text, or the resolved (possibly all-null) card.
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
    final String? last4 = paymentMethod?.last4;
    final String? expiry = paymentMethod?.expiry;

    return WDiv(
      className: 'flex flex-row items-center gap-4',
      children: [
        WDiv(
          className:
              'grid h-9 w-12 shrink-0 place-items-center '
              'rounded-md border border-color-border '
              'bg-surface-container-high',
          child: WText(
            paymentMethod?.brand ?? trans('common.unknown'),
            className: 'text-xs font-semibold text-fg',
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
        MSButton(
          intent: ButtonIntent.secondary,
          size: ButtonSize.sm,
          onPressed: () => _openBillingPortal(),
          child: WText(trans('uptizm.teams.billing_payment_update_button')),
        ),
      ],
    );
  }

  /// Opens the Stripe billing portal via [BillingService.openPortal],
  /// surfacing the same deferred/failure toasts as [_selectPlan]'s checkout
  /// path. Shared by the payment-method "Update" button and every invoice
  /// row's "Receipt" button (both are Stripe-portal actions; the portal
  /// itself deep-links a customer straight to their invoice history).
  Future<void> _openBillingPortal() async {
    try {
      await _billing.openPortal(returnUrl: '$_webOrigin/teams/billing');
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

  /// Builds one invoice row: date + number, the status pill, the amount, and
  /// a "Receipt" [Button] that opens the Stripe billing portal.
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
                invoice.date,
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
  Widget _buildStatusPill(InvoiceStatus status) {
    const String base =
        'flex flex-row items-center rounded-full px-2 py-0.5 '
        'text-xs font-medium';

    final String tone = switch (status) {
      InvoiceStatus.paid => 'bg-up-soft text-up-soft-foreground',
      InvoiceStatus.pending => 'bg-degraded-soft text-degraded-soft-foreground',
      InvoiceStatus.failed => 'bg-down-soft text-down-soft-foreground',
    };

    return WDiv(className: '$base $tone', child: WText(status.label));
  }

  // ---------------------------------------------------------------------------
  // CTA + toast
  // ---------------------------------------------------------------------------

  /// Selects [plan]: hands off to sales for the custom tier, otherwise starts
  /// a live Stripe Checkout session via [BillingService.checkout] for
  /// [plan.id], the backend `Plan` enum value (`'pro'`/`'business'`; the
  /// backend rejects anything else with a 422).
  ///
  /// The monthly/annual [_cycle] toggle is local display state only: the
  /// backend plan map is cycle-agnostic (one Stripe price per plan, no
  /// monthly/annual price dimension yet), so it is never encoded into the
  /// checkout payload (see the `### Deviations` note on this step).
  ///
  /// On web, [BillingService.checkout] opens the returned checkout URL in an
  /// in-app browser tab; this only surfaces the local confirmation toast
  /// afterward. On mobile, [BillingService.checkout] throws
  /// [UnsupportedPlatformException] (store rails deferred, see
  /// `BillingServiceIo`), surfaced here as a friendly info toast rather than
  /// an error. Any other [BillingException] (a failed request) surfaces an
  /// error toast; neither failure is swallowed silently. Mirrors the React
  /// `selectPlan`.
  Future<void> _selectPlan(Plan plan) async {
    // 1. Custom tier: hand off to sales, no live billing call.
    if (plan.monthly == null) {
      Magic.success(
        trans('uptizm.teams.billing_toast_contact_title'),
        trans('uptizm.teams.billing_toast_contact_description'),
      );
      return;
    }

    // 2. Priced tier: start checkout for the plan, redirecting Stripe back to
    //    this screen on completion/abort.
    final bool isUpgrade = _direction(plan) > 0;
    try {
      await _billing.checkout(
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

  /// Resolves the CTA label for [plan] against the current plan.
  ///
  /// "Current plan" for the active tier; "Contact sales" for a custom tier;
  /// otherwise "Upgrade" (higher tier) or "Downgrade" (lower tier). Mirrors the
  /// React `ctaLabel`.
  String _ctaLabel(Plan plan) {
    if (plan.id == _currentPlanId) {
      return trans('uptizm.teams.billing_plan_button_current');
    }
    if (plan.monthly == null) {
      return trans('uptizm.teams.billing_plan_button_contact');
    }
    return _direction(plan) > 0
        ? trans('uptizm.teams.billing_plan_button_upgrade')
        : trans('uptizm.teams.billing_plan_button_downgrade');
  }

  /// The tier distance of [plan] from the current plan: positive when [plan] is
  /// higher (an upgrade), negative when lower. Mirrors the React
  /// `PLAN_ORDER.indexOf(plan.id) - PLAN_ORDER.indexOf(currentPlanId)`, against
  /// the live [_currentPlanId] rather than the static fixture.
  int _direction(Plan plan) {
    return _planIndex(plan.id) - _planIndex(_currentPlanId);
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
    return '\$${_formatCount(price)}';
  }

  /// The under-price billing note for [plan] at the selected cycle: "Tailored to
  /// your scale" (custom), "billed annually" (annual with a discount), "free
  /// forever" (zero), else "billed monthly". Mirrors the React ternary.
  String _billingNote(Plan plan) {
    if (plan.monthly == null) {
      return trans('uptizm.teams.billing_plan_billing_custom');
    }
    if (_cycle == BillingCycle.annual && plan.annual != null) {
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

  /// Formats an integer with thousands separators: `1000 -> "1,000"`.
  String _formatCount(int n) {
    final String digits = n.abs().toString();
    final StringBuffer buffer = StringBuffer();
    for (int i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) buffer.write(',');
      buffer.write(digits[i]);
    }
    return n < 0 ? '-$buffer' : buffer.toString();
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
