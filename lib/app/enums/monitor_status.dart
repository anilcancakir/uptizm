import 'package:fluttersdk_wind/fluttersdk_wind.dart';

enum MonitorStatus {
  active('active', 'Active'),
  paused('paused', 'Paused'),
  maintenance('maintenance', 'Maintenance');

  const MonitorStatus(this.value, this.label);

  final String value;
  final String label;

  static MonitorStatus? fromValue(String? value) {
    if (value == null) return null;
    return MonitorStatus.values.firstWhere(
      (status) => status.value == value,
      orElse: () => MonitorStatus.active,
    );
  }

  static List<SelectOption<MonitorStatus>> get selectOptions {
    return MonitorStatus.values
        .map((status) => SelectOption(value: status, label: status.label))
        .toList();
  }
}
