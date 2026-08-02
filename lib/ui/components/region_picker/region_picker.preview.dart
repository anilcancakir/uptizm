import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'region_picker.dart';

/// Static variant-matrix preview for [RegionPicker].
///
/// Mirrors the design lab `RegionPicker.preview.tsx`: two regions start
/// selected; tapping tiles updates the controlled selection. Renders both
/// states the plan-gate step added: an uncapped picker (no `maxSelected`) and
/// a capped one (a Free-style one-region allowance) where the unselected
/// tiles lock with the "Available on `<Plan>`" nudge. One preview class per
/// file; discovered by `previews:refresh`.
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

  List<String> _uncappedSelected = const ['us-east', 'eu-west'];
  List<String> _cappedSelected = const ['us-east'];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6 max-w-xl',
      children: [
        WText(
          'Uncapped (Pro/Business/Enterprise: no region limit)',
          className: 'text-sm font-medium text-fg',
        ),
        RegionPicker(
          regions: _regions,
          value: _uncappedSelected,
          onChanged: (next) => setState(() => _uncappedSelected = next),
        ),
        WText(
          'Capped (Free: one region), the rest locked',
          className: 'text-sm font-medium text-fg',
        ),
        RegionPicker(
          regions: _regions,
          value: _cappedSelected,
          onChanged: (next) => setState(() => _cappedSelected = next),
          maxSelected: 1,
          lockedPlanName: 'Pro',
        ),
      ],
    );
  }
}
