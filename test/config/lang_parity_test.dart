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
}
