import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'upgrade_nudge.recipe.dart';

/// **Inline upsell shown where a plan limit bites.**
///
/// Names the tier that unlocks the gated feature and offers an upgrade action.
/// Uses the `ai` accent (the upgrades that matter in uptizm are mostly AI
/// depth). Ported from the design lab `UpgradeNudge`; the source gradient wash
/// is adapted to a neutral fill + ai-soft lock tile (see the recipe).
///
/// ### Example:
/// ```dart
/// UpgradeNudge(
///   message: "You've reached your 3-responder limit.",
///   requiredPlan: 'Business',
///   onUpgrade: () => MagicRoute.to('/teams/billing'),
/// )
/// ```
@immutable
class UpgradeNudge extends StatelessWidget {
  /// What is gated — shown as the headline.
  final String message;

  /// The tier that unlocks the gated feature, e.g. "Business".
  final String requiredPlan;

  /// Hide the "Upgrade" button (for tight inline spots).
  final bool compact;

  /// Tapped when "Upgrade" is pressed; the caller routes to billing.
  final VoidCallback? onUpgrade;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates an [UpgradeNudge].
  const UpgradeNudge({
    super.key,
    required this.message,
    required this.requiredPlan,
    this.compact = false,
    this.onUpgrade,
    this.className,
  });

  static const IconData _lockIcon = Icons.lock_outline;

  @override
  Widget build(BuildContext context) {
    final slots = upgradeNudgeRecipe(variants: const <String, String>{});

    return WDiv(
      className: className == null
          ? slots['root']
          : '${slots['root']} $className',
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Lock tile (the ai signal).
          WDiv(
            className: slots['tile'],
            child: WIcon(_lockIcon, className: 'text-ai text-lg'),
          ),
          const SizedBox(width: 12),

          // Headline + plan line.
          Expanded(
            child: WDiv(
              className: 'flex flex-col gap-0.5',
              children: [
                WText(message, className: slots['message']),
                WText(
                  trans('uptizm.common.upgrade_available_on', {
                    'plan': requiredPlan,
                  }),
                  className: slots['sub'],
                ),
              ],
            ),
          ),

          // Upgrade action (omitted in compact mode).
          if (!compact) ...[
            const SizedBox(width: 12),
            MSButton(
              intent: ButtonIntent.primary,
              size: ButtonSize.sm,
              onPressed: onUpgrade,
              child: WText(trans('uptizm.common.upgrade')),
            ),
          ],
        ],
      ),
    );
  }
}
