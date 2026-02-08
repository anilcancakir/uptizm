import 'package:magic/magic.dart';

enum ApiKeyLocation {
  header('header', 'Header'),
  query('query', 'Query Parameter');

  const ApiKeyLocation(this.value, this.label);

  final String value;
  final String label;

  static ApiKeyLocation? fromValue(String? value) {
    if (value == null) return null;
    try {
      return ApiKeyLocation.values.firstWhere((type) => type.value == value);
    } catch (_) {
      return null;
    }
  }

  static List<SelectOption<ApiKeyLocation>> get selectOptions {
    return ApiKeyLocation.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
