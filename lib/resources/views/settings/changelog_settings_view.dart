import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/settings.dart';

/// **Changelog settings sub-page (`/settings/changelog`).**
///
/// A faithful Flutter port of the React `ChangelogSettingsPage.tsx`: a
/// [Card] per [ChangelogRelease] with a version + date header, then each
/// [ChangelogChange] as a soft-tone [Badge] (New/Improved/Fixed) followed by
/// its text. Read-only content, no local state.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/changelog', () => const ChangelogSettingsView());
/// ```
@immutable
class ChangelogSettingsView extends StatelessWidget {
  /// Creates the [ChangelogSettingsView].
  const ChangelogSettingsView({super.key});

  /// Resolves the soft-tone badge className for a [ChangeKind].
  ///
  /// Mirrors the React `TAG` map: added -> up-soft, improved -> info-soft,
  /// fixed -> degraded-soft. Every pair carries its `dark:` counterpart via
  /// `uptizmStatusAliases`.
  static String _badgeClassName(ChangeKind kind) {
    const String base =
        'rounded-sm px-1.5 py-0.5 text-[10px] font-medium uppercase '
        'tracking-wide';

    return switch (kind) {
      ChangeKind.added => '$base bg-up-soft text-up-soft-foreground',
      ChangeKind.improved => '$base bg-info-soft text-info-soft-foreground',
      ChangeKind.fixed =>
        '$base bg-degraded-soft text-degraded-soft-foreground',
    };
  }

  /// Builds a single tagged change row: badge + text.
  Widget _buildChange(ChangelogChange change) {
    return WDiv(
      className: 'flex flex-row items-start gap-2.5',
      children: [
        MSBadge(change.kind.label, className: _badgeClassName(change.kind)),
        WText(change.text, className: 'flex-1 text-sm text-fg'),
      ],
    );
  }

  /// Builds a single release card: version + date header, then its changes.
  Widget _buildRelease(ChangelogRelease release) {
    return MSCard(
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          WDiv(
            className:
                'flex flex-row items-center justify-between gap-3 '
                'px-6 pt-6 pb-3',
            children: [
              WText(
                trans('uptizm.settings.changelog_version_label', {
                  'version': release.version,
                }),
                className: 'text-sm font-semibold text-fg',
              ),
              WText(release.date, className: 'font-mono text-xs text-fg-muted'),
            ],
          ),
          WDiv(
            className: 'flex flex-col gap-3 px-6 pb-6',
            children: [
              for (final change in release.changes) _buildChange(change),
            ],
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.changelog_title'),
      subtitle: trans('uptizm.settings.changelog_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        WDiv(
          className: 'flex flex-col gap-4',
          children: [for (final release in changelog) _buildRelease(release)],
        ),
      ],
    );
  }
}
