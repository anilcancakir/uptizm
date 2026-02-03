import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/helpers/json_path_resolver.dart';

void main() {
  group('JsonPathResolver', () {
    test('resolves simple key', () {
      expect(JsonPathResolver.resolve({'name': 'test'}, 'name'), 'test');
    });

    test('resolves nested path', () {
      expect(
        JsonPathResolver.resolve({
          'data': {'status': 'ok'},
        }, 'data.status'),
        'ok',
      );
    });

    test('resolves deeply nested', () {
      expect(
        JsonPathResolver.resolve({
          'a': {
            'b': {'c': 42},
          },
        }, 'a.b.c'),
        42,
      );
    });

    test('resolves array index', () {
      expect(
        JsonPathResolver.resolve({
          'items': [10, 20, 30],
        }, 'items.0'),
        10,
      );
    });

    test('resolves nested array object', () {
      expect(
        JsonPathResolver.resolve({
          'data': {
            'items': [
              {'name': 'a'},
            ],
          },
        }, 'data.items.0.name'),
        'a',
      );
    });

    test('returns null for missing key', () {
      expect(JsonPathResolver.resolve({'a': 1}, 'b'), null);
    });

    test('returns null for missing nested key', () {
      expect(JsonPathResolver.resolve({'a': {}}, 'a.b.c'), null);
    });

    test('returns null for out of bounds index', () {
      expect(
        JsonPathResolver.resolve({
          'items': [1],
        }, 'items.5'),
        null,
      );
    });

    test('returns null for null input', () {
      expect(JsonPathResolver.resolve(null, 'a'), null);
    });

    test('returns null for empty path', () {
      expect(JsonPathResolver.resolve({'a': 1}, ''), null);
    });

    test('handles numeric string values', () {
      expect(JsonPathResolver.resolve({'size': '15MB'}, 'size'), '15MB');
    });
  });
}
