import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/settings.dart';

/// **Language settings sub-page (`/settings/language`).**
///
/// A faithful Flutter port of the React `LanguageSettingsPage.tsx`: an
/// iOS-style list of [appLanguages], one tappable [SettingsRow] per entry,
/// with a checkmark [trailing] on the selected row.
///
/// Selection is local-state only: tapping a row updates the selection and
/// shows a [Magic.success] toast; nothing persists (matches the app-wide mock
/// convention for settings).
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/language', () => const LanguageSettingsView());
/// ```
@immutable
class LanguageSettingsView extends StatefulWidget {
  /// Creates the [LanguageSettingsView].
  const LanguageSettingsView({super.key});

  @override
  State<LanguageSettingsView> createState() => _LanguageSettingsViewState();
}

class _LanguageSettingsViewState extends State<LanguageSettingsView> {
  /// The currently selected language code. Defaults to English, mirroring the
  /// React source's `localStorage.getItem(STORAGE_KEY) ?? "en"` fallback.
  String _selectedCode = 'en';

  /// Selects [language] and shows a confirmation toast (mock: nothing
  /// persists).
  void _select(AppLanguage language) {
    setState(() => _selectedCode = language.code);
    Magic.success(trans('uptizm.settings.language_title'), language.native);
  }

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.language_title'),
      subtitle: trans('uptizm.settings.language_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        MSSettingsSection(
          children: [
            for (final AppLanguage language in appLanguages)
              MSSettingsRow(
                title: language.native,
                subtitle: language.label,
                onTap: () => _select(language),
                trailing: language.code == _selectedCode
                    ? const WIcon(Icons.check, className: 'text-primary')
                    : null,
              ),
          ],
        ),
      ],
    );
  }
}
