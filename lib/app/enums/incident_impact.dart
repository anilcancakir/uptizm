import 'package:magic/magic.dart';

enum IncidentImpact {
  majorOutage('major_outage', 'Major Outage'),
  partialOutage('partial_outage', 'Partial Outage'),
  degradedPerformance('degraded_performance', 'Degraded Performance'),
  underMaintenance('under_maintenance', 'Under Maintenance');

  const IncidentImpact(this.value, this.label);

  final String value;
  final String label;

  String get color {
    return switch (this) {
      IncidentImpact.majorOutage => 'red',
      IncidentImpact.partialOutage => 'orange',
      IncidentImpact.degradedPerformance => 'yellow',
      IncidentImpact.underMaintenance => 'blue',
    };
  }

  String get icon {
    return switch (this) {
      IncidentImpact.majorOutage => 'close',
      IncidentImpact.partialOutage => 'warning',
      IncidentImpact.degradedPerformance => 'speed',
      IncidentImpact.underMaintenance => 'build',
    };
  }

  static IncidentImpact? fromValue(String? value) {
    if (value == null) return null;
    try {
      return IncidentImpact.values.firstWhere(
        (impact) => impact.value == value,
      );
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<IncidentImpact>> get selectOptions {
    return IncidentImpact.values
        .map((impact) => SelectOption(value: impact, label: impact.label))
        .toList();
  }
}
