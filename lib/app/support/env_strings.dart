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

/// Strips one WRAPPING pair of quotes and surrounding whitespace from a raw
/// `.env` value.
///
/// A value written `"Uptizm"` and one written `Uptizm` have to mean the same
/// thing, because the parser hands back whichever the file contained.
///
/// Only the boundary pair goes, never every quote in the string: an app name may
/// legitimately carry an apostrophe (`APP_NAME="Anıl's Monitor"`), and stripping
/// all quotes would silently rewrite it to `Anıls Monitor`. An unbalanced quote
/// is left alone, so a malformed line stays visibly malformed instead of being
/// half-repaired.
String envClean(String? value) {
  if (value == null) return '';

  final String trimmed = value.trim();
  if (trimmed.length < 2) return trimmed;

  final String first = trimmed[0];
  final bool wrapped =
      (first == '"' || first == "'") && trimmed.endsWith(first);

  return wrapped ? trimmed.substring(1, trimmed.length - 1).trim() : trimmed;
}
