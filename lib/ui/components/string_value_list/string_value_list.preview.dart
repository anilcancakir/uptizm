import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'string_value_list.dart';

/// Static variant-matrix preview for [StringValueList].
///
/// Renders every [StringValueListTone] crossed with an empty and a populated
/// state (six panels), each independently controlled. One preview class per
/// file; discovered by `previews:refresh`.
class StringValueListPreview extends StatefulWidget {
  /// Creates the StringValueList preview.
  const StringValueListPreview({super.key});

  @override
  State<StringValueListPreview> createState() =>
      _StringValueListPreviewState();
}

class _StringValueListPreviewState extends State<StringValueListPreview> {
  List<String> _neutralEmpty = const [];
  List<String> _neutralFilled = const ['ok', 'healthy'];
  List<String> _warnEmpty = const [];
  List<String> _warnFilled = const ['degraded'];
  List<String> _criticalEmpty = const [];
  List<String> _criticalFilled = const ['down', 'error'];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6 max-w-lg',
      children: [
        WText('Neutral — empty'),
        StringValueList(
          value: _neutralEmpty,
          onChanged: (next) => setState(() => _neutralEmpty = next),
        ),
        WText('Neutral — populated'),
        StringValueList(
          value: _neutralFilled,
          onChanged: (next) => setState(() => _neutralFilled = next),
        ),
        WText('Warn — empty'),
        StringValueList(
          value: _warnEmpty,
          tone: StringValueListTone.warn,
          onChanged: (next) => setState(() => _warnEmpty = next),
        ),
        WText('Warn — populated'),
        StringValueList(
          value: _warnFilled,
          tone: StringValueListTone.warn,
          onChanged: (next) => setState(() => _warnFilled = next),
        ),
        WText('Critical — empty'),
        StringValueList(
          value: _criticalEmpty,
          tone: StringValueListTone.critical,
          onChanged: (next) => setState(() => _criticalEmpty = next),
        ),
        WText('Critical — populated'),
        StringValueList(
          value: _criticalFilled,
          tone: StringValueListTone.critical,
          onChanged: (next) => setState(() => _criticalFilled = next),
        ),
      ],
    );
  }
}
