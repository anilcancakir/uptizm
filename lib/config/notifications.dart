import 'package:magic/magic.dart';

/// Magic Notifications Configuration
Map<String, dynamic> get notificationConfig => {
  'notifications': {
    'push': {
      'driver': 'onesignal',
      'app_id': env('ONESIGNAL_APP_ID', ''),
      'safari_web_id': env('ONESIGNAL_SAFARI_WEB_ID', ''),
      'notify_button_enabled': false,
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
