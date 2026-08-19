import 'dart:ui' show Locale;

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/channel_type.dart' show ChannelType;

import '../../support/bundled_lang.dart';

/// Locks the settings-hub subtitle to the channels the product actually has.
///
/// It read "Email, Slack, SMS, webhook" while `/teams/notifications` offers
/// Slack, Webhook, PagerDuty and Microsoft Teams. Two errors at once: it promised
/// email and SMS on a page that has neither (both are PER-MEMBER preferences, not
/// team integrations), and it hid PagerDuty and Teams, which the page does offer.
/// A row subtitle is a claim about the page it navigates to.
///
/// Derived from [ChannelType] rather than pinned to a literal, so adding or
/// removing a channel forces the copy to follow instead of drifting quietly. The
/// same idea the landing page already applies to its region and channel claims.
void main() {
  for (final String locale in <String>['en', 'tr']) {
    group('in $locale', () {
      setUp(() async {
        Translator.instance.setLoader(_BundledLoader(locale));
        await Translator.instance.setLocale(Locale(locale));
      });

      test('the team-channels row names every channel and nothing else', () {
        final String subtitle = trans(
          'uptizm.settings.hub_team_channels_subtitle',
        );

        for (final ChannelType channel in ChannelType.values) {
          expect(
            subtitle.toLowerCase(),
            contains(channel.label.toLowerCase()),
            reason: '$locale: ${channel.name} is offered on that page but the '
                'row does not name it',
          );
        }

        // The two that are NOT team channels. Naming either here sends the
        // operator to a page where they will not find it.
        for (final String absent in <String>['sms', 'mail', 'posta', 'push']) {
          expect(
            subtitle.toLowerCase(),
            isNot(contains(absent)),
            reason: '$locale: "$absent" is a per-member preference, not a team '
                'channel on that page',
          );
        }
      });
    });
  }
}

/// Serves the shipped catalogue for one locale, so the assertions read the copy
/// an operator reads rather than a fixture.
class _BundledLoader implements TranslationLoader {
  _BundledLoader(this.locale);

  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}
