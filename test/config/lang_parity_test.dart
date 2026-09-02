import 'package:flutter_test/flutter_test.dart';

import '../support/bundled_lang.dart';

/// Validates that [en.json] and [tr.json] carry identical key sets.
///
/// This test catches one-sided key additions at test-write time rather than
/// at runtime. Missing keys render as raw dotted paths, which ship raw
/// copy (e.g., `editor.preview.draft_heading`) where a label belongs.
void main() {
  test('en.json and tr.json carry identical key sets', () {
    final Map<String, dynamic> enKeys = readBundledLang('en');
    final Map<String, dynamic> trKeys = readBundledLang('tr');

    final Set<String> enSet = enKeys.keys.toSet();
    final Set<String> trSet = trKeys.keys.toSet();

    final Set<String> onlyInEn = enSet.difference(trSet);
    final Set<String> onlyInTr = trSet.difference(enSet);

    expect(
      onlyInEn.isEmpty && onlyInTr.isEmpty,
      isTrue,
      reason: onlyInEn.isNotEmpty || onlyInTr.isNotEmpty
          ? 'Key mismatch: only in en.json: ${onlyInEn.toList()}, only in tr.json: ${onlyInTr.toList()}'
          : '',
    );
  });

  test('magic_starter.billing values differ between en.json and tr.json', () {
    final Map<String, dynamic> enKeys = readBundledLang('en');
    final Map<String, dynamic> trKeys = readBundledLang('tr');

    // Keys whose value is legitimately identical across locales, each with
    // the reason it was not translated. A key-presence check passes on a
    // catalogue where someone pasted the English sentence, which is exactly
    // what this guard exists to catch; keep this list explicit and empty
    // unless a key is provably untranslatable (e.g. a store brand name with
    // no local equivalent).
    final Map<String, String> allowedIdentical = <String, String>{};

    final Iterable<String> billingKeys = enKeys.keys.where(
      (String key) => key.startsWith('magic_starter.billing.'),
    );

    for (final String key in billingKeys) {
      if (allowedIdentical.containsKey(key)) {
        continue;
      }

      final Object? enValue = enKeys[key];
      final Object? trValue = trKeys[key];

      expect(
        trValue,
        isNot(equals(enValue)),
        reason: 'tr.json "$key" matches en.json verbatim ("$enValue"). '
            'Translate it, or add it to allowedIdentical with a reason.',
      );
    }
  });

  /// Two relative-time vocabularies reach one screen, and they have to agree.
  ///
  /// `uptizm.common.time_*_ago` is what this app's own widgets render (the
  /// dashboard incident cards, monitor rows). `time.*_ago` is what
  /// `magic_notifications` renders inside the bell dropdown and the
  /// notifications list, and those keys are supplied by THIS catalogue because
  /// the package ships none. The bell sits beside an incident card, so a
  /// mismatch is visible in one glance: the two families once read "19 h ago"
  /// and "19h ago" in English, and "19 saat önce" against "19 sa önce" in
  /// Turkish.
  ///
  /// The placeholders differ by design (`:hours` against `:count`), so the
  /// comparison is on what follows the placeholder, which is the part a reader
  /// actually sees.
  test('both relative-time vocabularies render the same unit suffix', () {
    // Each entry pairs the package's key and placeholder with this app's own.
    // The placeholder is named explicitly rather than matched by a pattern:
    // `:daysd ago` has the unit letter flush against the placeholder, so a
    // greedy `:[A-Za-z]+` swallows the `d` and the comparison silently becomes
    // `' ago'` against `' ago'` for every unit, which passes on a real
    // mismatch.
    const List<List<String>> pairs = <List<String>>[
      <String>['time.minutes_ago', ':minutes', 'uptizm.common.time_minutes_ago', ':count'],
      <String>['time.hours_ago', ':hours', 'uptizm.common.time_hours_ago', ':count'],
      <String>['time.days_ago', ':days', 'uptizm.common.time_days_ago', ':count'],
    ];

    for (final String locale in <String>['en', 'tr']) {
      final Map<String, dynamic> keys = readBundledLang(locale);

      for (final List<String> pair in pairs) {
        final String packageValue = keys[pair[0]]! as String;
        final String appValue = keys[pair[2]]! as String;

        expect(
          _stripPlaceholder(packageValue, pair[1]),
          _stripPlaceholder(appValue, pair[3]),
          reason:
              '$locale.json "${pair[0]}" ("$packageValue") and "${pair[2]}" '
              '("$appValue") render different unit suffixes. Both appear on '
              'the dashboard at once, so they have to read the same.',
        );
      }
    }
  });
}

/// Everything after [placeholder] in a relative-time string.
///
/// `_stripPlaceholder(':hoursh ago', ':hours')` yields `'h ago'`;
/// `_stripPlaceholder(':count sa önce', ':count')` yields `' sa önce'`. The
/// count's own name is dropped because the two families spell it differently
/// and only the human-visible remainder is comparable.
String _stripPlaceholder(String value, String placeholder) {
  if (!value.startsWith(placeholder)) {
    throw ArgumentError('"$value" does not start with "$placeholder"');
  }

  return value.substring(placeholder.length);
}
