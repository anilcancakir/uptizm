import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/user.dart';

void main() {
  group('User Model - Timezone and Language', () {
    test('user timezone accessor', () {
      final user = User.fromMap({
        'id': 'test-user-uuid-1',
        'name': 'Test User',
        'email': 'test@example.com',
        'timezone': 'Europe/Istanbul',
      });

      expect(user.timezone, equals('Europe/Istanbul'));
    });

    test('user language accessor', () {
      final user = User.fromMap({
        'id': 'test-user-uuid-1',
        'name': 'Test User',
        'email': 'test@example.com',
        'language': 'tr',
      });

      expect(user.language, equals('tr'));
    });

    test('user timezone setter', () {
      final user = User.fromMap({
        'id': 'test-user-uuid-1',
        'name': 'Test User',
        'email': 'test@example.com',
      });

      user.timezone = 'America/New_York';

      expect(user.timezone, equals('America/New_York'));
    });

    test('user language setter', () {
      final user = User.fromMap({
        'id': 'test-user-uuid-1',
        'name': 'Test User',
        'email': 'test@example.com',
      });

      user.language = 'en';

      expect(user.language, equals('en'));
    });

    test('user timezone and language can be null', () {
      final user = User.fromMap({
        'id': 'test-user-uuid-1',
        'name': 'Test User',
        'email': 'test@example.com',
      });

      expect(user.timezone, isNull);
      expect(user.language, isNull);
    });

    test('user timezone and language in fillable list', () {
      final user = User();

      expect(user.fillable, contains('timezone'));
      expect(user.fillable, contains('language'));
    });
  });
}
