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
}
