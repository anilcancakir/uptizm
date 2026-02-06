Map<String, dynamic> get deeplinkConfig => {
  'deeplink': {
    'enabled': true,
    'driver': 'app_links',
    'domain': 'uptizm.com',
    'scheme': 'https',

    'ios': {
      'team_id': 'ABC123XYZ', // Apple Developer Team ID
      'bundle_id': 'com.uptizm.app',
    },

    'android': {
      'package_name': 'com.uptizm.app',
      'sha256_fingerprints': [
        // Debug ve Release key fingerprintlerini buraya ekle
        'AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99',
      ],
    },

    'paths': ['/monitors/*', '/status-pages/*', '/teams/*', '/settings/*'],
  },
};
