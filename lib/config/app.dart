import 'package:magic/magic.dart';
import 'package:magic_deeplink/magic_deeplink.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_social_auth/magic_social_auth.dart';
import 'package:magic_starter/magic_starter.dart';

import '../app/providers/app_service_provider.dart';
import '../app/providers/route_service_provider.dart';
import '../app/support/env_strings.dart' show envString;
import '../app/support/web_links.dart' show kDefaultWebUrl;

/// Application Configuration.
Map<String, dynamic> get appConfig => {
  'app': {
    // Through [envString], not [env]: a key that is PRESENT and blank resolves
    // to `''` rather than to the default, which is how a deployed `APP_NAME=""`
    // put `Monitor | ""` in the browser tab. The same read now covers every
    // string setting here.
    'name': envString('APP_NAME', 'Uptizm'),
    'env': envString('APP_ENV', 'production'),
    'debug': env('APP_DEBUG', false),
    'key': env('APP_KEY'),
    'title_separator': ' | ',

    // The marketing website's origin, no trailing slash. The client owns no
    // legal text of its own: Terms, Privacy and Contact live on the website and
    // are opened externally, so their one text stays in one place. [WebLinks]
    // reads this slot and composes the per-language path.
    'web_url': envString('WEB_URL', kDefaultWebUrl),
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
