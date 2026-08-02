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

  /// Maximum probe regions selectable per monitor; `null` = unlimited.
  ///
  /// Mirrors the backend's `PlanGate::maxRegionsPerMonitor()`
  /// (`config('plans.tiers.*.limits.regions')`), the allowance
  /// `StoreMonitorRequest::withValidator` enforces on the delta between the
  /// submitted region count and the monitor's already-stored count.
  ///
  /// Defaulted (not required): every existing [PlanLimits] call site predates
  /// this field, and an absent wire value or an un-updated fixture should read
  /// as unlimited rather than force every constructor site to be touched.
  final int? regions;

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
    this.regions,
    required this.ai,
    required this.whiteLabel,
    required this.privatePages,
    required this.sso,
  });

  /// Decodes a [PlanLimits] from a `GET /billing/plans` catalog entry's
  /// nested `limits` object (`backend/config/plans.php`'s `tiers.*.limits`).
  ///
  /// A missing/unrecognized `ai` wire value falls back to [AiLevel.inbox]
  /// (the lowest capability), mirroring the safe-fallback shape every other
  /// `fromWire` decoder in this codebase uses.
  factory PlanLimits.fromMap(Map<String, dynamic> map) {
    return PlanLimits(
      monitors: (map['monitors'] as num?)?.toInt(),
      checkIntervalSec: (map['check_interval_sec'] as num?)?.toInt() ?? 0,
      statusPages: (map['status_pages'] as num?)?.toInt(),
      subscribers: (map['subscribers'] as num?)?.toInt(),
      responders: (map['responders'] as num?)?.toInt(),
      regions: (map['regions'] as num?)?.toInt(),
      ai: _aiLevelFromWire(map['ai'] as String?),
      whiteLabel: (map['white_label'] as bool?) ?? false,
      privatePages: (map['private_pages'] as bool?) ?? false,
      sso: (map['sso'] as bool?) ?? false,
    );
  }
}

/// Decodes the `GET /billing/plans` `limits.ai` wire string into an
/// [AiLevel], falling back to [AiLevel.inbox] on an absent/unrecognized value.
AiLevel _aiLevelFromWire(String? wire) {
  return switch (wire) {
    'inbox' => AiLevel.inbox,
    'analysis' => AiLevel.analysis,
    'auto' => AiLevel.auto,
    'custom' => AiLevel.custom,
    _ => AiLevel.inbox,
  };
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

  /// Decodes a [Plan] from a `GET /billing/plans` catalog entry
  /// (`backend/config/plans.php`'s `tiers` array, served verbatim under the
  /// `data` envelope; see `BillingController::plans()`).
  ///
  /// The `currency` wire field is not decoded: the view renders every price
  /// as a bare `"$<n>"` (no currency-aware formatting), so it carries no
  /// field this value object needs.
  factory Plan.fromMap(Map<String, dynamic> map) {
    final Object? rawLimits = map['limits'];
    final Object? rawFeatures = map['features'];

    return Plan(
      id: (map['id'] as String?) ?? '',
      name: (map['name'] as String?) ?? '',
      tagline: (map['tagline'] as String?) ?? '',
      monthly: (map['monthly'] as num?)?.toInt(),
      annual: (map['annual'] as num?)?.toInt(),
      aiLine: (map['ai_line'] as String?) ?? '',
      features: rawFeatures is List
          ? rawFeatures.whereType<String>().toList()
          : const [],
      responderAddOn: map['responder_add_on'] as String?,
      recommended: (map['recommended'] as bool?) ?? false,
      limits: PlanLimits.fromMap(
        rawLimits is Map<String, dynamic> ? rawLimits : const {},
      ),
    );
  }
}
