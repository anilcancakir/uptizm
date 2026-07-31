import 'package:magic/magic.dart';

/// The website origin used when `WEB_URL` resolves to nothing.
///
/// Matches the local backend (`cd backend && composer dev` serves the site on
/// port 8000), so a checkout whose `.env` is missing the key still opens a
/// working page instead of a malformed URL. Both the `app.web_url` config slot
/// (`lib/config/app.dart`) and [WebLinks] fall back to this one constant, so
/// the default lives in a single place.
const String kDefaultWebUrl = 'http://localhost:8000';

/// Locale-aware links to the documents the WEBSITE owns.
///
/// The client carries no legal text of its own. Terms, Privacy and Contact are
/// served by the marketing site, which keeps exactly one text per document:
/// the version a user reads is the version that governs, and the sign-up
/// screen can put both in front of them before the account exists.
///
/// ### The path shape mirrors the website's own rule
///
/// `backend/routes/marketing.php` registers the DEFAULT language on the bare
/// path and every other supported language behind its own prefix (`/terms` vs
/// `/tr/terms`); an unlisted language is a deliberate 404 there. This resolver
/// reproduces that rule from the client's own configuration: [Lang] supplies
/// the active language, `localization.locale` the default one and
/// `localization.supported_locales` the list. An unknown language therefore
/// degrades to the bare path rather than composing an address the site
/// answers with a 404.
///
/// ### Example usage
/// ```dart
/// // Turkish app: http://localhost:8000/tr/terms
/// // English app: http://localhost:8000/terms
/// await Launch.url(WebLinks.terms);
/// ```
class WebLinks {
  // Prevent instantiation: every member is static.
  WebLinks._();

  /// Absolute URL of the Terms of Service page, in the active language.
  static String get terms => page('terms');

  /// Absolute URL of the Privacy Policy page, in the active language.
  static String get privacy => page('privacy');

  /// Absolute URL of the Contact page, in the active language.
  static String get contact => page('contact');

  /// The `magic_starter.legal` config block, resolved for the active language.
  ///
  /// magic_starter's sign-up screen reads `magic_starter.legal.terms_url` and
  /// `privacy_url`, opens each through `Launch.url`, and hides the whole legal
  /// line when both are null (`MagicStarterConfig.hasLegalLinks`). Filling the
  /// block from here is what shows a user the two documents BEFORE the account
  /// is created.
  static Map<String, String> get legalConfig => {
    'terms_url': terms,
    'privacy_url': privacy,
  };

  /// Composes the absolute URL of the [slug] page on the website, prefixed
  /// with the active language unless that language is the default one.
  static String page(String slug) => '${_origin()}${_localePrefix()}/$slug';

  /// Resolves the website origin, without a trailing slash.
  ///
  /// Reads the `app.web_url` config slot, which carries `WEB_URL`. The direct
  /// [env] read covers the one moment that slot cannot answer: `Magic.init`
  /// evaluates every config factory BEFORE merging the results into the
  /// repository, so a factory (the `magic_starter.legal` block) reads an empty
  /// repository. Both paths resolve the same `WEB_URL` key.
  static String _origin() {
    final String configured = _clean(Config.get<String>('app.web_url', ''));
    final String resolved = configured.isEmpty
        ? _clean(env('WEB_URL', kDefaultWebUrl))
        : configured;
    final String origin = resolved.isEmpty ? kDefaultWebUrl : resolved;

    return origin.replaceAll(RegExp(r'/+$'), '');
  }

  /// The path prefix for the active language.
  ///
  /// Empty for the default language (which lives on the bare path) and for any
  /// language the client does not list as supported.
  static String _localePrefix() {
    final String active = Lang.current.languageCode;
    if (active.isEmpty) return '';
    if (active == _defaultLocale()) return '';
    if (!_supportedLocales().contains(active)) return '';

    return '/$active';
  }

  /// The default language code, from `localization.locale`.
  static String _defaultLocale() {
    return _clean(Config.get<String>('localization.locale', ''));
  }

  /// The language codes the client supports, from
  /// `localization.supported_locales`.
  static List<String> _supportedLocales() {
    final List<dynamic> configured =
        Config.get<List<dynamic>>(
          'localization.supported_locales',
          const <dynamic>[],
        ) ??
        const <dynamic>[];

    return configured.map((dynamic code) => _clean(code.toString())).toList();
  }

  /// Strips surrounding whitespace and stray quotes from a raw config or env
  /// value.
  ///
  /// The `.env` parser can surface a quoted assignment as a literal quoted
  /// string (`APP_NAME=""` reaches the app as `""`, which is what
  /// `_resolveAppName` in `lib/config/app.dart` already defends against), and a
  /// quote inside an origin would produce an unopenable URL.
  static String _clean(String? value) {
    if (value == null) return '';

    return value.replaceAll('"', '').replaceAll("'", '').trim();
  }
}
