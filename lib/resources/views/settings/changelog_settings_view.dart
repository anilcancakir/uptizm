import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// **Changelog settings sub-page (`/settings/changelog`).**
///
/// There is no backend endpoint serving a release history yet (confirmed:
/// none in `backend/routes/api.php`), and Uptizm has not shipped a version
/// against a real changelog, so this page has nothing honest to bind to. It
/// renders an [MSEmptyState] instead of inventing a release list, mirroring
/// the same dashed-border empty-state idiom used by the monitors and status
/// page list views.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/changelog', () => const ChangelogSettingsView());
/// ```
@immutable
class ChangelogSettingsView extends StatelessWidget {
  /// Creates the [ChangelogSettingsView].
  const ChangelogSettingsView({super.key});

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.changelog_title'),
      subtitle: trans('uptizm.settings.changelog_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        WDiv(
          className: 'rounded-xl border border-dashed border-color-border',
          child: MSEmptyState(
            title: trans('uptizm.settings.changelog_empty_title'),
            description: trans('uptizm.settings.changelog_empty_description'),
          ),
        ),
      ],
    );
  }
}
