import 'package:magic/magic.dart';

/// The role axis key for the assistant message-bubble recipe.
const String kAssistantRoleAxis = 'role';

/// The surface-mode axis key for the assistant surface recipe.
const String kAssistantSurfaceModeAxis = 'mode';

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
/// The assistant surface is a contained card with `2xl` rounding (the design
/// lab's `rounded-2xl` panel) and a hairline border. Two modes:
///
/// - `floating` (default) — the overlay panel: a fixed-width card over a
///   high-opacity surface fallback (`bg-surface/95`) so it stays legible when
///   the [Assistant] composites it over a [BackdropFilter] blur (PORTING.md §4).
/// - `embedded` — the static catalog/preview panel: full width (capped by a
///   Flutter constraint to the design lab's `max-w-sm`) on a solid `bg-surface`,
///   mirroring the React `panelEmbedded` slot. No blur sits behind it.
///
/// All internal layout (header, list, chips, input bar) is applied inline in
/// [Assistant].
///
/// Emission order: `base ++ mode-variant`.
const WindRecipe assistantSurfaceRecipe = WindRecipe(
  base: 'flex flex-col overflow-hidden rounded-2xl border border-color-border',
  variants: {
    kAssistantSurfaceModeAxis: {
      'floating': 'w-80 max-w-full bg-surface/95',
      'embedded': 'w-full bg-surface',
    },
  },
  defaultVariants: {kAssistantSurfaceModeAxis: 'floating'},
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
      // The system's own voice, not the assistant's. It carries the sentence
      // shown when no model produced an answer (today: the team is over its
      // daily AI allowance), and it has to read as the product speaking rather
      // than as something Uptizm AI worked out. Muted foreground on the page
      // canvas plus a hairline, so it recedes instead of competing with the
      // replies around it.
      'system':
          'bg-surface text-fg-muted border border-color-border-subtle',
    },
  },
  defaultVariants: {kAssistantRoleAxis: 'assistant'},
);
