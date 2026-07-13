import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';

void main() {
  group('CheckRow.fromMap', () {
    test('decodes a backend MonitorCheckResource payload', () {
      final CheckRow row = CheckRow.fromMap({
        'region': 'us-east',
        'status': 'up',
        'status_code': 200,
        'response_ms': 142,
        'checked_at': '2026-07-09T14:32:05.000Z',
        'error_message': null,
      });

      expect(row.region, 'us-east');
      expect(row.status, StatusKey.up);
      expect(row.statusCode, 200);
      expect(row.responseMs, 142);
    });

    test('an unknown status wire value falls back safely', () {
      final CheckRow row = CheckRow.fromMap({
        'region': 'eu-west',
        'status': 'totally_unknown',
        'checked_at': '2026-07-09T14:32:05.000Z',
      });

      expect(row.status, StatusKey.info);
    });
  });
}
