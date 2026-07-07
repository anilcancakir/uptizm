import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/auth/welcome_view.dart';

/// In-memory loader feeding the welcome onboarding prose so [trans] returns real
/// strings instead of raw i18n keys, letting the tests assert on button and
/// slide copy.
class _WelcomeLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.welcome.skip': 'Skip',
      'uptizm.welcome.slide_monitoring_title': 'Monitoring from everywhere',
      'uptizm.welcome.slide_monitoring_body': 'Checks from many regions.',
      'uptizm.welcome.slide_ai_title': 'AI that reasons',
      'uptizm.welcome.slide_ai_body': 'Evidence and confidence.',
      'uptizm.welcome.slide_status_title': 'Status pages users trust',
      'uptizm.welcome.slide_status_body': 'Branded public status pages.',
      'uptizm.welcome.continue_label': 'Continue',
      'uptizm.welcome.get_started': 'Get started',
      'uptizm.welcome.have_account': 'Already have an account?',
      'uptizm.welcome.sign_in': 'Sign in',
    };
  }
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_WelcomeLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    Magic.flush();
  });

  Widget wrap(Widget widget, {Size size = const Size(375, 812)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: widget,
        ),
      ),
    );
  }

  testWidgets('WelcomeView renders the wordmark and the skip affordance', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const WelcomeView()));
    await tester.pump();

    expect(find.text('Uptizm'), findsOneWidget);
    expect(find.text('Skip'), findsOneWidget);
  });

  testWidgets('WelcomeView starts on the first slide with a Continue CTA', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const WelcomeView()));
    await tester.pump();

    expect(find.byType(PageView), findsOneWidget);
    expect(find.text('Monitoring from everywhere'), findsOneWidget);
    // First slide: primary CTA advances, so it reads "Continue", not the final
    // "Get started".
    expect(find.text('Continue'), findsOneWidget);
    expect(find.text('Get started'), findsNothing);
    // The sign-in switch is always present.
    expect(find.text('Sign in'), findsOneWidget);
  });

  testWidgets('WelcomeView swaps the CTA to Get started on the last slide', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const WelcomeView()));
    await tester.pump();

    // Jump to the last slide by tapping its progress dot, then let the page
    // animation settle so onPageChanged flips the index.
    final Finder skip = find.text('Skip');
    expect(skip, findsOneWidget);

    // Drive the carousel to the last slide via a leftward fling on the track.
    await tester.fling(find.byType(PageView), const Offset(-400, 0), 1000);
    await tester.pumpAndSettle();
    await tester.fling(find.byType(PageView), const Offset(-400, 0), 1000);
    await tester.pumpAndSettle();

    expect(find.text('Status pages users trust'), findsOneWidget);
    expect(find.text('Get started'), findsOneWidget);
    expect(find.text('Continue'), findsNothing);
  });

  testWidgets('WelcomeView renders without error at a mobile width', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const WelcomeView()));
    await tester.pump();

    expect(tester.takeException(), isNull);
  });
}
