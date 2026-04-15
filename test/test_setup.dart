import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

/// Initialize Magic framework for widget tests.
///
/// Uses a minimal config set that registers core services (log, auth, cache)
/// without starting background timers that would cause test failures.
Future<void> initMagicForTests() async {
  TestWidgetsFlutterBinding.ensureInitialized();

  final testDir = '${Directory.systemTemp.path}/uptizm_test';
  await Directory(testDir).create(recursive: true);

  // Mock path_provider platform channel for test environment
  TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
      .setMockMethodCallHandler(
        const MethodChannel('plugins.flutter.io/path_provider'),
        (MethodCall methodCall) async {
          return testDir;
        },
      );

  await Magic.init(
    configs: [
      {
        'app': {
          'name': 'Uptizm Test',
          'env': 'testing',
          'debug': true,
          'providers': [
            (app) => CacheServiceProvider(app),
            (app) => LocalizationServiceProvider(app),
            (app) => AuthServiceProvider(app),
          ],
        },
      },
      {
        'auth': {
          'defaults': {'guard': 'api'},
          'guards': {
            'api': {'driver': 'bearer'},
          },
          'auto_refresh': false,
          'cache': {'user_key': 'auth_user'},
          'token': {
            'key': 'auth_token',
            'refresh_key': 'refresh_token',
            'header': 'Authorization',
            'prefix': 'Bearer',
          },
        },
      },
      {
        'cache': {'driver': FileStore(), 'ttl': 3600},
      },
      {
        'logging': {
          'default': 'stack',
          'channels': {
            'stack': {
              'driver': 'stack',
              'channels': ['console'],
            },
            'console': {'driver': 'console', 'level': 'debug'},
          },
        },
      },
    ],
  );
}
