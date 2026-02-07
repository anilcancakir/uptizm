import 'package:magic/magic.dart';

enum AnnouncementType {
  maintenance('maintenance', 'Maintenance'),
  improvement('improvement', 'Improvement'),
  informational('informational', 'Informational');

  const AnnouncementType(this.value, this.label);

  final String value;
  final String label;

  String get color {
    return switch (this) {
      AnnouncementType.maintenance => 'blue',
      AnnouncementType.improvement => 'green',
      AnnouncementType.informational => 'gray',
    };
  }

  String get icon {
    return switch (this) {
      AnnouncementType.maintenance => 'build',
      AnnouncementType.improvement => 'trending_up',
      AnnouncementType.informational => 'info',
    };
  }

  static AnnouncementType? fromValue(String? value) {
    if (value == null) return null;
    try {
      return AnnouncementType.values.firstWhere((type) => type.value == value);
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<AnnouncementType>> get selectOptions {
    return AnnouncementType.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
