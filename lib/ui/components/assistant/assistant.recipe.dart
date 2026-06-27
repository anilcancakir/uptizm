import 'package:magic/magic.dart';

/// The role axis key for the assistant message-bubble recipe.
const String kAssistantRoleAxis = 'role';

/// Builds the assistant FAB [WindRecipe].
///
/// The floating action button uses the `ai` token family: a solid `bg-ai`
/// circle with a light glyph. Per PORTING.md §6 the design lab's arbitrary
/// drop shadow is replaced by a hairline `ai`-toned border (the [Assistant]
/// widget adds Material elevation on top); per §7 the hover-scale affordance
/// becomes native press feedback supplied by [WButton]/[WAnchor].
///
/// Emission order: `base`.
const WindRecipe assistantFabRecipe = WindRecipe(
  base:
      'flex items-center justify-center size-14 rounded-full '
      'bg-ai text-on-ai border border-ai',
);

/// Builds the assistant surface (panel) [WindRecipe].
///
/// The opened assistant surface is a contained card with `2xl` rounding (the
/// design lab's `rounded-2xl` panel) and a hairline border over a high-opacity
/// surface fallback (`bg-surface/95`) so it stays legible when the [Assistant]
/// composites it over a [BackdropFilter] blur (PORTING.md §4). All internal
/// layout (header, list, chips, input bar) is applied inline in [Assistant].
///
/// Emission order: `base`.
const WindRecipe assistantSurfaceRecipe = WindRecipe(
  base:
      'flex flex-col w-80 max-w-full overflow-hidden rounded-2xl '
      'bg-surface/95 border border-color-border',
);

/// Builds the assistant chat-bubble [WindRecipe].
///
/// A user bubble reads with the brand primary; an assistant bubble reads with
/// the muted surface container. Geometry mirrors the design lab bubble:
/// `rounded-2xl`, `px-3.5 py-2.5`, relaxed leading, capped at `max-w-[85%]` so
/// a long reply wraps rather than spanning the surface. The role axis drives
/// the tone.
///
/// Emission order: `base ++ role-variant`.
const WindRecipe assistantBubbleRecipe = WindRecipe(
  base: 'rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed max-w-[85%]',
  variants: {
    kAssistantRoleAxis: {
      'user': 'bg-primary text-on-primary',
      'assistant': 'bg-surface-container text-fg',
    },
  },
  defaultVariants: {kAssistantRoleAxis: 'assistant'},
);
