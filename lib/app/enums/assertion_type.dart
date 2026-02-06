import 'package:magic/magic.dart';

enum AssertionType {
  statusCode('status_code', 'Status Code'),
  bodyJsonPath('body_json_path', 'JSON Path'),
  bodyContains('body_contains', 'Body Contains'),
  bodyRegex('body_regex', 'Body Regex'),
  headerContains('header_contains', 'Header Contains'),
  responseTime('response_time', 'Response Time');

  const AssertionType(this.value, this.label);

  final String value;
  final String label;

  static AssertionType? fromValue(String? value) {
    if (value == null) return null;
    try {
      return AssertionType.values.firstWhere((type) => type.value == value);
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<AssertionType>> get selectOptions {
    return AssertionType.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
