import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/config/uptizm_theme.dart';

/// Guards the two halves of DESIGN.md that reach the runtime by hand.
///
/// `design:sync` emits colours only, so the radius scale, the font families and
/// every translucent surface are hand-authored. All three shipped broken at
/// once and no test could go red for any of them: the radii were silently half
/// their specified size, `font-mono` resolved to a CSS stack Flutter cannot
/// match so the bundled Geist fonts were never used, and an opacity modifier on
/// an alias token painted nothing at all.
///
/// The scale assertions read DESIGN.md itself rather than repeating its
/// numbers, so this file cannot certify a value that drifted away from the
/// source of truth.
void main() {
  final WindThemeData theme = buildUptizmWindTheme();

  /// Parses one `key: value` block out of DESIGN.md's YAML frontmatter.
  ///
  /// Returns the block's entries with `px` suffixes stripped. Deliberately
  /// hand-rolled: pulling in a YAML parser for six lines would trade a
  /// dependency for no extra confidence.
  Map<String, double> designBlock(String name) {
    final List<String> lines = File('DESIGN.md').readAsLinesSync();
    final int start = lines.indexWhere((l) => l.trimRight() == '$name:');
    expect(start, isNot(-1), reason: 'DESIGN.md has no `$name:` block');

    final Map<String, double> out = <String, double>{};
    for (final String line in lines.skip(start + 1)) {
      // The block ends at the next line that is not indented.
      if (!line.startsWith('  ') || line.trim().isEmpty) break;
      final RegExpMatch? m = RegExp(
        r'^\s+([A-Za-z0-9]+):\s*"?([0-9.]+)px"?\s*$',
      ).firstMatch(line);
      if (m != null) out[m.group(1)!] = double.parse(m.group(2)!);
    }
    expect(out, isNotEmpty, reason: 'parsed nothing out of `$name:`');
    return out;
  }

  group('radius scale', () {
    test('every radius DESIGN.md declares reaches the theme', () {
      final Map<String, double> declared = designBlock('rounded');

      for (final MapEntry<String, double> entry in declared.entries) {
        expect(
          theme.borderRadius[entry.key],
          entry.value,
          reason:
              'DESIGN.md declares `rounded-${entry.key}` as ${entry.value}px '
              'but the theme resolves it to ${theme.borderRadius[entry.key]}. '
              "Wind's own default scale is half of DESIGN.md's, so a dropped "
              'uptizmBorderRadius reads as this failure.',
        );
      }
    });

    test('the scale never decreases as the token name grows', () {
      // `rounded-2xl` and `rounded-3xl` are both used in lib/ but were absent
      // from DESIGN.md, so they kept Wind's defaults (16 and 24) while the rest
      // of the scale moved up. That left `2xl` (16) SMALLER than `xl` (20).
      const List<String> ladder = [
        'none',
        'sm',
        'DEFAULT',
        'md',
        'lg',
        'xl',
        '2xl',
        '3xl',
      ];
      for (int i = 1; i < ladder.length; i++) {
        expect(
          theme.borderRadius[ladder[i]]!,
          greaterThan(theme.borderRadius[ladder[i - 1]]!),
          reason: '`${ladder[i]}` must be larger than `${ladder[i - 1]}`',
        );
      }
    });
  });

  group('font families', () {
    test('resolve to the families pubspec.yaml actually registers', () {
      final String pubspec = File('pubspec.yaml').readAsStringSync();

      for (final String role in <String>['sans', 'mono']) {
        final String family = theme.fontFamilies[role]!;
        expect(
          family,
          isNot(contains(',')),
          reason:
              'fontFamilies[$role] is "$family", a CSS font stack. Flutter '
              'matches ONE registered family and does not fall through a '
              'comma-separated list, so a stack resolves to nothing and the '
              'bundled font is never used.',
        );
        expect(
          pubspec,
          contains('family: $family'),
          reason:
              'fontFamilies[$role] names "$family", which pubspec.yaml does '
              'not register as an asset font.',
        );
      }
    });
  });

  group('translucent surfaces', () {
    /// Renders [token] and returns every background colour it paints.
    Future<List<Color>> painted(WidgetTester tester, String token) async {
      await tester.pumpWidget(
        WindTheme(
          data: theme,
          child: Directionality(
            textDirection: ui.TextDirection.ltr,
            child: WDiv(
              className: token,
              children: const [SizedBox(width: 40, height: 40)],
            ),
          ),
        ),
      );

      return <Color>[
        for (final Element e in find.byType(DecoratedBox).evaluate())
          if ((e.widget as DecoratedBox).decoration case BoxDecoration(
            :final Color color,
          ))
            color,
      ];
    }

    testWidgets('every glass and scrim token paints a translucent fill', (
      tester,
    ) async {
      // The expected alpha per token, as baked into its hex in
      // uptizm_status_tokens.dart. A token that resolves to nothing paints an
      // empty list, which is exactly how the shipped `bg-surface/90` behaved.
      const Map<String, int> expected = <String, int>{
        'bg-surface-glass-95': 0xF2,
        'bg-surface-glass-90': 0xE6,
        'bg-surface-glass-80': 0xCC,
        'bg-surface-scrim': 0x4D,
      };

      for (final MapEntry<String, int> entry in expected.entries) {
        final List<Color> colors = await painted(tester, entry.key);

        expect(
          colors,
          isNotEmpty,
          reason:
              '`${entry.key}` painted no background at all. That is what an '
              'opacity modifier on an alias token does, so this is the '
              'regression the token was introduced to remove.',
        );
        expect(
          (colors.first.a * 255).round(),
          entry.value,
          reason: '`${entry.key}` should paint at alpha ${entry.value}',
        );
      }
    });

    testWidgets('an opaque alias still paints fully opaque', (tester) async {
      // The control: if this ever goes translucent, the probe above is
      // measuring something other than the alpha it claims to measure.
      final List<Color> colors = await painted(tester, 'bg-surface');
      expect(colors, isNotEmpty);
      expect((colors.first.a * 255).round(), 0xFF);
    });
  });

  group('no source applies an opacity modifier to an alias', () {
    test('because it silently resolves to no fill', () {
      // `bg-primary/50` is fine: `primary` is a registered MaterialColor
      // family, so the background parser can resolve it and apply the alpha.
      // `bg-surface/80` is not: `surface` exists only as an alias key, the
      // parser asks isValidColor('surface'), gets false, and paints nothing.
      // The difference is invisible at the call site, which is why this is a
      // source guard rather than a code review note.
      final Set<String> aliasRoles = <String>{
        for (final String key in theme.aliases.keys)
          if (key.split('-') case [_, ...final rest] when rest.isNotEmpty)
            rest.join('-'),
      }..removeWhere(theme.colors.containsKey);

      final List<String> offenders = <String>[];
      for (final FileSystemEntity entity
          in Directory('lib').listSync(recursive: true)) {
        if (entity is! File || !entity.path.endsWith('.dart')) continue;
        final List<String> lines = entity.readAsLinesSync();

        for (int i = 0; i < lines.length; i++) {
          final String line = lines[i];
          if (line.trimLeft().startsWith('//')) continue;

          for (final RegExpMatch m in RegExp(
            r'\b(?:bg|text|border)-([a-z0-9-]+)/\d+',
          ).allMatches(line)) {
            if (aliasRoles.contains(m.group(1))) {
              offenders.add('${entity.path}:${i + 1}: ${m.group(0)}');
            }
          }
        }
      }

      expect(
        offenders,
        isEmpty,
        reason:
            'These tokens paint nothing. Add a pre-composited alias carrying '
            'the alpha in its hex (see `bg-surface-glass-90`) and use that '
            'instead:\n${offenders.join('\n')}',
      );
    });
  });
}
