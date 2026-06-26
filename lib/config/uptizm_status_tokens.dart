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
  'bg-ai-soft': 'bg-[#ECE8FF] dark:bg-[#2B195A]',
  'text-ai-soft-foreground': 'text-[#5F40D5] dark:text-[#D6D0FF]',
};
