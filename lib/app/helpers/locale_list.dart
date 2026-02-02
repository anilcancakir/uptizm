import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Supported locale options for user selection
List<SelectOption<String>> get localeOptions => [
      SelectOption(value: 'en', label: 'English'),
      SelectOption(value: 'tr', label: 'Türkçe'),
    ];
