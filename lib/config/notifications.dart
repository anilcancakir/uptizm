/// Magic Notifications Configuration
Map<String, dynamic> get notificationConfig => {
  'notifications': {
    'push': {
      'driver': 'onesignal',
      'app_id': '4573490d-2dfa-44c3-b211-8e04e2e96bdd',
    },
    'database': {
      'enabled': true,
      'polling_interval': 30, // seconds
    },
    'mail': {'enabled': false},
    'soft_prompt': {
      'enabled': true,
      'title': 'Enable Notifications',
      'message': 'Stay updated with important alerts and updates',
    },
  },
};
