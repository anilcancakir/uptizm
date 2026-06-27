import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import '../ai_confidence_badge/index.dart';
import 'ai_inbox_item.recipe.dart';

/// **AI Inbox Row**
///
/// A card-style row for a single AI-detected anomaly in the dashboard inbox.
/// The anomaly is a soft signal Uptizm flagged from its own monitoring data
/// that has not yet met the threshold to become a full incident.
///
/// Surfaces the monitor name, a one-sentence tl;dr, an [AiConfidenceBadge],
/// a relative timestamp, and two graduated-trust actions:
///
/// - **Open incident** ([onApprove]): promote the anomaly to an incident.
/// - **Dismiss** ([onDismiss]): mark the anomaly as noise so Uptizm can refine
///   its detector. The action does NOT auto-execute; the operator must tap.
///
/// ### Row structure (mirrors `AiInboxItem.tsx`)
///
/// ```
/// root (ai-soft card, left ai stripe, overflow-hidden)
///   header: glyph + monitor name + AiConfidenceBadge + time (ml-auto)
///   summary: one-sentence tldr
///   actions: Open incident (primary) + Dismiss (ghost)
/// ```
///
/// ### Graduated-trust UX
///
/// AI suggestions are never auto-executed. Both [onApprove] and [onDismiss] are
/// callbacks supplied by the caller; this component only fires them on explicit
/// user interaction.
///
/// ### Example Usage:
///
/// ```dart
/// AiInboxItem(
///   incident: incidents.first,
///   onApprove: () => context.go('/incidents/${incident.id}'),
///   onDismiss: () {},
/// )
/// ```
@immutable
class AiInboxItem extends StatelessWidget {
  /// The incident summary carrying the AI analysis data.
  final IncidentSummary incident;

  /// Called when the operator taps the open-incident button.
  ///
  /// Does NOT fire automatically; the component only calls it on explicit tap.
  final VoidCallback? onApprove;

  /// Called when the operator taps the dismiss button.
  ///
  /// Does NOT fire automatically; the component only calls it on explicit tap.
  final VoidCallback? onDismiss;

  /// Creates an [AiInboxItem] for the given [incident].
  const AiInboxItem({
    super.key,
    required this.incident,
    this.onApprove,
    this.onDismiss,
  });

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the outer card className from the recipe.
    final String rootClass = aiInboxItemRecipe();

    // 2. A Stack: the padded content column defines the card size; a Positioned
    //    4px bar paints the full-height ai stripe at the left. Content carries
    //    pl-5 (20px) so the text/buttons clear the 4px stripe. Explicit Flutter
    //    positioning avoids both Wind's `absolute` (content overlapped) and
    //    IntrinsicHeight (Wind flex has no intrinsic-size support -> crash).
    return WDiv(
      className: rootClass,
      child: Stack(
        children: [
          WDiv(
            className: 'w-full flex flex-col gap-2 p-4 pl-5',
            children: [
              // Header: sparkle glyph + monitor name + confidence + time.
              _buildHeader(),
              // AI summary paragraph (tldr from the IncidentAi payload).
              _buildSummary(),
              // Action row: open-incident + dismiss (explicit tap only).
              _buildActions(),
            ],
          ),
          const Positioned(
            left: 0,
            top: 0,
            bottom: 0,
            width: 4,
            child: WDiv(className: 'bg-ai'),
          ),
        ],
      ),
    );
  }

  /// Builds the header row: sparkle glyph, monitor name, [AiConfidenceBadge],
  /// and relative timestamp pushed to the trailing edge via `ml-auto`.
  ///
  /// Uses a `WDiv(flex flex-wrap)` container so the badge stays shrink-wrap
  /// (non-greedy) and the row reflows on narrow columns instead of overflowing.
  Widget _buildHeader() {
    // `flex flex-wrap items-center gap-2` mirrors the React `slots.header()`.
    // `ml-auto` on the time span pushes it to the trailing edge within the
    // wrap row, matching the React `ml-auto` on the time slot.
    // A plain Flutter Row would make the Wind-badge a greedy Expanded child
    // and overflow; Wind `wrap` reflows on a narrow column instead.
    return WDiv(
      className: 'wrap items-center gap-2',
      children: [
        // Sparkle glyph marking the row as AI-generated.
        WText('✦', className: 'text-sm text-ai'),

        // Monitor name: grows to fill available space.
        WText(incident.monitorName, className: 'text-sm font-medium text-fg'),

        // Confidence badge: shrink-wrap pill; non-greedy inside the wrap row.
        AiConfidenceBadge(incident.ai!.confidence),

        // Relative time ("4m ago"): pushed to the end via ml-auto. Strip the
        // "started "/"resolved " prefix the incident carries for incident lists.
        WText(
          incident.startedAt.replaceFirst(
            RegExp(r'^(started|resolved)\s+'),
            '',
          ),
          className: 'ml-auto font-mono text-xs tabular-nums text-fg-muted',
        ),
      ],
    );
  }

  /// Builds the AI summary paragraph from [IncidentAi.tldr].
  Widget _buildSummary() {
    return WText(incident.ai!.tldr, className: 'text-sm text-fg-muted');
  }

  /// Builds the action row with open-incident and dismiss buttons.
  ///
  /// Both buttons require explicit user interaction; neither fires a callback
  /// automatically (graduated-trust principle). A [Wrap] is used instead of
  /// a [Row] so the buttons reflow to a second line on a narrow column.
  Widget _buildActions() {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        // Open incident: promote the anomaly to a full incident.
        WButton(
          onTap: onApprove,
          className:
              'flex flex-row items-center rounded-md bg-primary px-3 py-1.5 '
              'text-xs font-semibold text-on-primary',
          child: WText(
            trans('uptizm.ai.open_incident'),
            className: 'text-xs font-semibold text-on-primary',
          ),
        ),

        // Dismiss: a ghost button with a trailing chevron (the design source's
        // dropdown trigger; the reason menu is a deferred follow-up).
        WButton(
          onTap: onDismiss,
          className:
              'flex flex-row items-center gap-1 rounded-md px-3 py-1.5 '
              'text-xs font-medium text-fg-muted hover:bg-surface-container',
          child: WDiv(
            className: 'flex flex-row items-center gap-1',
            children: [
              WText(
                trans('uptizm.ai.dismiss'),
                className: 'text-xs font-medium text-fg-muted',
              ),
              WIcon(
                Icons.keyboard_arrow_down,
                className: 'text-sm text-fg-muted',
              ),
            ],
          ),
        ),
      ],
    );
  }
}
