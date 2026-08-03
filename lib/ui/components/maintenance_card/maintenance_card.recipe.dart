import 'package:magic/magic.dart';

/// The phase axis key for the maintenance card recipe.
const String kMaintenanceCardPhaseAxis = 'phase';

/// Builds the maintenance card [WindRecipe].
///
/// Deliberately the same shape as `incidentCardRecipe`: the recipe covers ONLY
/// the left accent stripe, a 6px vertical bar, while the card shell, header,
/// title and meta rows are styled inline in the component from fixed semantic
/// tokens. A maintenance row is the sibling of an incident row, so it reuses
/// that language instead of inventing a second one.
///
/// The stripe encodes the window's PHASE, not its impact. Impact needs no axis
/// here: the product pins a maintenance window's status-page impact to `info`
/// (see the incident-create form's maintenance kind), so every window would map
/// to one colour and the stripe would carry nothing. Phase is what an operator
/// scans the list for.
///
/// Phase -> stripe token:
/// - `upcoming` / `active`: `bg-info`, the maintenance blue the badge also uses.
/// - `finished`: `bg-paused`, neutral grey. Finished work should stop drawing the
///   eye, and leaving it blue would make a spent window read as live.
const WindRecipe maintenanceCardRecipe = WindRecipe(
  base: 'absolute top-0 bottom-0 left-0 w-1.5 rounded-l-lg',
  variants: {
    kMaintenanceCardPhaseAxis: {
      'upcoming': 'bg-info',
      'active': 'bg-info',
      'finished': 'bg-paused',
    },
  },
  defaultVariants: {kMaintenanceCardPhaseAxis: 'upcoming'},
);
