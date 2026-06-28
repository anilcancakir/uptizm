import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'date_range_picker.dart';

/// Static variant-matrix preview for [DateRangePicker].
///
/// Mirrors the design lab `DateRangePicker.preview.tsx`: a controlled picker
/// whose trigger reflects the active preset; tapping opens the preset menu.
/// One preview class per file; discovered by `previews:refresh`.
class DateRangePickerPreview extends StatefulWidget {
  /// Creates the DateRangePicker preview.
  const DateRangePickerPreview({super.key});

  @override
  State<DateRangePickerPreview> createState() => _DateRangePickerPreviewState();
}

class _DateRangePickerPreviewState extends State<DateRangePickerPreview> {
  String _range = '7d';

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      child: DateRangePicker(
        value: _range,
        onChanged: (next) => setState(() => _range = next),
      ),
    );
  }
}
