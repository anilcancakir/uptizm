import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart'
    show Typography, TypographyVariant;

/// Foundations preview: the design-token vocabulary the whole system is built
/// on, rendered as live swatches so the catalog shows colors and type next to
/// the components that consume them.
///
/// Every swatch is painted purely through the 17 semantic alias keys (no raw
/// hex), so it doubles as a visual assertion that the generated theme resolves
/// each role in both light and dark.
class FoundationsPreview extends StatelessWidget {
  /// Creates the foundations preview.
  const FoundationsPreview({super.key});

  /// The semantic surface/background roles and their token classNames.
  static const List<(String, String)> _surfaceTokens = <(String, String)>[
    ('surface', 'bg-surface'),
    ('surface-container', 'bg-surface-container'),
    ('surface-container-high', 'bg-surface-container-high'),
    ('primary', 'bg-primary'),
    ('primary-container', 'bg-primary-container'),
    ('accent', 'bg-accent'),
    ('destructive', 'bg-destructive'),
    ('destructive-container', 'bg-destructive-container'),
    ('success', 'bg-success'),
    ('warning', 'bg-warning'),
  ];

  /// The foreground/text roles and their token classNames.
  static const List<(String, String)> _foregroundTokens = <(String, String)>[
    ('fg', 'text-fg'),
    ('fg-muted', 'text-fg-muted'),
    ('fg-disabled', 'text-fg-disabled'),
    ('on-primary', 'text-on-primary'),
    ('on-destructive', 'text-on-destructive'),
  ];

  /// The border roles and their token classNames.
  static const List<(String, String)> _borderTokens = <(String, String)>[
    ('border', 'border-color-border'),
    ('border-subtle', 'border-color-border-subtle'),
  ];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-8',
      children: [
        _section('Colors: surfaces', _buildSurfaceSwatches()),
        _section('Colors: foreground', _buildForegroundSwatches()),
        _section('Colors: borders', _buildBorderSwatches()),
        _section('Typography', _buildTypeScale()),
      ],
    );
  }

  /// A labelled section wrapper.
  Widget _section(String title, Widget body) {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(title, className: 'text-fg text-sm font-semibold uppercase'),
        body,
      ],
    );
  }

  /// Surface swatches: a filled tile per background role.
  Widget _buildSurfaceSwatches() {
    return WDiv(
      className: 'wrap gap-3',
      children: [
        for (final (String name, String token) in _surfaceTokens)
          WDiv(
            className: 'flex flex-col gap-1 w-36',
            children: [
              WDiv(
                className: '$token h-16 rounded-lg border border-color-border',
              ),
              WText(name, className: 'text-fg-muted text-xs'),
            ],
          ),
      ],
    );
  }

  /// Foreground swatches: each role's text painted on its natural backdrop.
  Widget _buildForegroundSwatches() {
    return WDiv(
      className: 'wrap gap-3',
      children: [
        for (final (String name, String token) in _foregroundTokens)
          WDiv(
            className: token.startsWith('text-on-')
                ? (token == 'text-on-primary'
                      ? 'bg-primary px-4 py-3 rounded-lg w-44'
                      : 'bg-destructive px-4 py-3 rounded-lg w-44')
                : 'bg-surface-container px-4 py-3 rounded-lg w-44',
            child: WText(name, className: '$token text-sm font-medium'),
          ),
      ],
    );
  }

  /// Border swatches: each border role around a neutral tile.
  Widget _buildBorderSwatches() {
    return WDiv(
      className: 'wrap gap-3',
      children: [
        for (final (String name, String token) in _borderTokens)
          WDiv(
            className: 'flex flex-col gap-1 w-36',
            children: [
              WDiv(className: 'bg-surface h-16 rounded-lg border-2 $token'),
              WText(name, className: 'text-fg-muted text-xs'),
            ],
          ),
      ],
    );
  }

  /// Typography scale rendered with the Typography component.
  Widget _buildTypeScale() {
    return const WDiv(
      className: 'flex flex-col gap-2',
      children: [
        Typography('Heading 1', variant: TypographyVariant.h1),
        Typography('Heading 2', variant: TypographyVariant.h2),
        Typography('Heading 3', variant: TypographyVariant.h3),
        Typography('Body text renders the default paragraph style.'),
        Typography('Caption text', variant: TypographyVariant.caption),
      ],
    );
  }
}
