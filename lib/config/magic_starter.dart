/// Magic Starter Configuration
///
/// Feature flags and route configuration for the magic_starter plugin.
/// See [MagicStarterConfig] for how these values are consumed.
Map<String, dynamic> get magicStarterConfig => {
  'magic_starter': {
    'features': {
      'teams': true,
      'profile_photos': true,
      'registration': true,
      'social_login': true,
    },
    'routes': {
      'home': '/',
      'login': '/auth/login',
      'auth_prefix': '/auth',
      'teams_prefix': '/teams',
      'profile_prefix': '/settings',
    },
  },
};
