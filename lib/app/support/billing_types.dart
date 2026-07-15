import 'package:flutter/foundation.dart';

import '../enums/ai_level.dart' show AiLevel;

/// The hard caps and capability flags a billing tier enforces in-product.
///
/// `null` values indicate an unlimited resource. This is display/gating data
/// only; no payment logic lives here.
///
/// ```dart
/// if (currentLimits.checkIntervalSec <= 10) {
///   // Business or Enterprise tier.
/// }
/// ```
@immutable
class PlanLimits {
  /// Maximum number of monitors; `null` = unlimited.
  final int? monitors;

  /// Fastest allowed check interval, in seconds.
  final int checkIntervalSec;

  /// Maximum number of status pages; `null` = unlimited.
  final int? statusPages;

  /// Maximum number of status-page subscribers; `null` = unlimited.
  final int? subscribers;

  /// Responders included before the per-seat add-on; `null` = unlimited.
  final int? responders;

  /// Deepest AI capability unlocked at this tier.
  final AiLevel ai;

  /// White-label / branding removal enabled on status pages.
  final bool whiteLabel;

  /// Password-protected (private) status pages enabled.
  final bool privatePages;

  /// SSO / SAML enabled.
  final bool sso;

  const PlanLimits({
    required this.monitors,
    required this.checkIntervalSec,
    required this.statusPages,
    required this.subscribers,
    required this.responders,
    required this.ai,
    required this.whiteLabel,
    required this.privatePages,
    required this.sso,
  });
}

/// A billing tier with its display metadata and in-product limits.
///
/// ```dart
/// final pro = plans.firstWhere((p) => p.id == 'pro');
/// print('${pro.name}: ${pro.limits.checkIntervalSec}s checks');
/// ```
@immutable
class Plan {
  /// Stable machine identifier (e.g. `'free'`, `'pro'`, `'business'`, `'enterprise'`).
  final String id;

  /// Human-readable tier name.
  final String name;

  /// Short positioning tagline.
  final String tagline;

  /// Monthly price in USD when billed monthly; `null` = custom (Enterprise).
  final int? monthly;

  /// Effective price per month in USD when billed annually; `null` = custom.
  final int? annual;

  /// The one line that sells the AI value at this tier.
  final String aiLine;

  /// Gating and headline feature bullets, in upgrade order.
  final List<String> features;

  /// Per-responder add-on note; absent when the tier does not charge for extra responders.
  final String? responderAddOn;

  /// Whether this tier is visually highlighted as the recommended choice.
  final bool recommended;

  /// Hard caps and capabilities this tier enforces in-product.
  final PlanLimits limits;

  const Plan({
    required this.id,
    required this.name,
    required this.tagline,
    required this.monthly,
    required this.annual,
    required this.aiLine,
    required this.features,
    this.responderAddOn,
    this.recommended = false,
    required this.limits,
  });
}
