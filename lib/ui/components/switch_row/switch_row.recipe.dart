import 'package:magic/magic.dart';

/// Builds the [WindRecipe] for the SwitchRow layout.
///
/// `flex-1 min-w-0` on the label, not `min-w-0` alone, and that difference is
/// the whole reason this component exists. Wind flex makes every child greedy:
/// the switch is a fixed 44pt and an unbounded label sizes to its natural
/// width, so the row measures wider than its column and overflows. `min-w-0`
/// does nothing on its own, because there is no flex wrapper to shrink INTO;
/// `flex-1` is what gives the label a share to shrink within.
///
/// Measured on 2026-09-06 at 390pt: the escalation editor's two toggles
/// ("Onaylanana kadar son basamağı tekrarla", "Yeni izleyiciler için varsayılan
/// politika olarak kullan") overflowed by 2.2 pixels. Turkish labels run longer
/// than the English ones that first fit, so this is a localisation-sensitive
/// layout bug: it is invisible in English and it is the operator's own language
/// that trips it.
///
/// `items-start`, not `items-center`: once the label can wrap, a two-line label
/// beside a one-line switch reads correctly only when both align to the top.
WindRecipe switchRowRecipe() {
  return const WindRecipe(
    base: 'w-full flex flex-row items-start gap-3',
  );
}

/// The className the label inside a [SwitchRow] carries.
///
/// Separate from the row recipe because it belongs to the CHILD, and keeping it
/// here is what stops a caller reconstructing the broken `min-w-0`-only version
/// by hand. See [switchRowRecipe] for why `flex-1` is load-bearing.
const String switchRowLabelClassName = 'flex-1 min-w-0 text-sm text-fg';
