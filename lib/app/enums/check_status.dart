import 'package:fluttersdk_magic/fluttersdk_magic.dart';

enum CheckStatus {
  up('up', 'Up'),
  down('down', 'Down'),
  degraded('degraded', 'Degraded');

  const CheckStatus(this.value, this.label);

  final String value;
  final String label;

  static CheckStatus? fromValue(String? value) {
    if (value == null) return null;
    return CheckStatus.values.firstWhere(
      (status) => status.value == value,
      orElse: () => CheckStatus.down,
    );
  }

  static List<SelectOption<CheckStatus>> get selectOptions {
    return CheckStatus.values
        .map((status) => SelectOption(value: status, label: status.label))
        .toList();
  }
}
