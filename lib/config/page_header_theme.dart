// The page-header sub-theme uptizm layers over magic_starter's derived one.
//
// It lives here rather than inline in `main.dart` because its tokens are
// responsive: the padding, the type step, the divider and the gap all change at
// `lg`, and a `lg:` prefix that resolves to nothing is invisible on the surface
// it was written for. A const in a config file is reachable from a widget test,
// so the breakpoint behaviour can be measured at both widths instead of assumed.
//
// Applied in `main.dart` via `MagicStarter.usePageHeaderTheme`, AFTER
// `useWindTheme` (later setters win).

import 'package:magic_starter/magic_starter.dart'
    show MagicStarterPageHeaderTheme;

/// uptizm's page-header styling: one tight row on a phone, roomier from `lg`.
///
/// magic_starter derives this sub-theme from uptizm's palette, but sizes it for
/// a desktop and hands the same values to a phone: a 24px bold title over a
/// wrapping two-line subtitle, 16px of padding on all four sides inside a page
/// container that already pays the gutter, and a full-width rule under it. On a
/// 402pt screen that spent roughly a third of the first viewport before any
/// monitoring data appeared.
///
/// Mobile therefore runs at the DESIGN.md `title-lg` step (18px semibold) and
/// clamps the title and the URL to one line each, while the rule under the
/// header stays at every width: it is what ends the header section, and without
/// it a title floats over the first card. Every other value steps back up at
/// `lg`.
///
/// [MagicStarterPageHeaderTheme.inlineActions] is on, so a header is one row on
/// every surface. It is the same decision as the tokens above rather than a
/// separate one: the stacked layout is what pushed a single `New monitor` button
/// onto a row of its own under the title, and [HeaderAction] renders that action
/// as a glyph beside the title instead.
const MagicStarterPageHeaderTheme uptizmPageHeaderTheme =
    MagicStarterPageHeaderTheme(
  containerClassName:
      'w-full flex flex-col sm:flex-row items-start sm:items-center '
      'sm:justify-between gap-3 px-0 py-2 pb-3 border-b border-color-border '
      'lg:gap-4 lg:px-4 lg:py-3',
  containerInlineClassName:
      'w-full flex flex-row items-center justify-between gap-2 px-0 py-2 pb-3 '
      'border-b border-color-border lg:gap-4 lg:px-4 lg:py-3',
  titleClassName:
      'text-lg font-semibold text-fg truncate lg:text-2xl lg:font-bold',
  subtitleClassName: 'text-xs text-fg-muted truncate lg:text-sm',
  actionContainerClassName: 'flex flex-row items-center gap-1 lg:gap-2',
  // A 28pt box around a 20px glyph, so the chevron starts 4pt inside the page
  // gutter instead of 6. The starter's default reached for `-ml-1` to claw that
  // back, and wind has no negative margin outside the position family: it
  // reported `unknown className '-ml-1' was ignored` and the class never did
  // anything. Shrinking the box is the lever that works.
  backControlClassName:
      'flex items-center justify-center size-7 text-xl text-fg-muted '
      'hover:text-fg lg:size-9 lg:text-2xl',
  inlineActions: true,
);
