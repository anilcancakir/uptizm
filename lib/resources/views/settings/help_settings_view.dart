import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/settings.dart';

/// One external-resource row rendered in the links [Card].
class _HelpLink {
  /// Display label, e.g. `"Documentation"`.
  final String label;

  /// Display URL, e.g. `"uptizm.com/docs"` (mock: never actually opened).
  final String url;

  const _HelpLink({required this.label, required this.url});
}

/// **Help & support settings sub-page (`/settings/help`).**
///
/// A faithful Flutter port of the React `HelpSettingsPage.tsx`: a search
/// [Input] filtering [faqItems] by question substring, an expandable FAQ
/// accordion (first entry open by default, tap toggles), a "Contact support"
/// [Card] with mock Email/Chat [Button]s, and an external-links [Card]
/// (Documentation/API/Community).
///
/// All interactions are local-state mocks: search filters in memory, the
/// accordion tracks its own expanded set, and the contact/links rows are
/// no-ops (matches the app-wide mock convention for settings).
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/help', () => const HelpSettingsView());
/// ```
@immutable
class HelpSettingsView extends StatefulWidget {
  /// Creates the [HelpSettingsView].
  const HelpSettingsView({super.key});

  @override
  State<HelpSettingsView> createState() => _HelpSettingsViewState();
}

class _HelpSettingsViewState extends State<HelpSettingsView> {
  /// Current search query typed into the search [Input].
  String _query = '';

  /// Indices (into [faqItems]) currently expanded in the accordion.
  ///
  /// Seeded with `{0}` so the first FAQ entry starts open, mirroring the
  /// React source's `defaultValue={[FAQ[0].q]}`.
  final Set<int> _expanded = {0};

  /// Builds the external-links fixtures. Mirrors the `LINKS` fixture in the
  /// React `HelpSettingsPage`; resolved at build time (not app-wide mock
  /// data) since nothing else in the app reads it and [trans] requires the
  /// translator to already be loaded.
  List<_HelpLink> _buildLinks() => [
    _HelpLink(
      label: trans('uptizm.settings.help_link_docs_label'),
      url: trans('uptizm.settings.help_link_docs_url'),
    ),
    _HelpLink(
      label: trans('uptizm.settings.help_link_api_label'),
      url: trans('uptizm.settings.help_link_api_url'),
    ),
    _HelpLink(
      label: trans('uptizm.settings.help_link_community_label'),
      url: trans('uptizm.settings.help_link_community_url'),
    ),
  ];

  /// Filters [faqItems] by [_query] against the question text,
  /// case-insensitively. An empty query returns every item.
  List<FaqItem> get _filteredFaq {
    final String needle = _query.trim().toLowerCase();
    if (needle.isEmpty) {
      return faqItems;
    }

    return [
      for (final FaqItem item in faqItems)
        if (item.question.toLowerCase().contains(needle)) item,
    ];
  }

  /// Toggles the expanded state of the FAQ entry at [index].
  void _toggleExpanded(int index) {
    setState(() {
      if (_expanded.contains(index)) {
        _expanded.remove(index);
      } else {
        _expanded.add(index);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final List<FaqItem> filtered = _filteredFaq;

    return MSSettingsScaffold(
      title: trans('uptizm.settings.help_title'),
      subtitle: trans('uptizm.settings.help_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        // 1. Search input, filters the FAQ accordion below.
        MSInput(
          value: _query,
          onChanged: (String value) => setState(() => _query = value),
          placeholder: trans('uptizm.settings.help_search_placeholder'),
          prefix: const WIcon(Icons.search, className: 'text-fg-muted'),
        ),

        // 2. FAQ accordion.
        MSCard(
          title: trans('uptizm.settings.help_faq_heading'),
          child: WDiv(
            className: 'flex flex-col gap-1',
            children: [
              for (final FaqItem item in filtered)
                _buildFaqRow(item, faqItems.indexOf(item)),
            ],
          ),
        ),

        // 3. Contact support card.
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
                    onPressed: () {},
                    child: WText(
                      trans('uptizm.settings.help_contact_email_button'),
                    ),
                  ),
                  MSButton(
                    intent: ButtonIntent.secondary,
                    onPressed: () {},
                    child: WText(
                      trans('uptizm.settings.help_contact_chat_button'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),

        // 4. External-links card.
        MSSettingsSection(
          children: [
            for (final _HelpLink link in _buildLinks())
              MSSettingsRow(
                title: link.label,
                subtitle: link.url,
                icon: Icons.open_in_new,
                onTap: () {},
              ),
          ],
        ),
      ],
    );
  }

  /// Builds one expandable FAQ row: a tappable question header plus an
  /// optional answer body shown when [index] is in [_expanded].
  Widget _buildFaqRow(FaqItem item, int index) {
    final bool isOpen = _expanded.contains(index);

    return WAnchor(
      onTap: () => _toggleExpanded(index),
      child: WDiv(
        className: 'flex flex-col gap-2 py-3',
        children: [
          WDiv(
            className: 'flex flex-row items-center justify-between gap-3',
            children: [
              Expanded(
                child: WText(
                  item.question,
                  className: 'text-sm font-medium text-fg',
                ),
              ),
              WIcon(
                isOpen ? Icons.expand_less : Icons.expand_more,
                className: 'text-fg-muted',
              ),
            ],
          ),
          if (isOpen) WText(item.answer, className: 'text-sm text-fg-muted'),
        ],
      ),
    );
  }
}
