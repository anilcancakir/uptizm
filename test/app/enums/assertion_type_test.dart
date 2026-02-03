import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/assertion_type.dart';

void main() {
  group('AssertionType', () {
    test('has statusCode type', () {
      expect(AssertionType.statusCode.value, 'status_code');
      expect(AssertionType.statusCode.label, 'Status Code');
    });

    test('has bodyJsonPath type', () {
      expect(AssertionType.bodyJsonPath.value, 'body_json_path');
      expect(AssertionType.bodyJsonPath.label, 'JSON Path');
    });

    test('has bodyContains type', () {
      expect(AssertionType.bodyContains.value, 'body_contains');
      expect(AssertionType.bodyContains.label, 'Body Contains');
    });

    test('has bodyRegex type', () {
      expect(AssertionType.bodyRegex.value, 'body_regex');
      expect(AssertionType.bodyRegex.label, 'Body Regex');
    });

    test('has headerContains type', () {
      expect(AssertionType.headerContains.value, 'header_contains');
      expect(AssertionType.headerContains.label, 'Header Contains');
    });

    test('has responseTime type', () {
      expect(AssertionType.responseTime.value, 'response_time');
      expect(AssertionType.responseTime.label, 'Response Time');
    });

    test('fromValue returns correct type', () {
      expect(AssertionType.fromValue('status_code'), AssertionType.statusCode);
      expect(
        AssertionType.fromValue('body_json_path'),
        AssertionType.bodyJsonPath,
      );
      expect(
        AssertionType.fromValue('body_contains'),
        AssertionType.bodyContains,
      );
    });

    test('fromValue returns null for invalid value', () {
      expect(AssertionType.fromValue('invalid'), null);
      expect(AssertionType.fromValue(null), null);
    });

    test('selectOptions includes all types', () {
      final options = AssertionType.selectOptions;
      expect(options.length, 6);
      expect(options.any((opt) => opt.value == AssertionType.statusCode), true);
      expect(
        options.any((opt) => opt.value == AssertionType.bodyJsonPath),
        true,
      );
      expect(
        options.any((opt) => opt.value == AssertionType.bodyContains),
        true,
      );
      expect(options.any((opt) => opt.value == AssertionType.bodyRegex), true);
      expect(
        options.any((opt) => opt.value == AssertionType.headerContains),
        true,
      );
      expect(
        options.any((opt) => opt.value == AssertionType.responseTime),
        true,
      );
    });
  });
}
