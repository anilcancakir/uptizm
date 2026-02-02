import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/check_status.dart';

void main() {
  group('CheckStatus', () {
    test('has 3 values', () {
      expect(CheckStatus.values.length, 3);
    });

    test('up type', () {
      expect(CheckStatus.up.value, 'up');
      expect(CheckStatus.up.label, 'Up');
    });

    test('down type', () {
      expect(CheckStatus.down.value, 'down');
      expect(CheckStatus.down.label, 'Down');
    });

    test('degraded type', () {
      expect(CheckStatus.degraded.value, 'degraded');
      expect(CheckStatus.degraded.label, 'Degraded');
    });

    test('fromValue returns correct status', () {
      expect(CheckStatus.fromValue('up'), CheckStatus.up);
      expect(CheckStatus.fromValue('down'), CheckStatus.down);
      expect(CheckStatus.fromValue('degraded'), CheckStatus.degraded);
    });

    test('fromValue returns null for null', () {
      expect(CheckStatus.fromValue(null), null);
    });

    test('fromValue returns down for unknown value', () {
      expect(CheckStatus.fromValue('unknown'), CheckStatus.down);
    });

    test('selectOptions returns 3 options', () {
      final options = CheckStatus.selectOptions;
      expect(options.length, 3);
      expect(options[0].value, CheckStatus.up);
      expect(options[1].value, CheckStatus.down);
      expect(options[2].value, CheckStatus.degraded);
    });
  });
}
