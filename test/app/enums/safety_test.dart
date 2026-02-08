import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/monitor_status.dart';
import 'package:uptizm/app/enums/monitor_type.dart';
import 'package:uptizm/app/enums/check_status.dart';
import 'package:uptizm/app/enums/http_method.dart';
import 'package:uptizm/app/enums/monitor_location.dart';
import 'package:uptizm/app/enums/api_key_location.dart';
import 'package:uptizm/app/enums/monitor_auth_type.dart';

void main() {
  group('Enum Safety Tests', () {
    test('MonitorStatus.fromValue handles invalid values safely', () {
      expect(MonitorStatus.fromValue('active'), MonitorStatus.active);
      expect(MonitorStatus.fromValue(null), isNull);
      expect(MonitorStatus.fromValue('invalid_value'), isNull);
    });

    test('MonitorType.fromValue handles invalid values safely', () {
      expect(MonitorType.fromValue('http'), MonitorType.http);
      expect(MonitorType.fromValue(null), isNull);
      expect(MonitorType.fromValue('invalid_value'), isNull);
    });

    test('CheckStatus.fromValue handles invalid values safely', () {
      expect(CheckStatus.fromValue('up'), CheckStatus.up);
      expect(CheckStatus.fromValue(null), isNull);
      expect(CheckStatus.fromValue('invalid_value'), isNull);
    });

    test('HttpMethod.fromValue handles invalid values safely', () {
      expect(HttpMethod.fromValue('GET'), HttpMethod.get);
      expect(HttpMethod.fromValue(null), isNull);
      expect(HttpMethod.fromValue('invalid_value'), isNull);
    });

    test('MonitorLocation.fromValue handles invalid values safely', () {
      expect(MonitorLocation.fromValue('us-east'), MonitorLocation.usEast);
      expect(MonitorLocation.fromValue(null), isNull);
      expect(MonitorLocation.fromValue('invalid_value'), isNull);
    });

    test('ApiKeyLocation.fromValue handles invalid values safely', () {
      expect(ApiKeyLocation.fromValue('header'), ApiKeyLocation.header);
      expect(ApiKeyLocation.fromValue(null), isNull);
      expect(ApiKeyLocation.fromValue('invalid_value'), isNull);
    });

    test('MonitorAuthType.fromValue handles invalid values safely', () {
      expect(
        MonitorAuthType.fromValue('basic_auth'),
        MonitorAuthType.basicAuth,
      );
      expect(MonitorAuthType.fromValue(null), isNull);
      expect(MonitorAuthType.fromValue('invalid_value'), isNull);
    });
  });
}
