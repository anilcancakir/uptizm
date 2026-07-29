import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Validates that [en.json] and [tr.json] carry identical key sets.
///
/// This test catches one-sided key additions at test-write time rather than
/// at runtime. Missing keys render as raw dotted paths, which ship raw
/// copy (e.g., `editor.preview.draft_heading`) where a label belongs.
void main() {
  /// Flattens a nested lang map into the dotted keys [Translator] caches.
  ///
  /// Mirrors `JsonAssetLoader`: flattening is the loader's job, the
  /// translator stores whatever the loader returns verbatim.
  Map<String, dynamic> flatten(
    Map<String, dynamic> source, [
    String prefix = '',
  ]) {
    final Map<String, dynamic> flat = {};
    source.forEach((String key, Object? value) {
      final String path = prefix.isEmpty ? key : '$prefix.$key';
      if (value is Map<String, dynamic>) {
        flat.addAll(flatten(value, path));
      } else {
        flat[path] = value;
      }
    });

    return flat;
  }

  /// Reads the app's real bundled lang file for [locale], pre-flattened.
  Map<String, dynamic> readBundledLang(String locale) {
    final File file = File('assets/lang/$locale.json');

    return flatten(json.decode(file.readAsStringSync()) as Map<String, dynamic>);
  }

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
