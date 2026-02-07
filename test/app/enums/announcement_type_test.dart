import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/announcement_type.dart';

void main() {
  group('AnnouncementType', () {
    test('has maintenance value', () {
      expect(AnnouncementType.maintenance.value, 'maintenance');
      expect(AnnouncementType.maintenance.label, 'Maintenance');
    });

    test('has improvement value', () {
      expect(AnnouncementType.improvement.value, 'improvement');
      expect(AnnouncementType.improvement.label, 'Improvement');
    });

    test('has informational value', () {
      expect(AnnouncementType.informational.value, 'informational');
      expect(AnnouncementType.informational.label, 'Informational');
    });

    test('fromValue returns correct type', () {
      expect(
        AnnouncementType.fromValue('maintenance'),
        AnnouncementType.maintenance,
      );
      expect(
        AnnouncementType.fromValue('improvement'),
        AnnouncementType.improvement,
      );
      expect(
        AnnouncementType.fromValue('informational'),
        AnnouncementType.informational,
      );
    });

    test('fromValue returns null for invalid value', () {
      expect(AnnouncementType.fromValue('invalid'), isNull);
      expect(AnnouncementType.fromValue(null), isNull);
    });

    test('selectOptions includes all types', () {
      final options = AnnouncementType.selectOptions;
      expect(options.length, 3);
      expect(
        options.any((opt) => opt.value == AnnouncementType.maintenance),
        true,
      );
      expect(
        options.any((opt) => opt.value == AnnouncementType.improvement),
        true,
      );
      expect(
        options.any((opt) => opt.value == AnnouncementType.informational),
        true,
      );
    });

    test('color getter returns correct colors', () {
      expect(AnnouncementType.maintenance.color, 'blue');
      expect(AnnouncementType.improvement.color, 'green');
      expect(AnnouncementType.informational.color, 'gray');
    });

    test('icon getter returns correct icons', () {
      expect(AnnouncementType.maintenance.icon, 'build');
      expect(AnnouncementType.improvement.icon, 'trending_up');
      expect(AnnouncementType.informational.icon, 'info');
    });
  });
}
