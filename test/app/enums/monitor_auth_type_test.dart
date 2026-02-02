import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/monitor_auth_type.dart';

void main() {
  group('MonitorAuthType', () {
    test('has 5 values', () {
      expect(MonitorAuthType.values.length, 5);
    });

    test('none type', () {
      expect(MonitorAuthType.none.value, 'none');
      expect(MonitorAuthType.none.label, 'None');
    });

    test('basicAuth type', () {
      expect(MonitorAuthType.basicAuth.value, 'basic_auth');
      expect(MonitorAuthType.basicAuth.label, 'Basic Auth');
    });

    test('bearerToken type', () {
      expect(MonitorAuthType.bearerToken.value, 'bearer_token');
      expect(MonitorAuthType.bearerToken.label, 'Bearer Token');
    });

    test('apiKey type', () {
      expect(MonitorAuthType.apiKey.value, 'api_key');
      expect(MonitorAuthType.apiKey.label, 'API Key');
    });

    test('customHeader type', () {
      expect(MonitorAuthType.customHeader.value, 'custom_header');
      expect(MonitorAuthType.customHeader.label, 'Custom Header');
    });

    test('fromValue returns correct type', () {
      expect(MonitorAuthType.fromValue('none'), MonitorAuthType.none);
      expect(MonitorAuthType.fromValue('basic_auth'), MonitorAuthType.basicAuth);
      expect(MonitorAuthType.fromValue('bearer_token'), MonitorAuthType.bearerToken);
      expect(MonitorAuthType.fromValue('api_key'), MonitorAuthType.apiKey);
      expect(MonitorAuthType.fromValue('custom_header'), MonitorAuthType.customHeader);
    });

    test('fromValue returns none for null', () {
      expect(MonitorAuthType.fromValue(null), MonitorAuthType.none);
    });

    test('fromValue returns none for unknown value', () {
      expect(MonitorAuthType.fromValue('unknown'), MonitorAuthType.none);
    });

    test('selectOptions returns all types', () {
      final options = MonitorAuthType.selectOptions;
      expect(options.length, 5);
      expect(options.first.value, MonitorAuthType.none);
      expect(options.last.value, MonitorAuthType.customHeader);
    });
  });
}
