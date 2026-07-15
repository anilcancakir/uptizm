import '../enums/ai_level.dart' show AiLevel;
import '../support/billing_types.dart' show Plan, PlanLimits;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// All billing tiers, cheapest to most expensive.
///
/// Order matters: [smallestPlanWhere] walks this list front-to-back and returns
/// the first match, so cheaper tiers appear first.
const List<Plan> plans = [
  Plan(
    id: 'free',
    name: 'Free',
    tagline: 'Kick the tires, solo projects.',
    monthly: 0,
    annual: 0,
    aiLine: 'AI anomaly inbox — see what Uptizm flags from its own checks.',
    features: [
      '10 monitors · 3-minute checks',
      '1 status page · 100 subscribers',
      '1 responder · email & Slack alerts',
      '3-day history',
    ],
    limits: PlanLimits(
      monitors: 10,
      checkIntervalSec: 180,
      statusPages: 1,
      subscribers: 100,
      responders: 1,
      ai: AiLevel.inbox,
      whiteLabel: false,
      privatePages: false,
      sso: false,
    ),
  ),
  Plan(
    id: 'pro',
    name: 'Pro',
    tagline: 'Startups and small teams that page.',
    monthly: 34,
    annual: 29,
    aiLine: 'Full AI incident analysis — evidence, confidence, citations, drafted updates.',
    features: [
      '50 monitors · 30-second checks · all regions',
      '3 status pages · 1,000 subscribers · custom domain',
      '3 responders · on-call, escalation & SMS/voice',
      'SLO error budgets · 30-day history',
    ],
    responderAddOn: '+\$9/mo per extra responder',
    recommended: true,
    limits: PlanLimits(
      monitors: 50,
      checkIntervalSec: 30,
      statusPages: 3,
      subscribers: 1000,
      responders: 3,
      ai: AiLevel.analysis,
      whiteLabel: false,
      privatePages: false,
      sso: false,
    ),
  ),
  Plan(
    id: 'business',
    name: 'Business',
    tagline: 'Scaling teams with real SLAs.',
    monthly: 119,
    annual: 99,
    aiLine: 'AI Auto mode, weekly digest & similar-incident matching.',
    features: [
      '200 monitors · 10-second checks',
      '10 status pages · 10,000 subscribers · white-label & private pages',
      '10 responders · SSO · audit log',
      '1-year history',
    ],
    responderAddOn: '+\$9/mo per extra responder',
    limits: PlanLimits(
      monitors: 200,
      checkIntervalSec: 10,
      statusPages: 10,
      subscribers: 10000,
      responders: 10,
      ai: AiLevel.auto,
      whiteLabel: true,
      privatePages: true,
      sso: true,
    ),
  ),
  Plan(
    id: 'enterprise',
    name: 'Enterprise',
    tagline: 'Custom scale, security and support.',
    monthly: null,
    annual: null,
    aiLine: 'AI with custom guardrails & dedicated capacity.',
    features: [
      'Unlimited monitors · 5-second checks · dedicated relays',
      'Unlimited status pages & subscribers · audience-specific pages',
      'Unlimited responders · SAML & SCIM · custom roles',
      'Custom retention · SLA · invoicing',
    ],
    limits: PlanLimits(
      monitors: null,
      checkIntervalSec: 5,
      statusPages: null,
      subscribers: null,
      responders: null,
      ai: AiLevel.custom,
      whiteLabel: true,
      privatePages: true,
      sso: true,
    ),
  ),
];

/// The design-lab mock team's active plan (Pro, billed annually).
const String currentPlanId = 'pro';

/// The active plan's hard caps and capability flags.
///
/// Consumers should gate features against this object:
/// ```dart
/// if (currentLimits.sso) { /* show SSO settings */ }
/// ```
final PlanLimits currentLimits = _findPlan(currentPlanId).limits;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/// Returns the plan with [id], or [plans.first] when not found.
Plan _findPlan(String id) {
  for (final plan in plans) {
    if (plan.id == id) return plan;
  }
  return plans.first;
}

/// Returns the cheapest plan whose limits satisfy [pred].
///
/// Walks [plans] cheapest-first and returns the first match. Falls back to the
/// last plan (Enterprise) when no tier satisfies the predicate.
///
/// Typical use: "upgrade to X" prompts in the product UI.
///
/// ```dart
/// final cheapest = smallestPlanWhere((l) => l.checkIntervalSec <= 10);
/// print('Need at least ${cheapest.name} for 10-second checks.');
/// ```
Plan smallestPlanWhere(bool Function(PlanLimits) pred) {
  for (final plan in plans) {
    if (pred(plan.limits)) return plan;
  }
  return plans.last;
}
