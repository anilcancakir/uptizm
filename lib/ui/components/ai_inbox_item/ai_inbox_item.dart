import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/models/incident.dart';
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
///   verdict: shown ONLY when the model read this as no real deviation
///   actions: Open incident (primary) + Dismiss (ghost)
/// ```
///
/// ### The verdict line
///
/// The model answers whether the evidence reads as a real deviation, separately
/// from how confident it is about the label it wrote. A negative answer does not
/// remove the anomaly (the statistics fired, and they are the source of truth),
/// so the row still appears; what it changes is that the backend declines to
/// open the incident autonomously and leaves the call to a person. Saying so on
/// the card is what makes that a decision the operator can make: without it, a
/// row the model disputed looks exactly like one it stood behind.
///
/// It is deliberately quiet, muted text rather than a status colour, matching
/// how the incident detail view states a degraded analysis. A caveat about our
/// own output is not a monitoring alarm and must not borrow the vocabulary of
/// one.
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
  /// Affordance glyph on the expand control.
  static const IconData _expandIcon = Icons.keyboard_arrow_down;

  /// Glyph marking the model's own caveat about the row it wrote.
  static const IconData _verdictIcon = Icons.info_outline;

  /// The incident carrying the AI analysis data.
  final Incident incident;

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
              // The model's caveat, present ONLY when it disputed the anomaly.
              // Conditionally in the list rather than conditionally visible: a
              // wind flex gap reserves a slot for a child that renders nothing,
              // so an empty widget here would open a double gap on every other
              // row. `== false` and not `!`, because null is "no model ran" and
              // must stay silent.
              if (incident.ai!.confirmed == false) _buildVerdict(),
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

  /// Builds the model's own caveat line: this anomaly fired statistically, and
  /// the model that labeled it does not read the evidence as a real deviation.
  ///
  /// A plain Flutter [Row] with an [Expanded] text, copying
  /// `AiAnalysisCard._buildActionCard`, because that is the shape already proven
  /// here for a glyph beside a sentence that has to wrap. A wind flex row makes
  /// its children greedy instead, which is what overflows the header when a
  /// [Row] is used there.
  Widget _buildVerdict() {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WIcon(_verdictIcon, className: 'text-sm text-fg-muted'),
        const SizedBox(width: 8),
        Expanded(
          child: WText(
            trans('uptizm.ai.unconfirmed'),
            className: 'text-xs text-fg-muted',
          ),
        ),
      ],
    );
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
                _expandIcon,
                className: 'text-sm text-fg-muted',
              ),
            ],
          ),
        ),
      ],
    );
  }
}
