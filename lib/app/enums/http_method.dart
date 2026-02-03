import 'package:fluttersdk_magic/fluttersdk_magic.dart';

enum HttpMethod {
  get('GET', 'GET'),
  post('POST', 'POST'),
  put('PUT', 'PUT'),
  head('HEAD', 'HEAD');

  const HttpMethod(this.value, this.label);

  final String value;
  final String label;

  static HttpMethod? fromValue(String? value) {
    if (value == null) return null;
    return HttpMethod.values.firstWhere(
      (method) => method.value == value,
      orElse: () => HttpMethod.get,
    );
  }

  static List<SelectOption<HttpMethod>> get selectOptions {
    return HttpMethod.values
        .map((method) => SelectOption(value: method, label: method.label))
        .toList();
  }
}
