import '../app/support/env_strings.dart' show envString;

/// Broadcasting configuration.
///
/// Defines the default broadcasting connection and available connections.
/// See: https://magic.fluttersdk.com/docs/broadcasting
Map<String, dynamic> get broadcastingConfig => {
  'broadcasting': {
    // Through [envString] throughout: a present-but-blank key resolves to `''`
    // rather than to the default, and a blank host or scheme builds a malformed
    // socket URL that the boot-time Echo connect turns into an uncaught
    // exception and a blank app.
    'default': envString('BROADCAST_CONNECTION', 'null'),
    'connections': {
      'reverb': {
        'driver': 'reverb',
        'host': envString('REVERB_HOST', 'localhost'),
        'port': int.tryParse(envString('REVERB_PORT', '8080')) ?? 8080,
        'scheme': envString('REVERB_SCHEME', 'ws'),
        'app_key': envString('REVERB_APP_KEY', ''),
        'auth_endpoint': '/broadcasting/auth',
        'reconnect': true,
        'max_reconnect_delay': 30000,
        'activity_timeout': 120,
        'dedup_buffer_size': 100,
      },
      'null': {'driver': 'null'},
    },
  },
};
