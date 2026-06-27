import 'package:magic/magic.dart';

/// The impact axis key for the incident card recipe
/// (`IncidentImpact.statusKey.name`).
const String kIncidentCardImpactAxis = 'impact';

/// Builds the incident card [WindRecipe].
///
/// The recipe covers only the left accent stripe: a 6px wide vertical bar
/// whose color encodes customer-facing [IncidentImpact]. The card shell,
/// header, title, and meta rows are styled inline in [IncidentCard] using
/// fixed semantic tokens.
///
/// Impact -> stripe token mapping (solid status tone):
/// - down:     `bg-down`     (outage red)
/// - degraded: `bg-degraded` (warning amber)
/// - info:     `bg-info`     (maintenance blue)
const WindRecipe incidentCardRecipe = WindRecipe(
  base: 'absolute top-0 bottom-0 left-0 w-1.5 rounded-l-lg',
  variants: {
    kIncidentCardImpactAxis: {
      'down': 'bg-down',
      'degraded': 'bg-degraded',
      'info': 'bg-info',
    },
  },
  defaultVariants: {kIncidentCardImpactAxis: 'down'},
);
