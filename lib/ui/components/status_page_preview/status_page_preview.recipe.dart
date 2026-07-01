import '../../../app/mocks/status.dart';

/// Root shell className for [StatusPagePreview].
///
/// A centered, max-width column with generous vertical rhythm, mirroring the
/// design source's `mx-auto flex max-w-2xl flex-col gap-8`. This is the frame
/// the editor's live pane and the full-screen public route both render into.
const String statusPagePreviewShellClassName =
    'mx-auto w-full max-w-2xl flex flex-col gap-8';

/// Section heading className shared by every labelled section.
///
/// Matches the design source's
/// `mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground`.
const String statusPagePreviewSectionHeadingClassName =
    'text-xs font-medium uppercase tracking-wide text-fg-muted';

/// Bordered container className wrapping the component-status rows.
///
/// Mirrors `rounded-xl border border-border bg-surface px-6 py-2`. The design
/// source's `bg-surface` (its page canvas) maps to Uptizm's `bg-surface`.
const String statusPagePreviewComponentsBoxClassName =
    'rounded-xl border border-color-border bg-surface px-6 py-2';

/// Metric-cell className in the live-metrics grid.
///
/// Mirrors `rounded-xl border border-border bg-surface p-4`.
const String statusPagePreviewMetricCellClassName =
    'rounded-xl border border-color-border bg-surface p-4';

/// Single past-incident row className.
///
/// Mirrors `flex items-center gap-3 rounded-lg border border-border bg-surface p-4`.
const String statusPagePreviewIncidentRowClassName =
    'flex flex-row items-center gap-3 rounded-lg border border-color-border bg-surface p-4';

/// Dashed empty placeholder className when no components are assigned.
///
/// Mirrors
/// `rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground`.
const String statusPagePreviewEmptyPlaceholderClassName =
    'rounded-xl border border-dashed border-color-border px-4 py-8 text-center text-sm text-fg-muted';

/// Subscribe panel shell className.
///
/// Mirrors `rounded-xl border border-border bg-surface p-5`.
const String statusPagePreviewSubscribeBoxClassName =
    'rounded-xl border border-color-border bg-surface p-5';

/// Visual tone of the overall-status banner for a given [StatusKey].
///
/// Carries the three color classNames the banner needs: the soft background
/// [box], the soft-foreground [text] (label + mono timestamp), and the solid
/// [dot] color. Every classNames pair is drawn from the hand-authored status
/// families in `uptizm_status_tokens.dart`, each of which already resolves to a
/// `light dark:` pair, so the banner honors the token-and-dark-pair rule.
///
/// The design source colors the banner border with a `border-<status>-soft`
/// token; those border tokens do not exist in the Uptizm status supplement
/// (only `border-ai-soft` does), so the component pairs the soft fill with the
/// neutral `border-color-border` hairline instead of an unresolved token that
/// would silently render white.
class StatusPageBannerTone {
  /// Soft background classNames, e.g. `bg-up-soft`.
  final String box;

  /// Soft-foreground text classNames, e.g. `text-up-soft-foreground`.
  final String text;

  /// Solid dot classNames, e.g. `bg-up`.
  final String dot;

  /// Overall-status label, e.g. `All systems operational`.
  final String label;

  const StatusPageBannerTone({
    required this.box,
    required this.text,
    required this.dot,
    required this.label,
  });
}

/// Overall-status banner tone per status, mirroring the design source's
/// `BANNER` record.
///
/// The design source folds `paused` into muted tokens and `ai` into the `up`
/// tone; this map keeps each status on its own family (paused uses the
/// `paused-soft` family) so the banner stays token-faithful, and treats `ai`
/// like `up` (an all-clear page owned by AI reads as operational) exactly as the
/// source does.
const Map<StatusKey, StatusPageBannerTone> statusPageBannerTones =
    <StatusKey, StatusPageBannerTone>{
      StatusKey.up: StatusPageBannerTone(
        box: 'bg-up-soft',
        text: 'text-up-soft-foreground',
        dot: 'bg-up',
        label: 'All systems operational',
      ),
      StatusKey.degraded: StatusPageBannerTone(
        box: 'bg-degraded-soft',
        text: 'text-degraded-soft-foreground',
        dot: 'bg-degraded',
        label: 'Degraded performance',
      ),
      StatusKey.down: StatusPageBannerTone(
        box: 'bg-down-soft',
        text: 'text-down-soft-foreground',
        dot: 'bg-down',
        label: 'Major outage',
      ),
      StatusKey.info: StatusPageBannerTone(
        box: 'bg-info-soft',
        text: 'text-info-soft-foreground',
        dot: 'bg-info',
        label: 'Maintenance in progress',
      ),
      StatusKey.paused: StatusPageBannerTone(
        box: 'bg-paused-soft',
        text: 'text-paused-soft-foreground',
        dot: 'bg-paused',
        label: 'Some components paused',
      ),
      StatusKey.ai: StatusPageBannerTone(
        box: 'bg-up-soft',
        text: 'text-up-soft-foreground',
        dot: 'bg-up',
        label: 'All systems operational',
      ),
    };
