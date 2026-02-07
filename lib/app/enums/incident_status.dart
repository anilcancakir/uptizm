import 'package:magic/magic.dart';

enum IncidentStatus {
  investigating('investigating', 'Investigating'),
  identified('identified', 'Identified'),
  monitoring('monitoring', 'Monitoring'),
  resolved('resolved', 'Resolved');

  const IncidentStatus(this.value, this.label);

  final String value;
  final String label;

  String get color {
    return switch (this) {
      IncidentStatus.investigating => 'gray',
      IncidentStatus.identified => 'orange',
      IncidentStatus.monitoring => 'blue',
      IncidentStatus.resolved => 'green',
    };
  }

  static IncidentStatus? fromValue(String? value) {
    if (value == null) return null;
    try {
      return IncidentStatus.values.firstWhere(
        (status) => status.value == value,
      );
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<IncidentStatus>> get selectOptions {
    return IncidentStatus.values
        .map((status) => SelectOption(value: status, label: status.label))
        .toList();
  }
}
