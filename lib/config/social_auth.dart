import 'package:magic/magic.dart';

/// Social Authentication Configuration.
///
/// Configure social login providers here.
///
/// Usage:
///   Config.get('social_auth.endpoint')
///   Config.get('social_auth.providers.google.client_id')
Map<String, dynamic> get socialAuthConfig => {
  'social_auth': {
    // Backend endpoint for social auth
    'endpoint': '/auth/social/{provider}',

    // Provider configurations
    'providers': {
      'google': {
        'enabled': true,
        'client_id': env('GOOGLE_CLIENT_ID'),
        'server_client_id': env('GOOGLE_SERVER_CLIENT_ID'),
        'scopes': ['email', 'profile'],
      },
      'microsoft': {
        'enabled': true,
        'client_id': env('MICROSOFT_CLIENT_ID'),
        'tenant': env('MICROSOFT_TENANT', 'common'),
        'callback_scheme': 'uptizm', // Mobile/desktop
        'web_callback_url': env(
          'MICROSOFT_WEB_CALLBACK_URL',
          'http://localhost:8080/auth/callback',
        ), // Web
        'scopes': ['openid', 'profile', 'email'],
      },
      'github': {
        'enabled': true,
        'client_id': env('GITHUB_CLIENT_ID'),
        'callback_scheme': 'uptizm', // Mobile/desktop
        'web_callback_url': env(
          'GITHUB_WEB_CALLBACK_URL',
          'http://localhost:8080/auth/callback',
        ), // Web
        'scopes': ['read:user', 'user:email'],
      },
    },
  },
};
