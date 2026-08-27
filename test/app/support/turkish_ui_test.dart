import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/support/status_page_types.dart';
import 'package:uptizm/resources/views/status/status_form_support.dart';

import '../../support/bundled_lang.dart';

/// Three surfaces that spoke English on a Turkish UI, and one Turkish sentence
/// that could not hold the value interpolated into it.
///
/// Every one of them passed the whole suite, because the suite runs in English:
/// a hardcoded English literal is indistinguishable from a correct translation
/// when the expected value is the English string. So each case here loads the
/// SHIPPED Turkish catalogue and asserts against it, which is the only way an
/// assertion can move when the copy moves rather than agreeing with whoever
/// wrote the test.
void main() {
  Future<void> useLocale(String locale) async {
    Translator.instance.setLoader(_BundledLoader(locale));
    await Translator.instance.setLocale(Locale(locale));
  }

  group('the subscriber roster', () {
    testWidgets('dates a subscriber in the operator\'s language', (_) async {
      await useLocale('tr');
      final Map<String, dynamic> lang = readBundledLang('tr');

      final Subscriber subscriber = Subscriber.fromMap(<String, dynamic>{
        'id': 's1',
        'email': 'ops@example.com',
        'subscribed_at': DateTime.now()
            .subtract(const Duration(days: 3))
            .toIso8601String(),
      });

      // It used to return the literal '3 days ago', which the subscribers
      // screen then interpolated into a translated sentence.
      expect(
        subscriber.subscribedAt,
        lang['uptizm.common.time_days_ago'].toString().replaceAll(
          ':count',
          '3',
        ),
      );
    });

    testWidgets('carries a relative phrase without claiming it is a date',
        (_) async {
      await useLocale('tr');
      final Map<String, dynamic> lang = readBundledLang('tr');

      // Turkish puts the verb at the end, so a sentence built around
      // "tarihinde" (meaning "on the date of") cannot take a relative phrase:
      // ":date tarihinde abone oldu" rendered as "3 gün önce tarihinde abone
      // oldu". The value is relative now, so the sentence has to be too.
      expect(
        lang['uptizm.status.subscribers_subscribed_at'],
        isNot(contains('tarihinde')),
      );
    });
  });

  group('the AI status-page draft', () {
    testWidgets('seeds a Turkish page for a Turkish operator', (_) async {
      await useLocale('tr');
      final Map<String, dynamic> lang = readBundledLang('tr');

      final draft = aiDraftFor(const <String>['api']);

      // This lands on a page whose whole purpose is to be PUBLISHED, so the
      // English seed was copy a Turkish operator's own customers would read.
      expect(draft.name, lang['uptizm.status.status_draft_name']);
      expect(
        draft.description,
        lang['uptizm.status.status_draft_description'],
      );
    });

    testWidgets('keeps the slug ASCII and free of a company that does not exist',
        (_) async {
      await useLocale('tr');

      final draft = aiDraftFor(const <String>['api']);

      // The slug is a URL component, so unlike the name it stays untranslated.
      // It was 'acme', which named a fictional brand on a real customer's
      // public page.
      expect(draft.slug, matches(RegExp(r'^[a-z0-9-]+$')));
      expect(draft.slug, isNot('acme'));
    });
  });
}

/// Feeds [trans] the app's shipped catalogue for one locale.
class _BundledLoader implements TranslationLoader {
  final String locale;

  const _BundledLoader(this.locale);

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}
