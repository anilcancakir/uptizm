import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/web_links.dart';

/// **Help & support settings sub-page (`/settings/help`).**
///
/// A single "Contact support" [MSCard] whose two buttons open the website's
/// Contact page through [WebLinks.contact] and [Launch.url], the same
/// mechanism `UptizmHubExtras` already uses for the legal rows.
///
/// This page used to also carry a search input, an in-app FAQ accordion, and
/// an external-links card (Documentation/API/Community). All three are gone:
///
/// - The FAQ accordion duplicated answers the marketing site's own FAQ page
///   already derives from config (region count, plan limits, retention). A
///   hand-written Dart copy goes stale the moment that config changes, so
///   the website is the one source now.
/// - The external-links card pointed at Documentation/API/Community pages
///   that do not exist on the marketing site (`backend/routes/marketing.php`
///   registers only `privacy`, `terms`, `contact` and `faq`); inventing a
///   URL for a page that is not there is worse than not offering the row.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/help', () => const HelpSettingsView());
/// ```
@immutable
class HelpSettingsView extends StatelessWidget {
  /// Creates the [HelpSettingsView].
  const HelpSettingsView({super.key});

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.help_title'),
      subtitle: trans('uptizm.settings.help_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        MSCard(
          title: trans('uptizm.settings.help_contact_heading'),
          child: WDiv(
            className: 'flex flex-col gap-3',
            children: [
              WText(
                trans('uptizm.settings.help_contact_note'),
                className: 'text-sm text-fg-muted',
              ),
              WDiv(
                className: 'flex flex-row flex-wrap gap-2',
                children: [
                  MSButton(
                    onPressed: () => Launch.url(WebLinks.contact),
                    child: WText(
                      trans('uptizm.settings.help_contact_email_button'),
                    ),
                  ),
                  MSButton(
                    intent: ButtonIntent.secondary,
                    onPressed: () => Launch.url(WebLinks.contact),
                    child: WText(
                      trans('uptizm.settings.help_contact_chat_button'),
                    ),
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
