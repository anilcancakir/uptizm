import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/settings_types.dart' show LegalSection;
import '../../../app/mocks/settings.dart';

/// Privacy Policy (`/settings/privacy`): a titled legal document.
///
/// Mirrors the React `PrivacySettingsPage.tsx`, which renders the shared
/// `LegalDoc` part over its own `SECTIONS` fixture. This view inlines the
/// same layout directly (a single [Card] of heading+body sections over
/// [privacySections]) rather than extracting a shared widget, since Terms
/// is the only other caller of this exact shape.
@immutable
class PrivacySettingsView extends StatelessWidget {
  /// Creates the [PrivacySettingsView].
  const PrivacySettingsView({super.key});

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.privacy_title'),
      subtitle: 'Last updated ${trans('uptizm.settings.privacy_updated')}.',
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        MSCard(
          child: WDiv(
            className: 'flex flex-col gap-5',
            children: [
              for (final LegalSection section in privacySections)
                WDiv(
                  className: 'flex flex-col gap-1.5',
                  children: [
                    WText(
                      section.heading,
                      className: 'text-sm font-semibold text-fg',
                    ),
                    WText(
                      section.body,
                      className: 'text-sm leading-relaxed text-fg-muted',
                    ),
                  ],
                ),
            ],
          ),
        ),
      ],
    );
  }
}
