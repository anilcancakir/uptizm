import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/config/social_auth.dart';

void main() {
  group('Social Auth Config', () {
    test('config structure is valid', () {
      final config = socialAuthConfig;

      // Verify top-level structure
      expect(config['social_auth'], isNotNull);
      expect(config['social_auth']['endpoint'], isNotNull);
      expect(config['social_auth']['providers'], isNotNull);

      // Verify providers exist
      final providers = config['social_auth']['providers'] as Map<String, dynamic>;
      expect(providers['google'], isNotNull);
      expect(providers['microsoft'], isNotNull);
      expect(providers['github'], isNotNull);

      // Verify each provider has required fields
      expect(providers['google']['enabled'], isA<bool>());
      expect(providers['google']['scopes'], isA<List>());

      expect(providers['microsoft']['enabled'], isA<bool>());
      expect(providers['microsoft']['scopes'], isA<List>());

      expect(providers['github']['enabled'], isA<bool>());
      expect(providers['github']['scopes'], isA<List>());
    });
  });
}
