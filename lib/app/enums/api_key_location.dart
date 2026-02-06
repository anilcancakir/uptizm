import 'package:magic/magic.dart';

enum ApiKeyLocation {
  header('header', 'Header'),
  query('query', 'Query Parameter');

  const ApiKeyLocation(this.value, this.label);

  final String value;
  final String label;

  static ApiKeyLocation fromValue(String? value) {
    if (value == null) return ApiKeyLocation.header;
    return ApiKeyLocation.values.firstWhere(
      (type) => type.value == value,
      orElse: () => ApiKeyLocation.header,
    );
  }

  static List<SelectOption<ApiKeyLocation>> get selectOptions {
    return ApiKeyLocation.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
