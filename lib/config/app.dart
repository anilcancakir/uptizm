import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../app/providers/app_service_provider.dart';
import '../app/providers/route_service_provider.dart';

/// Application Configuration
Map<String, dynamic> get appConfig => {
  'app': {
    'name': 'Uptizm',
    'env': 'local',
    'debug': true,
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
    ],
  },
};
