import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/assertion_type.dart';
import 'package:uptizm/app/enums/assertion_operator.dart';
import 'package:uptizm/app/models/assertion_rule.dart';

void main() {
  group('AssertionRule', () {
    group('fromMap/toMap roundtrip', () {
      test('status code assertion', () {
        final rule = AssertionRule(
          type: AssertionType.statusCode,
          operator: AssertionOperator.equals,
          value: '200',
        );

        final map = rule.toMap();
        final restored = AssertionRule.fromMap(map);

        expect(restored.type, AssertionType.statusCode);
        expect(restored.operator, AssertionOperator.equals);
        expect(restored.value, '200');
        expect(restored.path, null);
      });

      test('JSON path assertion with path', () {
        final rule = AssertionRule(
          type: AssertionType.bodyJsonPath,
          operator: AssertionOperator.equals,
          value: 'healthy',
          path: 'data.status',
        );

        final map = rule.toMap();
        final restored = AssertionRule.fromMap(map);

        expect(restored.type, AssertionType.bodyJsonPath);
        expect(restored.operator, AssertionOperator.equals);
        expect(restored.value, 'healthy');
        expect(restored.path, 'data.status');
      });

      test('response time assertion', () {
        final rule = AssertionRule(
          type: AssertionType.responseTime,
          operator: AssertionOperator.lessThan,
          value: '1000',
        );

        final map = rule.toMap();
        final restored = AssertionRule.fromMap(map);

        expect(restored.type, AssertionType.responseTime);
        expect(restored.operator, AssertionOperator.lessThan);
        expect(restored.value, '1000');
      });
    });

    group('toDisplayString', () {
      test('status code displays correctly', () {
        final rule = AssertionRule(
          type: AssertionType.statusCode,
          operator: AssertionOperator.equals,
          value: '200',
        );

        expect(rule.toDisplayString(), 'status_code == 200');
      });

      test('JSON path with path displays correctly', () {
        final rule = AssertionRule(
          type: AssertionType.bodyJsonPath,
          operator: AssertionOperator.equals,
          value: 'healthy',
          path: 'data.status',
        );

        expect(rule.toDisplayString(), 'data.status == healthy');
      });

      test('body contains displays correctly', () {
        final rule = AssertionRule(
          type: AssertionType.bodyContains,
          operator: AssertionOperator.contains,
          value: 'success',
        );

        expect(rule.toDisplayString(), 'body contains success');
      });

      test('response time displays correctly', () {
        final rule = AssertionRule(
          type: AssertionType.responseTime,
          operator: AssertionOperator.lessThan,
          value: '1000',
        );

        expect(rule.toDisplayString(), 'response_time < 1000');
      });

      test('regex displays correctly', () {
        final rule = AssertionRule(
          type: AssertionType.bodyRegex,
          operator: AssertionOperator.matchesRegex,
          value: '^[0-9]+\$',
        );

        expect(rule.toDisplayString(), 'body matches ^[0-9]+\$');
      });
    });

    group('toMap', () {
      test('includes all fields', () {
        final rule = AssertionRule(
          type: AssertionType.bodyJsonPath,
          operator: AssertionOperator.equals,
          value: 'healthy',
          path: 'data.status',
        );

        final map = rule.toMap();

        expect(map['type'], 'body_json_path');
        expect(map['operator'], 'equals');
        expect(map['value'], 'healthy');
        expect(map['path'], 'data.status');
      });

      test('omits null path', () {
        final rule = AssertionRule(
          type: AssertionType.statusCode,
          operator: AssertionOperator.equals,
          value: '200',
        );

        final map = rule.toMap();

        expect(map.containsKey('path'), false);
      });
    });

    group('fromMap', () {
      test('parses all fields', () {
        final map = {
          'type': 'body_json_path',
          'operator': 'equals',
          'value': 'healthy',
          'path': 'data.status',
        };

        final rule = AssertionRule.fromMap(map);

        expect(rule.type, AssertionType.bodyJsonPath);
        expect(rule.operator, AssertionOperator.equals);
        expect(rule.value, 'healthy');
        expect(rule.path, 'data.status');
      });

      test('handles missing path', () {
        final map = {
          'type': 'status_code',
          'operator': 'equals',
          'value': '200',
        };

        final rule = AssertionRule.fromMap(map);

        expect(rule.type, AssertionType.statusCode);
        expect(rule.path, null);
      });
    });
  });
}
