import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/billing.dart';
import '../../../app/mocks/teams_data.dart';
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
/// A faithful Flutter port of the React `PlanBillingPage.tsx`: the current plan
/// with live [UsageMeter]s over [billingUsage], the tier comparison with a
/// monthly/annual [SegmentedControl] cycle toggle over a responsive grid of
/// plan cards, the on-file payment method, and the billing history.
///
/// - **Current plan card**: the active [Plan]'s name + a "Current" [Badge],
///   the renewal line, and a two-column [UsageMeter] grid over [billingUsage].
/// - **Plans section**: a centered heading and a monthly/annual cycle toggle,
///   then a responsive grid of one card per [plans] entry. Each card shows the
///   name/tagline, the price for the selected cycle (`"Custom"` when
///   [Plan.monthly] is null), the AI hero line on a soft-tone tile, the feature
///   list with a check glyph, the responder add-on line when present, a
///   "Recommended" badge when [Plan.recommended], and a CTA: "Current plan"
///   (disabled) for the active plan, else "Upgrade"/"Downgrade"/"Contact sales"
///   decided by comparing the plan's position to the current plan's. The CTA
///   only surfaces a [Magic.success] toast; nothing navigates or persists.
/// - **Payment method card**: the [paymentMethod] brand tile, the masked card
///   number + expiry, and an "Update" [Button] (mock, no-op).
/// - **Billing history card**: one row per [invoices] entry: date + number, a
///   token-tinted [InvoiceStatus] pill (a `WDiv` + `WText` mapping
///   paid -> up-soft, pending -> degraded-soft, failed -> down-soft with `dark:`
///   pairs, NOT [StatusBadge], which takes a monitoring [StatusKey]), the
///   amount, and a "Receipt" [Button] (mock, no-op).
///
/// This is a mock screen: cycle selection is the only local state; nothing
/// persists and no financial computation runs beyond selecting monthly vs
/// annual off the fixture prices.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/billing', () => const PlanBillingView());
/// ```
@immutable
class PlanBillingView extends StatefulWidget {
  /// Creates the [PlanBillingView].
  const PlanBillingView({super.key});

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

  /// The selected billing cycle. Defaults to annual (mirrors the React
  /// `useState<BillingCycle>("annual")`), so the toggle opens on the second
  /// segment and prices read the discounted annual column.
  BillingCycle _cycle = BillingCycle.annual;

  /// The active plan, resolved once from the fixtures.
  late final Plan _current = _findPlan(currentPlanId);

