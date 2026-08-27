import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import '../../../app/support/brand_contrast.dart';

import '../../../app/support/status_page_support.dart' show pageUrl, worstStatus;
import '../../../app/support/status_page_types.dart' show PublicComponent;
import '../../../app/enums/status_key.dart';
import '../../../app/models/status_page.dart';
import '../component_status_row/index.dart';
import 'status_page_preview.recipe.dart';

/// **The public status page, rendered in-app.**
///
/// A faithful mockup of the (backend-rendered) public status page, driven
/// entirely by a [StatusPage]. It is embedded twice: in the editor's live
/// preview pane (a brand-framed live draft) and by the standalone full-screen
/// public route. Ported 1:1 in structure from the design source
/// `StatusPagePreview.tsx`.
///
/// Top-to-bottom it renders: a brand header, an overall-status banner, the
/// component list (or a dashed empty placeholder), an optional subscribe box,
/// and a footer.
///
/// Every component and the banner tone come from [StatusPage.components], the
/// page's own eager-loaded pivot. This preview previously carried two further
/// sections, a live-metrics grid and a past-incidents list, both resolved
/// through design-lab fixtures: they showed invented metrics and incidents that
/// belonged to no real monitor, so they were removed rather than left to
/// misinform the operator. The authoritative view remains the backend-rendered
/// page at `/s/{slug}`, one tap away via the editor's "View public page".
///
/// Brand color and logo come from the model; all component health reads through
/// the semantic status tokens so it looks right regardless of the brand tint.
/// The only raw color anywhere is [StatusPage.brandColor] (the logo tile and the
/// subscribe button), which is content data.
///
/// ### Example Usage:
///
/// ```dart
/// StatusPagePreview(config: page)
/// ```
@immutable
class StatusPagePreview extends StatelessWidget {
  /// The status page to render.
  final StatusPage config;

  /// Creates a [StatusPagePreview] for the given [config].
  const StatusPagePreview({super.key, required this.config});

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the config into its published components and overall status,
    //    both from the model's own eager-loaded pivot.
    final List<PublicComponent> components = config.components;
    final StatusKey? overall = worstStatus(components);

