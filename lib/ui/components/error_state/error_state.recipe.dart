// ErrorState has no variant axes — its layout is static. This file maintains
// the canonical 4-file atomic-component shape.
//
// Ported 1:1 from the design lab `error-state.variants.ts`: the failure sibling
// of EmptyState. A centered stack of a BARE down-toned (red) alert glyph (no
// circle background), a title, an optional description, and a retry action.

/// Root container className for the uptizm [ErrorState].
String errorStateRootClassName() =>
    'flex flex-col items-center justify-center gap-2 py-12 px-6 text-center';

/// Icon wrapper className: a small bottom margin and the `down` (red) tone, NO
/// circular background (the design renders the alert glyph bare).
String errorStateIconWrapClassName() => 'mb-1 text-down';

/// Icon className: a 32px (`size-8`) `down`-toned alert glyph.
String errorStateIconClassName() => 'text-3xl text-down';

/// Title className: the focal message, in the neutral foreground (not red).
String errorStateTitleClassName() => 'text-sm font-medium text-fg';

/// Description className: muted, capped at `max-w-sm` so long copy wraps.
String errorStateDescriptionClassName() => 'text-sm text-fg-muted max-w-sm';

/// Action wrapper className: extra top margin above the retry action.
String errorStateActionClassName() => 'mt-2';
