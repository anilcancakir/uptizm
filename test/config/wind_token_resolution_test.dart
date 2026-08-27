import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/config/uptizm_theme.dart';

/// Every colour token written in `lib/` has to resolve to something.
///
/// Wind drops an unrecognised token silently: no warning, no throw, nothing at
/// the call site. So a token that never existed and a token that was renamed
/// look exactly like one that works, and the only symptom is a colour that does
/// not appear. Four had accumulated by the time this test was written, and two
/// of them were invisible to a full-tree review because the class strings read
/// perfectly plausibly:
///
/// - `bg-fg-disabled`: `fg` is not a colour family and there is no such alias,
///   so the status-page preview's three browser-chrome dots painted nothing.
/// - `border-ai`: the map defines `border-ai-soft`, not this, so the AI card and
///   the assistant panel fell back to the default border.
/// - `error:border-bg-destructive`: the border parser cannot read a `bg-` body,
///   so a field's error state painted no border.
///
/// Two more that this check cannot see, fixed alongside them because they are
/// the same failure in families it does not cover: `divide-y` names a family
/// wind does not implement at all, and a `grid` with no base `grid-cols-*` gets
/// wind's default of TWO rather than one, so the `sm:` prefix beside it changed
/// nothing.
///
/// The second half of the check is the Token-Only rule: a raw palette token like
/// `text-white` DOES resolve, so the first half cannot see it, and it is a
/// blocker all the same because it hardcodes a colour that cannot answer to
/// dark mode or to a theme change.
void main() {
  final WindThemeData theme = buildUptizmWindTheme();

  /// Colour-family names Wind ships built in, which resolve without an alias.
  ///
  /// The Token-Only rule bans reaching for them by name in app code, so they are
  /// listed here to be REJECTED rather than allowed: a token naming one of these
  /// is a raw palette token, not a semantic one.
  const Set<String> rawPalette = <String>{
    'white', 'black',
    'slate', 'gray', 'grey', 'zinc', 'neutral', 'stone',
    'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
    'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink',
    'rose',
  };

  /// Tokens whose `bg-`/`text-`/`border-` prefix is not a colour at all: sizes,
  /// weights, alignment, border sides and widths, background sizing.
  /// Values that name the ABSENCE of a colour rather than a choice of one.
  ///
  /// `border-transparent` holds a border's width without painting it, which is
  /// how a swatch picker keeps its layout stable between selected and
  /// unselected. Banning it would push the call site to a hardcoded hex or to a
  /// layout hack, both worse.
  const Set<String> colourless = <String>{'transparent', 'current', 'inherit'};

  final RegExp nonColour = RegExp(
    r'^(?:'
    r'text-(?:xs|sm|base|lg|xl|\dxl|left|right|center|justify|wrap|nowrap|ellipsis|clip|balance)'
    r'|border-(?:t|b|l|r|x|y|s|e|\d+|none|solid|dashed|dotted|double|hidden)'
    r'|bg-(?:cover|contain|auto|fixed|local|scroll|center|top|bottom|left|right|repeat|no-repeat|clip-\w+|origin-\w+|gradient-[\w-]+|linear-[\w-]+)'
    r')$',
  );

  /// Every colour-ish token written in `lib/`, with its file and line.
  ///
  /// Comment lines are skipped: the codebase names dead tokens in comments on
  /// purpose (explaining why one was replaced), and flagging those would make
  /// the guard argue with its own documentation.
  Map<String, List<String>> tokensInLib() {
    final Map<String, List<String>> found = <String, List<String>>{};
    final RegExp token = RegExp(r'\b(?:bg|text|border)-[a-z][a-z0-9-]*');

    for (final FileSystemEntity entity in Directory('lib').listSync(recursive: true)) {
      if (entity is! File || !entity.path.endsWith('.dart')) continue;
      // Generated files are regenerated, never hand-edited, and the theme file
      // legitimately contains the raw hex the aliases expand to.
      if (entity.path.endsWith('.g.dart')) continue;
      // `lib/preview/` is the token CATALOGUE: its job is to display token
      // names, so it carries them as data (a row label, a `startsWith` test)
      // rather than as classNames. Scanning it makes the guard argue with the
      // one directory whose purpose is to name what the guard checks.
      if (entity.path.startsWith('lib/preview/')) continue;

      final List<String> lines = entity.readAsLinesSync();

      for (int i = 0; i < lines.length; i++) {
        final String line = lines[i];
        final String trimmed = line.trimLeft();
        if (trimmed.startsWith('//') || trimmed.startsWith('///')) continue;

        for (final RegExpMatch match in token.allMatches(line)) {
          found
              .putIfAbsent(match.group(0)!, () => <String>[])
              .add('${entity.path}:${i + 1}');
        }
      }
    }

    return found;
  }

  test('no token in lib/ resolves to nothing', () {
    final List<String> dead = <String>[];

    tokensInLib().forEach((String token, List<String> sites) {
      if (nonColour.hasMatch(token)) return;
      if (theme.aliases.containsKey(token)) return;

      // A token naming a real colour family resolves, whatever the rule says
      // about reaching for one by name. That is the next test's business.
      final String body = token.substring(token.indexOf('-') + 1);
      final String family = body.split('-').first;
      if (colourless.contains(family)) return;
      if (theme.colors.containsKey(family) || rawPalette.contains(family)) return;

      dead.add('$token  ->  ${sites.first}${sites.length > 1 ? ' (+${sites.length - 1} more)' : ''}');
    });

    expect(
      dead,
      isEmpty,
      reason:
          'Wind drops an unrecognised token silently, so these paint nothing '
          'and nothing at the call site says so:\n${dead.join('\n')}',
    );
  });

  test('no token in lib/ hardcodes a raw palette colour', () {
    final List<String> raw = <String>[];

    tokensInLib().forEach((String token, List<String> sites) {
      if (nonColour.hasMatch(token)) return;
      if (theme.aliases.containsKey(token)) return;

      final String body = token.substring(token.indexOf('-') + 1);
      if (colourless.contains(body.split('-').first)) return;
      if (rawPalette.contains(body.split('-').first)) {
        raw.add('$token  ->  ${sites.first}${sites.length > 1 ? ' (+${sites.length - 1} more)' : ''}');
      }
    });

    expect(
      raw,
      isEmpty,
      reason:
          'These resolve, so the dead-token check cannot see them, and they are '
          'blockers all the same: a hardcoded colour cannot answer to dark mode '
          'or to a theme change. Use a semantic alias from DESIGN.md, or derive '
          'the colour when it sits on a value the customer chose:\n${raw.join('\n')}',
    );
  });
}
