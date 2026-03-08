/// Magic Starter Configuration
///
/// Feature flags and route configuration for the magic_starter plugin.
/// See [MagicStarterConfig] for how these values are consumed.
Map<String, dynamic> get magicStarterConfig => {
  'magic_starter': {
    'features': {
      'teams': true,
      'registration': true,
      'extended_profile': true,
      'profile_photos': true,
      'social_login': true,
      'two_factor': true,
      'sessions': true,
      'phone_otp': true,
      'newsletter': true,
      'notifications': true,
      'email_verification': true,
      'guest_auth': false,
      'timezones': true,
    },
    'auth': {'email': true, 'phone': false},
    'defaults': {'locale': 'en', 'timezone': 'UTC'},
    'supported_locales': ['en', 'tr'],
    'routes': {
      'home': '/',
      'login': '/auth/login',
      'auth_prefix': '/auth',
      'teams_prefix': '/teams',
      'profile_prefix': '/settings',
      'notifications_prefix': '/notifications',
    },
    'legal': {'terms_url': null, 'privacy_url': null},
  },
};