  @override
  Widget build(BuildContext context) {
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PageHeader(
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
  /// and a responsive two-column grid of [UsageMeter]s over [billingUsage].
  Widget _buildCurrentPlanCard() {
    return Card(
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
                    _current.name,
                    className: 'text-sm font-semibold text-fg',
                  ),
                  Badge(
                    trans('uptizm.teams.billing_plan_current_badge'),
                    tone: BadgeTone.primary,
                  ),
                ],
              ),
              WText(
                trans('uptizm.teams.billing_renewal_text', {
                  'price': _priceLabel(_current, BillingCycle.annual),
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
              for (final UsageStat stat in billingUsage)
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

  /// Builds the tier-comparison section: a centered heading + cycle toggle, then
  /// a responsive grid of one plan card per [plans] entry.
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
            SegmentedControl<BillingCycle>(
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
        WDiv(
          className: 'grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4',
          children: [
            for (final Plan plan in plans) _buildPlanCard(plan),
          ],
        ),
      ],
    );
  }

  /// Builds one plan card: name/tagline, price for the selected cycle, the AI
  /// hero tile, the feature list, an optional responder add-on line, an optional
  /// "Recommended" badge, and the CTA.
  Widget _buildPlanCard(Plan plan) {
    final bool isCurrent = plan.id == currentPlanId;
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
            child: Badge(
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
            WText(
              _billingNote(plan),
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),
        // 3. AI hero tile: the value each upgrade buys.
        WDiv(
          className: 'flex flex-row items-start gap-2 rounded-md '
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
                  WIcon(
                    _checkIcon,
                    className: 'text-[16px] text-primary',
                  ),
                  WText(
                    feature,
                    className: 'flex-1 text-sm text-fg',
                  ),
                ],
              ),
          ],
        ),
        // 5. Optional responder add-on line.
        if (plan.responderAddOn != null)
          WText(
            plan.responderAddOn!,
            className: 'text-xs text-fg-muted',
          ),
        // 6. CTA. A block column stretches the button full-width without a
        //    flex-row w-full (MUST NOT). Current plan is disabled.
        WDiv(
          className: 'flex flex-col pt-1',
          children: [
            Button(
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
  /// tile, the masked number, the expiry, and a mock "Update" [Button].
  Widget _buildPaymentMethodSection() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.teams.billing_payment_header'),
          className: 'text-sm font-semibold text-fg',
        ),
        Card(
          child: WDiv(
            className: 'flex flex-row items-center gap-4',
            children: [
              WDiv(
                className: 'grid h-9 w-12 shrink-0 place-items-center '
                    'rounded-md border border-color-border '
                    'bg-surface-container-high',
                child: WText(
                  paymentMethod.brand,
                  className: 'text-xs font-semibold text-fg',
                ),
              ),
              Expanded(
                child: WDiv(
                  className: 'flex flex-col min-w-0',
                  children: [
                    WText(
                      '•••• •••• '
                      '•••• ${paymentMethod.last4}',
                      className: 'font-mono text-sm tabular-nums text-fg',
                    ),
                    WText(
                      trans('uptizm.teams.billing_payment_expires', {
                        'date': paymentMethod.expiry,
                      }),
                      className: 'font-mono text-xs tabular-nums text-fg-muted',
                    ),
                  ],
                ),
              ),
              Button(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                onPressed: () {},
                child: WText(
                  trans('uptizm.teams.billing_payment_update_button'),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Billing history
  // ---------------------------------------------------------------------------

  /// Builds the billing-history section: a heading + a full-bleed [Card] with
  /// one row per [invoices] entry.
  Widget _buildInvoicesSection() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.teams.billing_invoices_header'),
          className: 'text-sm font-semibold text-fg',
        ),
        Card(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (final (int index, Invoice invoice) in invoices.indexed)
                _buildInvoiceRow(
                  invoice,
                  isLast: index == invoices.length - 1,
                ),
            ],
          ),
        ),
      ],
    );
  }

  /// Builds one invoice row: date + number, the status pill, the amount, and a
  /// mock "Receipt" [Button].
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
        Button(
          intent: ButtonIntent.ghost,
          size: ButtonSize.sm,
          onPressed: () {},
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
      InvoiceStatus.pending =>
        'bg-degraded-soft text-degraded-soft-foreground',
      InvoiceStatus.failed => 'bg-down-soft text-down-soft-foreground',
    };

    return WDiv(
      className: '$base $tone',
      child: WText(status.label),
    );
  }

  // ---------------------------------------------------------------------------
  // CTA + toast
  // ---------------------------------------------------------------------------

  /// Surfaces the plan-selection toast (mock: no navigation, nothing persists).
  ///
  /// A custom (Enterprise) plan routes to the "contact sales" toast; otherwise
  /// the title reflects the direction (upgrade vs switch) and the description
  /// names the selected billing cycle. Mirrors the React `selectPlan`.
  void _selectPlan(Plan plan) {
    // 1. Custom tier: hand off to sales, no cycle-based copy.
    if (plan.monthly == null) {
      Magic.success(
        trans('uptizm.teams.billing_toast_contact_title'),
        trans('uptizm.teams.billing_toast_contact_description'),
      );
      return;
    }

    // 2. Priced tier: title by direction, description by cycle.
    final bool isUpgrade = _direction(plan) > 0;
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
  }

  /// Resolves the CTA label for [plan] against the current plan.
  ///
  /// "Current plan" for the active tier; "Contact sales" for a custom tier;
  /// otherwise "Upgrade" (higher tier) or "Downgrade" (lower tier). Mirrors the
  /// React `ctaLabel`.
  String _ctaLabel(Plan plan) {
    if (plan.id == currentPlanId) {
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
  /// `PLAN_ORDER.indexOf(plan.id) - PLAN_ORDER.indexOf(currentPlanId)`.
  int _direction(Plan plan) {
    return _planIndex(plan.id) - _planIndex(currentPlanId);
  }

  // ---------------------------------------------------------------------------
  // Price helpers
  // ---------------------------------------------------------------------------

  /// The big price label for [plan] at [cycle]: `"$<n>"`, or the "Custom" label
  /// when the plan carries no numeric price. Mirrors the React `priceLabel`.
  String _priceLabel(Plan plan, BillingCycle cycle) {
    final int? price = cycle == BillingCycle.annual ? plan.annual : plan.monthly;
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
      BillingCycle.monthly => trans('uptizm.teams.billing_renewal_cycle_monthly'),
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

  /// The index of the plan with [id] in [plans], or `0` when not found.
  int _planIndex(String id) {
    for (int i = 0; i < plans.length; i++) {
      if (plans[i].id == id) return i;
    }
    return 0;
  }

  /// The plan with [id], or `plans.first` when not found (mirrors the private
  /// `_findPlan` in `billing.dart`, which is not exported).
  Plan _findPlan(String id) {
    return plans[_planIndex(id)];
  }
}
