import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/ai_confidence.dart';
import '../ai_confidence_badge/index.dart';
import 'ai_insight.recipe.dart';

/// **AI Insight — Inline Annotation**
///
/// A small, reusable AI annotation marked with a sparkle glyph and the `ai`
/// token family. Ported from the React `AiInsight` component 1:1.
///
/// Two tones (via [tone]):
/// - `inline` (default) — quiet sparkle + muted text; no background/border;
///   for quiet asides next to charts and fields.
/// - `banner` — prominent ai-soft card with a rounded-xl border and a glyph
///   tile; for fleet summaries and postmortem drafts.
///
/// Optional slots:
/// - [label] — a bold lead-in shown before [child] (e.g. `"Uptizm AI"`).
/// - [confidence] — an [AiConfidenceBadge] rendered in the meta row below the
///   text. Omit to suppress the badge entirely.
/// - [action] — a trailing control (e.g. a button) placed alongside the badge
///   in the meta row. The meta row is omitted when both [confidence] and
///   [action] are null.
///
/// ### Example Usage:
///
/// ```dart
/// // Quiet inline aside (default tone)
/// AiInsight(
///   child: WText('Suggested bounds: warn at 400 ms, critical at 900 ms.'),
/// )
///
/// // Banner card with confidence badge
/// AiInsight(
///   tone: 'banner',
///   label: 'This week',
///   confidence: AiConfidence.medium,
///   child: WText('99.97% uptime across 50 monitors.'),
/// )
/// ```
@immutable
class AiInsight extends StatelessWidget {
  /// The tone variant. Accepts `'banner'` or `'inline'` (default).
  final String? tone;

  /// Optional bold lead-in displayed before [child].
  final String? label;

  /// Optional confidence level rendered as an [AiConfidenceBadge] in the meta
  /// row. When `null` no badge is shown.
  final AiConfidence? confidence;

  /// Optional trailing control placed in the meta row alongside [confidence].
  final Widget? action;

  /// The insight body text or rich child widget.
  final Widget child;

  /// Creates an [AiInsight] widget.
  const AiInsight({
    super.key,
    this.tone,
    this.label,
    this.confidence,
    this.action,
    required this.child,
  });

  /// Resolves all slot classNames from the recipe for the current [tone].
  Map<String, String> _resolveClasses() {
    return aiInsightRecipe(variants: {kAiInsightToneAxis: tone ?? 'inline'});
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve all slot classNames from the recipe once.
    final classes = _resolveClasses();
    final rootClass = classes['root'] ?? '';
    final glyphWrapClass = classes['glyphWrap'] ?? '';
    final glyphClass = classes['glyph'] ?? '';
    final bodyClass = classes['body'] ?? '';
    final textClass = classes['text'] ?? '';
    final metaClass = classes['meta'] ?? '';

    // 2. Determine whether the meta row should be rendered.
    final bool hasMeta = confidence != null || action != null;

    // 3. Build root: a Wind container with an explicit Flutter Column body so
    //    text wraps at bounded width on narrow columns.
    return WDiv(
      className: rootClass,
      children: [
        // Sparkle glyph wrap.
        _buildGlyphWrap(glyphWrapClass, glyphClass),

        // Body column: text paragraph + optional meta row.
        WDiv(
          className: bodyClass,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Text paragraph; label is an optional bold lead-in.
              _buildTextParagraph(textClass),

              // Meta row: confidence badge + action (omitted when both null).
              if (hasMeta) _buildMeta(metaClass),
            ],
          ),
        ),
      ],
    );
  }

  /// Builds the sparkle glyph wrapped in its toned container.
  ///
  /// The glyph's scaling is clamped because the banner tone puts it inside a
  /// fixed `size-8` tile: the glyph is a text character, so it grows with the
  /// reader's text size while its box does not, and on an iPhone at
  /// `accessibility-extra-large` that overflowed the tile and clipped the
  /// sparkle. The tile is a visual anchor for the card rather than content, so
  /// the box is what has to hold; 1.3 still grows it for someone who asked for
  /// larger text, and the sentence beside it scales without a cap.
  Widget _buildGlyphWrap(String wrapClass, String gClass) {
    return WDiv(
      className: wrapClass,
      child: MediaQuery.withClampedTextScaling(
        maxScaleFactor: 1.3,
        child: WText('✦', className: gClass),
      ),
    );
  }

  /// Builds the text paragraph, optionally prefixed with a bold [label].
  ///
  /// When a [label] is present and [child] is a [WText], the label and body are
  /// rendered as ONE inline paragraph (a bold lead-in span followed by the body
  /// text), mirroring the React `<p><span>{label}</span>{children}</p>`. This
  /// keeps wrapped lines flush against the left edge instead of indenting them
  /// past the label, which a `Row` + `Expanded` layout would do.
  Widget _buildTextParagraph(String textClass) {
    if (label == null) {
      return WDiv(className: textClass, child: child);
    }

    final body = child;
    if (body is WText) {
      // textClass publishes the tone text style via DefaultTextStyle; the body
      // span inherits it, the label span adds the medium weight on top.
      return WDiv(
        className: textClass,
        child: Builder(
          builder: (context) {
            final base = DefaultTextStyle.of(context).style;
            return Text.rich(
              TextSpan(
                children: [
                  TextSpan(
                    text: '$label ',
                    style: base.copyWith(fontWeight: FontWeight.w500),
                  ),
                  TextSpan(text: body.data, style: base),
                ],
              ),
              textAlign: TextAlign.left,
            );
          },
        ),
      );
    }

    // Non-text child: stack the bold label above the arbitrary child.
    return WDiv(
      className: textClass,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          WText('$label ', className: 'font-medium text-fg'),
          child,
        ],
      ),
    );
  }

  /// Builds the meta row containing the optional [AiConfidenceBadge] and the
  /// optional [action] widget.
  Widget _buildMeta(String metaClass) {
    return WDiv(
      className: metaClass,
      children: [
        if (confidence != null) AiConfidenceBadge(confidence!),
        ?action,
      ],
    );
  }
}
