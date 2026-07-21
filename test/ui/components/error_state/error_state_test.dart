import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/error_state/error_state.dart';
import 'package:uptizm/ui/components/error_state/error_state.recipe.dart';

/// Feeds the default error-state copy so [trans] returns real English prose
/// instead of the raw key tokens.
class _ErrorLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.common.error_default_title': "Couldn't load this",
    'uptizm.common.error_default_description':
        'Something went wrong on our end. Check your connection and try again.',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_ErrorLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe assertions
  // ---------------------------------------------------------------------------

  group('errorStateRecipe', () {
    test('root is a centered flex column', () {
      final cls = errorStateRootClassName();
      expect(cls, contains('flex flex-col'));
      expect(cls, contains('items-center'));
      expect(cls, contains('justify-center'));
      expect(cls, contains('text-center'));
    });

    test('icon wrap is bare and down-toned (no circular background)', () {
      final cls = errorStateIconWrapClassName();
      expect(cls, contains('text-down'));
      // The design renders the glyph bare; no filled circle.
      expect(cls, isNot(contains('rounded-full')));
      expect(cls, isNot(contains('bg-')));
    });

    test('title is neutral foreground, not red', () {
      final cls = errorStateTitleClassName();
      expect(cls, contains('text-sm'));
      expect(cls, contains('font-medium'));
      expect(cls, contains('text-fg'));
      expect(cls, isNot(contains('text-down')));
    });

    test('description is muted and capped at max-w-sm', () {
      final cls = errorStateDescriptionClassName();
      expect(cls, contains('text-fg-muted'));
      expect(cls, contains('max-w-sm'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('ErrorState renders the given title', (tester) async {
    await tester.pumpWidget(
      wrap(const ErrorState(title: "Couldn't load monitors")),
    );
    expect(find.text("Couldn't load monitors"), findsOneWidget);
  });

  testWidgets('ErrorState falls back to a default title and description', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const ErrorState()));
    expect(find.text("Couldn't load this"), findsOneWidget);
    expect(find.byType(WIcon), findsOneWidget);
  });

  testWidgets('ErrorState renders the given description', (tester) async {
    await tester.pumpWidget(
      wrap(const ErrorState(description: 'Check your connection.')),
    );
    expect(find.text('Check your connection.'), findsOneWidget);
  });

  testWidgets('ErrorState renders the retry action when provided', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const ErrorState(action: WText('Try again'))));
    expect(find.text('Try again'), findsOneWidget);
  });
}
