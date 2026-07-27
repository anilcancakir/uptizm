/// Localization Configuration.
///
/// Mirrors the shape of magic's own `defaultLocalizationConfig`
/// (`../magic/lib/config/localization.dart`) but overrides its defaults:
/// magic ships with `auto_detect_locale`/`auto_detect_timezone` OFF and
/// `supported_locales: ['en']` only. This map is merged OVER that default in
/// `Magic.init`'s `configFactories` (see `lib/main.dart`), turning both
/// auto-detections on and registering `tr` as a supported locale.
Map<String, dynamic> get localizationConfig => {
  'localization': {
    /// The default locale for the application.
    'locale': 'en',

    /// The fallback locale when a translation is not found.
    'fallback_locale': 'en',

    /// List of supported locales.
    'supported_locales': ['en', 'tr'],

    /// Auto-detect locale from device/browser on app start.
    'auto_detect_locale': true,

    /// Path to translation JSON files.
    'path': 'assets/lang',

    /// Default IANA timezone for date operations, used when detection is
    /// disabled or fails.
    'timezone': 'UTC',

    /// Auto-detect timezone from device on app start. Magic reads the real
    /// platform IANA identifier (through `flutter_timezone`) and its
    /// `LocalizationServiceProvider` boots `DateManager` itself, so nothing
    /// needs feeding in by hand. When no valid zone resolves, the `timezone`
    /// above stays in effect rather than a guess.
    'auto_detect_timezone': true,

    /// Default date format pattern.
    'date_format': 'MMMM do yyyy',
  },
};
