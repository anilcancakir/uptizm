import 'package:magic/magic.dart';

enum MonitorAuthType {
  none('none', 'None'),
  basicAuth('basic_auth', 'Basic Auth'),
  bearerToken('bearer_token', 'Bearer Token'),
  apiKey('api_key', 'API Key'),
  customHeader('custom_header', 'Custom Header');

  const MonitorAuthType(this.value, this.label);

  final String value;
  final String label;

  static MonitorAuthType fromValue(String? value) {
    if (value == null) return MonitorAuthType.none;
    return MonitorAuthType.values.firstWhere(
      (type) => type.value == value,
      orElse: () => MonitorAuthType.none,
    );
  }

  static List<SelectOption<MonitorAuthType>> get selectOptions {
    return MonitorAuthType.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
