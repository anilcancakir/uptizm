import 'package:magic/magic.dart';
import '../app/providers/app_service_provider.dart';
import '../app/providers/route_service_provider.dart';
import '../app/support/web_links.dart' show kDefaultWebUrl;
import 'package:magic_deeplink/magic_deeplink.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_social_auth/magic_social_auth.dart';
import 'package:magic_starter/magic_starter.dart';

/// Resolves the app name used as the browser-tab title suffix.
///
/// Tolerates a blank or accidentally quoted `APP_NAME` (e.g. `APP_NAME=""`,
/// which the `.env` parser can surface as the literal two-character string
/// `""`): surrounding quotes are stripped and a blank result falls back to the
/// product default, so the tab title never renders an empty `""` suffix.
String _resolveAppName() {
  final String cleaned = env('APP_NAME', 'Uptizm').replaceAll('"', '').trim();
  return cleaned.isEmpty ? 'Uptizm' : cleaned;
}

/// Application Configuration.
Map<String, dynamic> get appConfig => {
  'app': {
    'name': _resolveAppName(),
    'env': env('APP_ENV', 'production'),
    'debug': env('APP_DEBUG', false),
    'key': env('APP_KEY'),
    'title_separator': ' | ',

    // The marketing website's origin, no trailing slash. The client owns no
    // legal text of its own: Terms, Privacy and Contact live on the website and
    // are opened externally, so their one text stays in one place. [WebLinks]
    // reads this slot and composes the per-language path.
    'web_url': env('WEB_URL', kDefaultWebUrl),
    'providers': [
      (app) => RouteServiceProvider(app),
      (app) => CacheServiceProvider(app),
      (app) => DatabaseServiceProvider(app),
      (app) => LaunchServiceProvider(app),
      (app) => LocalizationServiceProvider(app),
      (app) => NetworkServiceProvider(app),
      (app) => VaultServiceProvider(app),
      (app) => BroadcastServiceProvider(app),
      (app) => AppServiceProvider(app),
      (app) => AuthServiceProvider(app),
      (app) => DeeplinkServiceProvider(app),
      (app) => NotificationServiceProvider(app),
      (app) => SocialAuthServiceProvider(app),
      (app) => MagicStarterServiceProvider(app),
    ],
  },
};
