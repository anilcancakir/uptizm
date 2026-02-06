import 'package:magic/magic.dart';

enum MonitorLocation {
  usEast('us-east', 'US East'),
  usWest('us-west', 'US West'),
  euWest('eu-west', 'EU West'),
  euCentral('eu-central', 'EU Central'),
  apSoutheast('ap-southeast', 'AP Southeast'),
  apNortheast('ap-northeast', 'AP Northeast');

  const MonitorLocation(this.value, this.label);

  final String value;
  final String label;

  static MonitorLocation? fromValue(String? value) {
    if (value == null) return null;
    return MonitorLocation.values.firstWhere(
      (location) => location.value == value,
      orElse: () => MonitorLocation.usEast,
    );
  }

  static List<SelectOption<MonitorLocation>> get selectOptions {
    return MonitorLocation.values
        .map((location) => SelectOption(value: location, label: location.label))
        .toList();
  }

  static List<MonitorLocation> fromValueList(List<dynamic>? values) {
    if (values == null) return [];
    return values
        .map((v) => fromValue(v.toString()))
        .whereType<MonitorLocation>()
        .toList();
  }

  static List<String> toValueList(List<MonitorLocation> locations) {
    return locations.map((location) => location.value).toList();
  }
}
