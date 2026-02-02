import 'package:fluttersdk_wind/fluttersdk_wind.dart';

enum MonitorType {
  http('http', 'HTTP'),
  ping('ping', 'Ping'),
  port('port', 'Port');

  const MonitorType(this.value, this.label);

  final String value;
  final String label;

  static MonitorType? fromValue(String? value) {
    if (value == null) return null;
    return MonitorType.values.firstWhere(
      (type) => type.value == value,
      orElse: () => MonitorType.http,
    );
  }

  static List<SelectOption<MonitorType>> get selectOptions {
    return MonitorType.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
