// Hand-authored monitoring status supplement.
//
// magic's `design:sync` only emits the 17 standard semantic roles (its
// `_aliasMappings` is hardcoded and never includes the monitoring status
// families). This supplement carries the six status families
// (up/down/degraded/paused/info/ai) as Wind className tokens so views can write
// `bg-up-soft`, `text-down`, etc. directly.
//
// Hex values are computed (oklch -> sRGB) from the design source's status
// tokens in `uptizm-design/src/styles/theme.css`; mirror any change there back
// into the `colors:` reference block of DESIGN.md too. `down` deliberately
// equals `destructive` so outages and danger actions read identically.
//
// Merge into the alias map in `lib/main.dart`:
//   WindThemeData(aliases: {...designAliases, ...uptizmStatusAliases})
//
// The wind alias expander matches a whole unprefixed-or-prefixed token against
// a key, so each value is a `'<util>-[#light] dark:<util>-[#dark]'` pair.

/// Monitoring status className aliases, four per status family.
///
/// For each status `s` in up/down/degraded/paused/info/ai:
/// - `bg-<s>` / `text-<s>`: the solid tone (dots, strong text), same hex.
/// - `bg-<s>-soft`: the soft badge background.
/// - `text-<s>-soft-foreground`: the badge text on the soft background.
const Map<String, String> uptizmStatusAliases = <String, String>{
  // up: operational (green, hue 150 — distinct from the brand green hue 168)
  'bg-up': 'bg-[#30A556] dark:bg-[#45C06A]',
  'text-up': 'text-[#30A556] dark:text-[#45C06A]',
  'bg-up-soft': 'bg-[#DCF9E1] dark:bg-[#0C2E16]',
  'text-up-soft-foreground': 'text-[#197037] dark:text-[#8CE6A0]',

  // down: major outage (red, equals destructive)
  'bg-down': 'bg-[#DF202E] dark:bg-[#FF645F]',
  'text-down': 'text-[#DF202E] dark:text-[#FF645F]',
  'bg-down-soft': 'bg-[#FFE3DF] dark:bg-[#4C1010]',
  'text-down-soft-foreground': 'text-[#B71824] dark:text-[#FFAEA6]',

  // degraded: partial / degraded performance (amber)
  'bg-degraded': 'bg-[#E69825] dark:bg-[#F5AE39]',
  'text-degraded': 'text-[#E69825] dark:text-[#F5AE39]',
  'bg-degraded-soft': 'bg-[#FFECCC] dark:bg-[#412400]',
  'text-degraded-soft-foreground': 'text-[#834100] dark:text-[#FAC871]',

  // paused: no data / paused (neutral slate)
  'bg-paused': 'bg-[#79828A] dark:bg-[#999FA6]',
  'text-paused': 'text-[#79828A] dark:text-[#999FA6]',
  'bg-paused-soft': 'bg-[#F1F3F6] dark:bg-[#23272B]',
  'text-paused-soft-foreground': 'text-[#555D65] dark:text-[#AAB1B7]',

  // info: maintenance / informational (blue)
  'bg-info': 'bg-[#207FE8] dark:bg-[#53A0FF]',
  'text-info': 'text-[#207FE8] dark:text-[#53A0FF]',
  'bg-info-soft': 'bg-[#DBEFFF] dark:bg-[#00265D]',
  'text-info-soft-foreground': 'text-[#005DD1] dark:text-[#B0D4FF]',

  // ai: AI-generated surfaces (indigo, off both brand and info)
  'bg-ai': 'bg-[#6E59E2] dark:bg-[#9E8AFA]',
  'text-ai': 'text-[#6E59E2] dark:text-[#9E8AFA]',
  // on-ai: foreground for content sitting on a solid `bg-ai` surface (mirrors
  // on-primary / on-destructive). White reads on the indigo in both modes.
  'text-on-ai': 'text-[#FFFFFF] dark:text-[#FFFFFF]',
  'bg-ai-soft': 'bg-[#ECE8FF] dark:bg-[#2B195A]',
  'text-ai-soft-foreground': 'text-[#5F40D5] dark:text-[#D6D0FF]',
  // ai-wash: a paler fill than `ai-soft`, used as the AI banner background so
  // the full-strength `ai-soft` glyph tile still reads against it. Computed as
  // `ai-soft` at 50% over the page surface (light #F9FAFB / dark #07090C),
  // matching the React `from-ai-soft/50 to-surface` gradient as a flat tint
  // (Wind cannot apply an opacity modifier to an alias token).
  'bg-ai-wash': 'bg-[#F2F1FD] dark:bg-[#191133]',
  // border-ai-soft: the soft ai border (same hex as `bg-ai-soft`). Without this
  // key the wind expander leaves `border-ai-soft` unresolved and the border
  // falls back to the default (white) color.
  'border-ai-soft': 'border-[#ECE8FF] dark:border-[#2B195A]',
  // ---------------------------------------------------------------------------
  // Brand primary in the two families design:sync does not emit.
  //
  // It emits `bg-primary`, `text-on-primary` and `bg-primary-container`, so a
  // primary-coloured LABEL or BORDER had no alias. Those two still rendered,
  // because wind falls back to the `primary` MaterialColor in `designColors`,
  // and that fallback is the light hex in BOTH modes: a selected tab underline
  // and a selected-option border came out #008560 on the near-black dark
  // canvas. These keys shadow the fallback with the real DESIGN.md pair.
  // ---------------------------------------------------------------------------
  // border-ai: the solid ai border (same hex as `bg-ai`). The AI card draws an
  // ai-toned edge on a neutral surface and the assistant panel an edge on the
  // solid ai one; both wrote `border-ai`, which is not a key, so wind dropped it
  // and they rendered the default border instead. `border-ai-soft` already
  // existed for the pale variant, which is why the gap read as a typo nobody saw.
  'border-ai': 'border-[#6E59E2] dark:border-[#9E8AFA]',
  // border-destructive: the danger border. `bg-destructive` and
  // `text-on-destructive` were emitted; the `border-` peer was not, so a field's
  // error state wrote `border-bg-destructive` (a body the border parser cannot
  // read) and painted nothing.
  'border-destructive': 'border-[#DF202E] dark:border-[#FF645F]',
  // bg-fg-disabled: the disabled foreground used as a FILL. design:sync emits
  // the `text-` role only, so the status-page preview's browser-chrome dots
  // painted nothing at all.
  'bg-fg-disabled': 'bg-[#D1D5DA] dark:bg-[#3A4147]',
  // bg-fg-muted: the muted foreground used as a FILL, for a mark that has to be
  // visible without claiming a status. design:sync emits the `text-` role only,
  // and `bg-fg-disabled` is far too pale for a dot: at #D1D5DA it is within a
  // shade of `border-color-border`, so a node drawn with it disappears into the
  // rail it sits on.
  'bg-fg-muted': 'bg-[#555D65] dark:bg-[#AAB1B7]',
  // text-on-warning: the foreground for content on a solid `bg-warning`.
  // design:sync emits `on-primary` and `on-destructive` but no warning peer, so
  // the one surface that needed it (the modal's warning button) carried
  // `text-white`, which is 2.2:1 on the amber and unreadable in both modes.
  // Near-black in both, because the amber is light in both.
  'text-on-warning': 'text-[#07090C] dark:text-[#07090C]',
  'text-primary': 'text-[#008560] dark:text-[#00C292]',
  'border-primary': 'border-[#008560] dark:border-[#00C292]',
  // text-destructive: the destructive foreground, same hex as `bg-destructive`
  // and therefore as `text-down`. design:sync emits `bg-destructive` and
  // `text-on-destructive` but no `text-` peer, and magic_starter's theme
  // derivation asks for exactly this role (`_windRole(theme,
  // 'text-destructive')`) when it builds the modal and form error styles. With
  // the key absent the starter fell through to its own stock red instead of
  // uptizm's, so every modal and form error message was off-palette.
  'text-destructive': 'text-[#DF202E] dark:text-[#FF645F]',
  // ---------------------------------------------------------------------------
  // Translucent surface fills, for the glass bars and the assistant scrim.
  //
  // `bg-surface/80` does NOT work: the alias expander matches a token's whole
  // body against the map, so `bg-surface/80` misses the `bg-surface` key and
  // falls through to the background parser, which strips the `/80` and asks
  // `isValidColor('surface')`. That is false (surface is an alias, not a
  // registered MaterialColor family; only `primary` is one), so the parser
  // returns null and NOTHING PAINTS AT ALL. The same trap is described for
  // `bg-ai-wash` above, which solves it by flattening to a solid colour.
  //
  // Flattening is wrong here: these fills sit over a BackdropFilter and have to
  // stay translucent or they hide the blur they exist to soften. So the opacity
  // modifier is kept, but moved onto an ARBITRARY hex, which the parser can
  // resolve without consulting the alias map at all. An 8-digit AARRGGBB hex
  // would be the tidier spelling and does NOT work: `hexToColor` accepts one,
  // but `_backgroundColorRegex` only admits 3 or 6 digits, so the value never
  // reaches it and nothing paints. The hexes are the page surface (light
  // #F9FAFB, dark #07090C), which is what `bg-surface` itself expands to.
  // ---------------------------------------------------------------------------
  'bg-surface-glass-95': 'bg-[#F9FAFB]/95 dark:bg-[#07090C]/95',
  'bg-surface-glass-90': 'bg-[#F9FAFB]/90 dark:bg-[#07090C]/90',
  'bg-surface-glass-80': 'bg-[#F9FAFB]/80 dark:bg-[#07090C]/80',
  'bg-surface-scrim': 'bg-[#F9FAFB]/30 dark:bg-[#07090C]/30',
};
