import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'region_picker.recipe.dart';

/// A selectable monitoring region.
@immutable
class Region {
  /// Human-readable region name, e.g. "US East".
  final String label;

  /// Stable region identifier, e.g. "us-east".
  final String value;

  /// Optional flag emoji for the region (e.g. "🇺🇸").
  final String? flag;

  /// Creates a [Region].
  const Region({required this.label, required this.value, this.flag});
}

/// **Controlled multi-select grid of monitoring regions.**
///
/// Each tile is a clickable region (flag + label + checkbox). Tapping a tile
/// toggles its [Region.value] in [value] and reports the next selection through
/// [onChanged]. The whole tile is the hit target; the checkbox is display-only
/// (driven by the tile tap) to avoid double toggles. Ported from the design lab
/// `RegionPicker`.
///
/// ### Example:
/// ```dart
/// RegionPicker(
///   regions: regions,
///   value: selected,
///   onChanged: (next) => setState(() => selected = next),
/// )
/// ```
@immutable
class RegionPicker extends StatelessWidget {
  /// The full set of regions to offer.
  final List<Region> regions;

  /// Controlled list of selected region values.
  final List<String> value;

  /// Called with the next selection whenever a tile toggles.
  final ValueChanged<List<String>> onChanged;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates a [RegionPicker].
  const RegionPicker({
    super.key,
    required this.regions,
    required this.value,
    required this.onChanged,
    this.className,
  });

  @override
  Widget build(BuildContext context) {
    final slots = regionPickerRecipe(variants: const <String, String>{});
    return WDiv(
      className: className == null
          ? slots['root']
          : '${slots['root']} $className',
      children: [for (final region in regions) _tile(region, slots)],
    );
  }

  Widget _tile(Region region, Map<String, String> slots) {
    final selected = value.contains(region.value);
    final optionClass = selected
        ? '${slots['option']} ${slots['optionSelected']}'
        : slots['option'];

    return WAnchor(
      onTap: () => onChanged(
        selected
            ? value.where((entry) => entry != region.value).toList()
            : [...value, region.value],
      ),
      child: WDiv(
        className: optionClass,
        children: [
          // Display-only checkbox; the tile tap drives the toggle.
          IgnorePointer(child: MSCheckbox(value: selected, onChanged: null)),
          if (region.flag != null) WText(region.flag!, className: 'text-base'),
          // flex-1 + truncate so a long label (e.g. a monitor name or metric
          // label) shrinks and ellipsizes within the tile instead of forcing a
          // horizontal overflow in a narrow column.
          WText(region.label, className: 'flex-1 min-w-0 truncate text-fg'),
        ],
      ),
    );
  }
}
