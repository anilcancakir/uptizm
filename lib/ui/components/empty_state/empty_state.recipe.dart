// EmptyState has no variant axes — its layout is static. This file maintains
// the canonical 4-file atomic-component shape.
//
// Ported 1:1 from the design lab `empty-state.variants.ts`: a centered stack
// of an optional BARE icon (muted, no circle background), a title, an optional
// description, and an optional action. The muted icon + description keep the
// title and action as the focal points.

/// Root container className for the uptizm [EmptyState].
String emptyStateRootClassName() =>
    'flex flex-col items-center justify-center gap-2 py-12 px-6 text-center';

/// Icon wrapper className: just a small bottom margin and the muted tone, NO
/// circular background (the design lab renders the glyph bare).
String emptyStateIconWrapClassName() => 'mb-1 text-fg-muted';

/// Icon className: a 32px (`size-8`) muted glyph.
String emptyStateIconClassName() => 'text-3xl text-fg-muted';

/// Title className: the focal message.
String emptyStateTitleClassName() => 'text-sm font-medium text-fg';

/// Description className: muted, capped at `max-w-sm` so long copy wraps.
String emptyStateDescriptionClassName() => 'text-sm text-fg-muted max-w-sm';

/// Action wrapper className: extra top margin above the primary action.
String emptyStateActionClassName() => 'mt-2';