    // 2. Column scaffold: an explicit Flutter Column bounds each leaf section to
    //    the max-w-2xl frame so rows and grids lay out cleanly (a Wind flex-col
    //    would hand descendants an unbounded-width regime).
    return WDiv(
      className: statusPagePreviewShellClassName,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _buildBrandHeader(),
          const SizedBox(height: 32),
          _buildBanner(overall),
          const SizedBox(height: 32),
          _buildComponents(components),
          if (config.subscriptionsEnabled) ...[
            const SizedBox(height: 32),
            _buildSubscribe(),
          ],
          const SizedBox(height: 32),
          _buildFooter(),
        ],
      ),
    );
  }

  // -- 1. Brand header -------------------------------------------------------

  Widget _buildBrandHeader() {
    // The logo tile: a brand-tinted rounded square with the fallback initials in
    // white. brandColor is content data (the design source's inline
    // `style={{ background: brandColor }}`), applied through WDiv.backgroundColor
    // (the Team.color / sidebar-avatar precedent), NOT a semantic token.
    final String logoText = config.logoText ?? '';
    final String name = config.name ?? '';
    final String initials = logoText.isNotEmpty
        ? logoText
        : (name.isNotEmpty ? name.substring(0, 1) : 'S');

    return WDiv(
      className: 'flex flex-row items-center gap-2',
      children: [
        WDiv(
          backgroundColor: config.brandColor,
          className: 'size-7 rounded-md flex items-center justify-center',
          // Derived, not `text-white`: the tile's background is a colour the
          // OPERATOR chose, so no semantic token can be right for all of it and
          // white initials on a light brand read as nothing.
          child: WText(
            initials,
            className: 'text-sm font-bold',
            textStyle: TextStyle(color: foregroundOn(config.brandColor)),
          ),
        ),
        WText(
          name.isNotEmpty ? name : trans('uptizm.status.preview_default_name'),
          className: 'text-base font-semibold tracking-tight text-fg',
        ),
      ],
    );
  }

  // -- 2. Overall banner -----------------------------------------------------

  /// Builds the overall-status banner, or the "nothing published" banner when
  /// [overall] is null.
  ///
  /// A page with no components has measured nothing, so it must not borrow the
  /// operational tone. Falling back to [StatusKey.up] here is what let an
  /// unconfigured page announce "All systems operational".
  Widget _buildBanner(StatusKey? overall) {
    if (overall == null) {
      return WDiv(
        className:
            'flex flex-row items-center gap-3 rounded-xl border '
            'border-color-border px-5 py-4 bg-surface-container',
        // flex-1 so the sentence takes the available width and wraps instead of
        // overflowing the row: the Turkish string is longer than the English
        // one, and a fixed-width child would clip whichever is longer.
        child: WText(
          trans('uptizm.status.preview_no_components_banner'),
          className: 'flex-1 text-sm font-semibold text-fg-muted',
        ),
      );
    }

    final StatusPageBannerTone tone =
        statusPageBannerTones[overall] ?? statusPageBannerTones[StatusKey.up]!;

    // Solid banner dot bound to a 10px box (a childless WDiv collapses to zero
    // size in Wind); the label grows and pushes the mono timestamp to the right.
    return WDiv(
      className:
          'flex flex-row items-center gap-3 rounded-xl border border-color-border '
          'px-5 py-4 ${tone.box}',
      children: [
        SizedBox(
          width: 10,
          height: 10,
          child: WDiv(className: 'size-2.5 rounded-full ${tone.dot}'),
        ),
        WText(tone.label, className: 'text-sm font-semibold ${tone.text}'),
        WDiv(
          className: 'flex-1',
          child: WText(
            trans('uptizm.status.preview_updated_ago'),
            className: 'text-right font-mono text-xs ${tone.text}',
          ),
        ),
      ],
    );
  }

  // -- 3. Components ---------------------------------------------------------

  Widget _buildComponents(List<PublicComponent> components) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WText(
          trans('uptizm.status.preview_components_heading'),
          className: statusPagePreviewSectionHeadingClassName,
        ),
        const SizedBox(height: 8),
        if (components.isNotEmpty)
          WDiv(
            className: statusPagePreviewComponentsBoxClassName,
            children: [
              for (final PublicComponent component in components)
                ComponentStatusRow(
                  name: component.name,
                  status: component.status,
                  segments: component.segments,
                  uptimeLabel: component.uptime,
                ),
            ],
          )
        else
          WText(
            trans('uptizm.status.preview_components_empty'),
            className: statusPagePreviewEmptyPlaceholderClassName,
          ),
      ],
    );
  }

  // -- 4. Subscribe ----------------------------------------------------------

  Widget _buildSubscribe() {
    return WDiv(
      className: statusPagePreviewSubscribeBoxClassName,
      children: [
        WText(
          trans('uptizm.status.preview_subscribe_heading'),
          className: 'text-sm font-semibold text-fg',
        ),
        const SizedBox(height: 4),
        WText(
          trans('uptizm.status.preview_subscribe_description'),
          className: 'text-sm text-fg-muted',
        ),
        const SizedBox(height: 12),
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            Expanded(
              child: MSInput(
                type: InputType.email,
                placeholder: trans('uptizm.status.preview_subscribe_placeholder'),
                className: 'max-w-xs',
              ),
            ),
            _buildSubscribeButton(),
          ],
        ),
      ],
    );
  }

  Widget _buildSubscribeButton() {
    // The design source paints this button with the raw brand color
    // (`style={{ background: brandColor }}`). Neither magic_starter's Button nor
    // WButton exposes a raw-Color background, so the brand surface is a
    // WDiv(backgroundColor: brandColor) (the sanctioned content-color path) made
    // tappable by a WButton wrapper. The tap is a no-op: this is a mockup.
    return WButton(
      onTap: () {},
      child: WDiv(
        backgroundColor: config.brandColor,
        className: 'rounded-md px-4 py-2 flex items-center justify-center',
        child: WText(
          trans('uptizm.status.preview_subscribe_button'),
          className: 'text-sm font-medium',
          textStyle: TextStyle(color: foregroundOn(config.brandColor)),
        ),
      ),
    );
  }

  // -- 5. Footer -------------------------------------------------------------

  Widget _buildFooter() {
    return WText(
      '${pageUrl(config)} · ${trans('uptizm.status.preview_powered_by')}',
      className: 'text-center font-mono text-xs text-fg-muted',
    );
  }
}
