import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'region_picker.dart';

/// Static variant-matrix preview for [RegionPicker].
///
/// Mirrors the design lab `RegionPicker.preview.tsx`: two regions start
/// selected; tapping tiles updates the controlled selection. Renders all three
/// plan states, because the cap reads differently at one than above one: an
/// uncapped picker (no `maxSelected`), a cap of ONE that behaves as a radio
/// group (tapping another region swaps, nothing locks), and a cap of THREE at
/// its limit (the unselected tiles lock). No tile carries a plan-name suffix;
/// the count limit is stated once under the grid. One preview class per file;
/// discovered by `previews:refresh`.
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
  List<String> _singleSelected = const ['us-east'];
  List<String> _cappedSelected = const ['us-east', 'eu-west', 'eu-central'];

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
          'Cap of one (Free): a radio group, tapping another region swaps',
          className: 'text-sm font-medium text-fg',
        ),
        RegionPicker(
          regions: _regions,
          value: _singleSelected,
          onChanged: (next) => setState(() => _singleSelected = next),
          maxSelected: 1,
          capNotice:
              'Your Free plan checks from 1 region per monitor. Pro probes '
              'from more at once.',
        ),
        WText(
          'Cap of three, at its limit: the unselected tiles lock',
          className: 'text-sm font-medium text-fg',
        ),
        RegionPicker(
          regions: _regions,
          value: _cappedSelected,
          onChanged: (next) => setState(() => _cappedSelected = next),
          maxSelected: 3,
          capNotice:
              'Your Pro plan checks from 3 regions per monitor. Business '
              'probes from more at once.',
        ),
      ],
    );
  }
}
