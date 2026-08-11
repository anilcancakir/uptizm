/// Reads the app's SHIPPED language catalogue the way the runtime loader does,
/// for tests whose subject is the copy an operator reads.
///
/// It exists because three test files had grown their own byte-identical copy of
/// the flattening rule, and that rule has to stay in lockstep with `magic`'s
/// `JsonAssetLoader`: flattening is the loader's job, and the translator stores
/// whatever the loader returns verbatim. One copy means a change in `magic` is
/// one edit here rather than a silent divergence in whichever harness nobody
/// looked at, and those harnesses are the only defence against another
/// ungrammatical string shipping.
///
/// Reading the asset instead of an inline map is the point: a fixture would have
/// the assertion agree with the test author rather than with the product, which
/// is precisely how an ungrammatical Turkish postmortem passed a green suite.
/// The suite runs with the repo root as its cwd, so the path resolves.
library;

import 'dart:convert';
import 'dart:io';

/// Flattens a nested catalogue into the dotted keys `Translator` caches.
Map<String, dynamic> flattenLang(
  Map<String, dynamic> source, [
  String prefix = '',
]) {
  final Map<String, dynamic> flat = {};
  source.forEach((String key, Object? value) {
    final String path = prefix.isEmpty ? key : '$prefix.$key';
    if (value is Map<String, dynamic>) {
      flat.addAll(flattenLang(value, path));
    } else {
      flat[path] = value;
    }
  });

  return flat;
}

/// Reads `assets/lang/<locale>.json` and returns it flattened.
Map<String, dynamic> readBundledLang(String locale) {
  return flattenLang(
    json.decode(File('assets/lang/$locale.json').readAsStringSync())
        as Map<String, dynamic>,
  );
}
