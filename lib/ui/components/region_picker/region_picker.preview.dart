import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'region_picker.dart';

/// Static variant-matrix preview for [RegionPicker].
///
/// Mirrors the design lab `RegionPicker.preview.tsx`: two regions start
/// selected; tapping tiles updates the controlled selection. One preview class
/// per file; discovered by `previews:refresh`.
class RegionPickerPreview extends StatefulWidget {
  /// Creates the RegionPicker preview.
  const RegionPickerPreview({super.key});

  @override
  State<RegionPickerPreview> createState() => _RegionPickerPreviewState();
}

class _RegionPickerPreviewState extends State<RegionPickerPreview> {
  static const List<Region> _regions = [
    Region(label: 'US East', value: 'us-east', flag: '🇺🇸'),
    Region(label: 'US West', value: 'us-west', flag: '🇺🇸'),
    Region(label: 'EU West', value: 'eu-west', flag: '🇮🇪'),
    Region(label: 'EU Central', value: 'eu-central', flag: '🇩🇪'),
    Region(label: 'AP Southeast', value: 'ap-southeast', flag: '🇸🇬'),
    Region(label: 'AP Northeast', value: 'ap-northeast', flag: '🇯🇵'),
  ];

  List<String> _selected = const ['us-east', 'eu-west'];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6 max-w-xl',
      child: RegionPicker(
        regions: _regions,
        value: _selected,
        onChanged: (next) => setState(() => _selected = next),
      ),
    );
  }
}
