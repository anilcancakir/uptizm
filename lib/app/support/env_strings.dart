import 'package:magic/magic.dart';

/// Reads a STRING setting from the bundled `.env`, treating a blank or
/// quote-only value as absent.
///
/// ## Why this exists
///
/// `env('KEY', fallback)` only returns the fallback when the key is MISSING. A
/// key that is present and empty resolves to `''`, and a quoted value arrives
/// with its quotes attached, because `flutter_dotenv` hands back the raw text
/// after the `=`.
///
/// That has bitten twice. `APP_NAME=""` in a deployed `.env` put `Monitor | ""`
/// in the browser tab, and `WEB_URL` blank pointed the Terms and Privacy links
/// at a path with no origin. Both were fixed where they were found, each with
/// its own private cleaner, which left the same trap open on every other string
/// key: `API_URL` blank sends every request to a bare path, and a blank
/// `REVERB_HOST` or `REVERB_SCHEME` builds a malformed socket URL, which the
/// boot-time Echo connect turns into an uncaught exception and a blank app.
///
/// Third caller, so it is one function now rather than a third private copy.
String envString(String key, String fallback) {
  final String value = envClean(env<String?>(key));

  return value.isEmpty ? envClean(fallback) : value;
}

/// Strips surrounding quotes and whitespace from a raw `.env` value.
///
/// Quotes are removed rather than trimmed from the ends only: a value written
/// `"Uptizm"` and one written `Uptizm` have to mean the same thing, and no
/// setting this app reads (a name, a host, a scheme, a URL) legitimately
/// contains a quote character.
String envClean(String? value) {
  if (value == null) return '';

  return value.replaceAll('"', '').replaceAll("'", '').trim();
}
