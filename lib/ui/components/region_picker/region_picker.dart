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
/// [maxSelected] caps how many regions may be selected at once, mirroring the
/// team's billing entitlement (the caller resolves the effective cap, see
/// `MonitorForm._buildRegionsField`). Once [value] reaches the cap, every
/// UNSELECTED tile renders locked: dimmed, untappable, and its label suffixed
/// with a " · " + [lockedPlanName] plan name, the same "Available on `<Plan>`"
/// treatment the check-interval field already uses. An already-selected tile is
/// NEVER locked, even past the cap, so a grandfathered monitor's stored regions
/// stay visibly selected and removable rather than silently dropped.
///
/// ### Example:
/// ```dart
/// RegionPicker(
///   regions: regions,
///   value: selected,
///   onChanged: (next) => setState(() => selected = next),
///   maxSelected: 1,
///   lockedPlanName: 'Pro',
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

  /// Maximum number of regions selectable at once, or `null` for unlimited.
  ///
  /// Enforced only against adding a new region: once [value] reaches this cap,
  /// unselected tiles lock. A tile already present in [value] beyond the cap
  /// (a grandfathered monitor's stored regions) is left selected and stays
  /// removable, mirroring the backend's delta-only gate.
  final int? maxSelected;

  /// Plan name suffix shown on a locked tile's label, e.g. `'Pro'` renders
  /// `'US West · Pro'`. Ignored while [maxSelected] is null or unreached.
  final String? lockedPlanName;

  /// Creates a [RegionPicker].
  const RegionPicker({
    super.key,
    required this.regions,
    required this.value,
    required this.onChanged,
    this.className,
    this.maxSelected,
    this.lockedPlanName,
  });

  @override
  Widget build(BuildContext context) {
    final slots = regionPickerRecipe(variants: const <String, String>{});
    final bool capReached =
        maxSelected != null && value.length >= maxSelected!;
    return WDiv(
      className: className == null
          ? slots['root']
          : '${slots['root']} $className',
      children: [
        for (final region in regions) _tile(region, slots, capReached),
      ],
    );
  }

  Widget _tile(Region region, Map<String, String> slots, bool capReached) {
    final selected = value.contains(region.value);
    final bool locked = !selected && capReached;
    final optionClass = selected
        ? '${slots['option']} ${slots['optionSelected']}'
        : locked
        ? '${slots['option']} ${slots['optionLocked']}'
        : slots['option'];

    final String label = locked && lockedPlanName != null
        ? '${region.label} · $lockedPlanName'
        : region.label;

    return WAnchor(
      isDisabled: locked,
      onTap: locked
          ? null
          : () => onChanged(
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
          WText(
            label,
            className: locked
                ? 'flex-1 min-w-0 truncate text-fg-disabled'
                : 'flex-1 min-w-0 truncate text-fg',
          ),
        ],
      ),
    );
  }
}
