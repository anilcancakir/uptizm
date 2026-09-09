// The catch-all view's one piece of logic, and the one a live walk caught.
//
// `/:path(.*)` does not promise whether the captured value carries a leading
// slash, and this view first shipped prepending one unconditionally. Driven
// against the real router at `/definitely-not-a-route` it rendered
// `//definitely-not-a-route` at the user. Both shapes are pinned here so the
// assumption cannot come back.
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/config/uptizm_status_tokens.dart'
    show uptizmStatusAliases;
import 'package:uptizm/resources/views/errors/not_found_view.dart';

import '../../../support/bundled_lang.dart';

/// Serves uptizm's own shipped English catalogue, whatever locale is asked for.
class _BundledLangLoader implements TranslationLoader {
  const _BundledLangLoader();

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang('en');
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();

    Translator.instance.setLoader(const _BundledLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  Future<void> pump(WidgetTester tester, String path) async {
    await tester.pumpWidget(
      WindTheme(
        data: WindThemeData(aliases: uptizmStatusAliases),
        child: WidgetsApp(
          color: const Color(0xFF000000),
          builder: (BuildContext context, Widget? child) =>
              NotFoundView(path: path),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  group('NotFoundView shows the address the visitor asked for', () {
    testWidgets('a captured path with no leading slash gains exactly one',
        (WidgetTester tester) async {
      await pump(tester, 'main.dart.js');

      expect(find.textContaining('/main.dart.js'), findsOneWidget);
      expect(find.textContaining('//main.dart.js'), findsNothing);
    });

    testWidgets('a captured path that already has one does not gain a second',
        (WidgetTester tester) async {
      // The shape the real router actually hands over, measured live.
      await pump(tester, '/definitely-not-a-route');

      expect(find.textContaining('/definitely-not-a-route'), findsOneWidget);
      expect(find.textContaining('//definitely-not-a-route'), findsNothing);
    });
  });
}
