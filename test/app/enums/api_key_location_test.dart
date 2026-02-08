import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/api_key_location.dart';

void main() {
  group('ApiKeyLocation', () {
    test('has 2 values', () {
      expect(ApiKeyLocation.values.length, 2);
    });

    test('header location', () {
      expect(ApiKeyLocation.header.value, 'header');
      expect(ApiKeyLocation.header.label, 'Header');
    });

    test('query location', () {
      expect(ApiKeyLocation.query.value, 'query');
      expect(ApiKeyLocation.query.label, 'Query Parameter');
    });

    test('fromValue returns correct location', () {
      expect(ApiKeyLocation.fromValue('header'), ApiKeyLocation.header);
      expect(ApiKeyLocation.fromValue('query'), ApiKeyLocation.query);
    });

    test('fromValue returns null for null', () {
      expect(ApiKeyLocation.fromValue(null), isNull);
    });

    test('selectOptions returns both', () {
      final options = ApiKeyLocation.selectOptions;
      expect(options.length, 2);
    });
  });
}
