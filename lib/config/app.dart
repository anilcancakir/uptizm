import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_social_auth/magic_social_auth.dart';
import 'package:magic_deeplink/magic_deeplink.dart';

import '../app/providers/app_service_provider.dart';
import '../app/providers/event_service_provider.dart';
import '../app/providers/route_service_provider.dart';

/// Application Configuration
Map<String, dynamic> get appConfig => {
  'app': {
    'name': 'Uptizm',
    'env': env('APP_ENV', 'production'),
    'debug': env('APP_DEBUG', false),
    'url': env('APP_URL', 'http://localhost'),
    'key': env('APP_KEY'),
    'providers': [
      (app) => CacheServiceProvider(app),
      (app) => RouteServiceProvider(app),
      (app) => AppServiceProvider(app),
      (app) => LocalizationServiceProvider(app),
      (app) => NetworkServiceProvider(app),
      (app) => VaultServiceProvider(app),
      (app) => AuthServiceProvider(app),
      (app) => SocialAuthServiceProvider(app),
      (app) => NotificationServiceProvider(app),
      (app) => DeeplinkServiceProvider(app),
      (app) => EventServiceProvider(app),
    ],
  },
};
