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
/// - resolved: `bg-up`       (over, and the service is fine now)
///
/// `resolved` is not an impact and that is the point of it being here. The
/// stripe encodes what a CUSTOMER is living through, and a closed incident is
/// not something anybody is living through, so painting one in outage red made
/// a list of three incidents read as three outages when one was ongoing.
///
/// Green rather than grey, which was the first attempt: grey says "archived"
/// and green says "this is over and the service is fine", and the second is
/// the thing a reader actually wants off a status list at a glance. The impact
/// badge beside it still states what the incident WAS, so the archive keeps
/// its severity; only the shouting stops.
const WindRecipe incidentCardRecipe = WindRecipe(
  base: 'absolute top-0 bottom-0 left-0 w-1.5 rounded-l-lg',
  variants: {
    kIncidentCardImpactAxis: {
      'down': 'bg-down',
      'degraded': 'bg-degraded',
      'info': 'bg-info',
      'resolved': 'bg-up',
    },
  },
  defaultVariants: {kIncidentCardImpactAxis: 'down'},
);
