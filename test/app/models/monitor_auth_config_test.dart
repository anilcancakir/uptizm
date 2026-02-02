import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/api_key_location.dart';
import 'package:uptizm/app/enums/monitor_auth_type.dart';
import 'package:uptizm/app/models/monitor_auth_config.dart';

void main() {
  group('MonitorAuthConfig', () {
    test('fromMap returns none type for null map', () {
      final config = MonitorAuthConfig.fromMap(null);
      expect(config.type, MonitorAuthType.none);
    });

    test('fromMap returns none type for empty map', () {
      final config = MonitorAuthConfig.fromMap({});
      expect(config.type, MonitorAuthType.none);
    });

    test('fromMap/toMap roundtrip for none type', () {
      final original = MonitorAuthConfig(type: MonitorAuthType.none);
      final map = original.toMap();
      final restored = MonitorAuthConfig.fromMap(map);

      expect(restored.type, MonitorAuthType.none);
      expect(map['type'], 'none');
    });

    test('fromMap/toMap roundtrip for basic_auth', () {
      final original = MonitorAuthConfig(
        type: MonitorAuthType.basicAuth,
        basicAuthUsername: 'admin',
        basicAuthPassword: 'secret123',
      );
      final map = original.toMap();
      final restored = MonitorAuthConfig.fromMap(map);

      expect(restored.type, MonitorAuthType.basicAuth);
      expect(restored.basicAuthUsername, 'admin');
      expect(restored.basicAuthPassword, 'secret123');
      expect(map['basic_auth']['username'], 'admin');
      expect(map['basic_auth']['password'], 'secret123');
    });

    test('fromMap/toMap roundtrip for bearer_token', () {
      final original = MonitorAuthConfig(
        type: MonitorAuthType.bearerToken,
        bearerToken: 'my-jwt-token',
      );
      final map = original.toMap();
      final restored = MonitorAuthConfig.fromMap(map);

      expect(restored.type, MonitorAuthType.bearerToken);
      expect(restored.bearerToken, 'my-jwt-token');
      expect(map['bearer_token']['token'], 'my-jwt-token');
    });

    test('fromMap/toMap roundtrip for api_key with header location', () {
      final original = MonitorAuthConfig(
        type: MonitorAuthType.apiKey,
        apiKeyName: 'X-API-Key',
        apiKeyValue: 'abc123',
        apiKeyLocation: ApiKeyLocation.header,
      );
      final map = original.toMap();
      final restored = MonitorAuthConfig.fromMap(map);

      expect(restored.type, MonitorAuthType.apiKey);
      expect(restored.apiKeyName, 'X-API-Key');
      expect(restored.apiKeyValue, 'abc123');
      expect(restored.apiKeyLocation, ApiKeyLocation.header);
    });

    test('fromMap/toMap roundtrip for api_key with query location', () {
      final original = MonitorAuthConfig(
        type: MonitorAuthType.apiKey,
        apiKeyName: 'api_key',
        apiKeyValue: 'xyz789',
        apiKeyLocation: ApiKeyLocation.query,
      );
      final map = original.toMap();
      final restored = MonitorAuthConfig.fromMap(map);

      expect(restored.type, MonitorAuthType.apiKey);
      expect(restored.apiKeyName, 'api_key');
      expect(restored.apiKeyValue, 'xyz789');
      expect(restored.apiKeyLocation, ApiKeyLocation.query);
    });

    test('fromMap/toMap roundtrip for custom_header', () {
      final original = MonitorAuthConfig(
        type: MonitorAuthType.customHeader,
        customHeaders: {'X-Custom-Auth': 'token123', 'X-Tenant': 'acme'},
      );
      final map = original.toMap();
      final restored = MonitorAuthConfig.fromMap(map);

      expect(restored.type, MonitorAuthType.customHeader);
      expect(restored.customHeaders, {'X-Custom-Auth': 'token123', 'X-Tenant': 'acme'});
    });

    test('toMap omits irrelevant keys', () {
      final config = MonitorAuthConfig(
        type: MonitorAuthType.basicAuth,
        basicAuthUsername: 'admin',
        basicAuthPassword: 'secret',
      );
      final map = config.toMap();

      expect(map.containsKey('type'), true);
      expect(map.containsKey('basic_auth'), true);
      expect(map.containsKey('bearer_token'), false);
      expect(map.containsKey('api_key'), false);
      expect(map.containsKey('custom_header'), false);
    });

    test('none() factory returns none config', () {
      final config = MonitorAuthConfig.none();
      expect(config.type, MonitorAuthType.none);
    });
  });
}
