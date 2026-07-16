import 'package:magic/magic.dart';

/// The status axis key for the status badge recipe (`StatusKey.<value>.name`).
const String kStatusBadgeStatusAxis = 'status';

/// The size axis key for the status badge recipe (`StatusBadgeSize.<value>.name`).
const String kStatusBadgeSizeAxis = 'size';

/// Builds the status badge [WindSlotRecipe] using the monitoring status token
/// families from `lib/config/uptizm_status_tokens.dart`.
///
/// Two slots:
/// - `root` — the pill container (soft background + text + layout)
/// - `dot` — the leading solid circle (solid status color)
///
/// Emission order per slot: `base ++ size-variant ++ status-variant`.
///
/// Status -> token pair mapping:
/// - up:       root `bg-up-soft text-up-soft-foreground`,  dot `bg-up`
/// - down:     root `bg-down-soft text-down-soft-foreground`, dot `bg-down`
/// - degraded: root `bg-degraded-soft text-degraded-soft-foreground`, dot `bg-degraded`
/// - paused:   root `bg-paused-soft text-paused-soft-foreground`, dot `bg-paused`
/// - pending:  root `bg-paused-soft text-paused-soft-foreground`, dot `bg-paused` (neutral, awaiting first check)
/// - info:     root `bg-info-soft text-info-soft-foreground`, dot `bg-info`
/// - ai:       root `bg-ai-soft text-ai-soft-foreground`, dot `bg-ai`
///
/// Size -> geometry mapping (mirrors `status-badge.variants.ts`):
/// - sm: root `gap-1.5 px-2 py-0.5 text-xs`, dot `size-1.5`
/// - md: root `gap-2 px-2.5 py-1 text-sm`, dot `size-2`
const WindSlotRecipe statusBadgeRecipe = WindSlotRecipe(
  slots: {
    'root': 'flex flex-row items-center rounded-full font-medium',
    'dot': 'shrink-0 rounded-full',
  },
  variants: {
    kStatusBadgeSizeAxis: {
      'sm': {'root': 'gap-1.5 px-2 py-0.5 text-xs', 'dot': 'size-1.5'},
      'md': {'root': 'gap-2 px-2.5 py-1 text-sm', 'dot': 'size-2'},
    },
    kStatusBadgeStatusAxis: {
      'up': {'root': 'bg-up-soft text-up-soft-foreground', 'dot': 'bg-up'},
      'down': {
        'root': 'bg-down-soft text-down-soft-foreground',
        'dot': 'bg-down',
      },
      'degraded': {
        'root': 'bg-degraded-soft text-degraded-soft-foreground',
        'dot': 'bg-degraded',
      },
      'paused': {
        'root': 'bg-paused-soft text-paused-soft-foreground',
        'dot': 'bg-paused',
      },
      // Pending (awaiting first check) reuses the neutral paused palette: a
      // monitor with no verdict yet reads as neutral grey, not maintenance blue.
      'pending': {
        'root': 'bg-paused-soft text-paused-soft-foreground',
        'dot': 'bg-paused',
      },
      'info': {
        'root': 'bg-info-soft text-info-soft-foreground',
        'dot': 'bg-info',
      },
      'ai': {'root': 'bg-ai-soft text-ai-soft-foreground', 'dot': 'bg-ai'},
    },
  },
  defaultVariants: {kStatusBadgeStatusAxis: 'up', kStatusBadgeSizeAxis: 'sm'},
);
