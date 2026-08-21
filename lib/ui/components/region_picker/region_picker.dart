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
/// `MonitorForm._buildRegionsField`). How the cap reads depends on its size,
/// because a cap of one is a different question from a cap of three:
///
/// At EVERY cap, deselecting the last remaining region is a no-op: the backend
/// requires `regions` `min:1`, so an empty selection has nowhere to go.
///
/// - **Cap of 1**: the grid behaves as a RADIO group. Tapping another region
///   swaps the selection rather than adding to it. Nothing is locked.
/// - **Cap above 1, reached**: every UNSELECTED tile renders locked, dimmed and
///   untappable. An already-selected tile is NEVER locked, even past the cap, so
///   a grandfathered monitor's stored regions stay visibly selected and
///   removable rather than silently dropped.
///
/// No tile carries a plan-name suffix, and that is the point. The check-interval
/// field suffixes a locked option with " · `<Plan>`" correctly, because a
/// 30-second interval genuinely IS gated. No region is gated: every plan can
/// probe from every region, and what a plan limits is HOW MANY at once. A
/// "EU West · Pro" tile therefore blamed the wrong thing and would push an
/// operator to upgrade for a reason that does not exist. The count limit is
/// stated once, in [capNotice], under the grid.
///
/// ### Example:
/// ```dart
/// RegionPicker(
///   regions: regions,
///   value: selected,
///   onChanged: (next) => setState(() => selected = next),
///   maxSelected: 1,
///   capNotice: 'Your Free plan checks from 1 region per monitor. Pro adds more.',
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

  /// One line under the grid explaining the count limit and which plan lifts
  /// it, already localized by the caller. Rendered only when [maxSelected] is
  /// set and smaller than the number of offered [regions], so an unlimited plan
  /// (or a cap nothing bumps into) shows nothing.
  final String? capNotice;

  /// Creates a [RegionPicker].
  const RegionPicker({
    super.key,
    required this.regions,
    required this.value,
    required this.onChanged,
    this.className,
    this.maxSelected,
    this.capNotice,
  });

  /// Whether the cap makes this a one-of-N choice rather than a capped
  /// multi-select.
  bool get _isSingleChoice => maxSelected == 1;

  @override
  Widget build(BuildContext context) {
    final slots = regionPickerRecipe(variants: const <String, String>{});
    // A cap of 1 swaps instead of locking, so it never reports "reached".
    final bool capReached =
        !_isSingleChoice && maxSelected != null && value.length >= maxSelected!;
    final bool showNotice =
        capNotice != null &&
        maxSelected != null &&
        maxSelected! < regions.length;

    final Widget grid = WDiv(
      className: className == null
          ? slots['root']
          : '${slots['root']} $className',
      children: [
        for (final region in regions) _tile(region, slots, capReached),
      ],
    );

    if (!showNotice) return grid;

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        grid,
        WText(capNotice!, className: 'text-xs text-fg-muted'),
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

    return WAnchor(
      isDisabled: locked,
      onTap: locked ? null : () => _onTileTap(region, selected),
      child: WDiv(
        className: optionClass,
        children: [
          // Display-only checkbox; the tile tap drives the toggle.
          //
          // `ExcludeSemantics` as well as `IgnorePointer`: the former blocks
          // pointers but leaves the semantics node in place, so assistive
          // technology was offered a second, nameless control inside a tile that
          // already announces its own region. The tick is decoration here, and
          // the tile carries the selected state.
          ExcludeSemantics(
            child: IgnorePointer(
              child: MSCheckbox(value: selected, onChanged: null),
            ),
          ),
          if (region.flag != null) WText(region.flag!, className: 'text-base'),
          // flex-1 + truncate so a long label (e.g. a monitor name or metric
          // label) shrinks and ellipsizes within the tile instead of forcing a
          // horizontal overflow in a narrow column.
          WText(
            region.label,
            className: locked
                ? 'flex-1 min-w-0 truncate text-fg-disabled'
                : 'flex-1 min-w-0 truncate text-fg',
          ),
        ],
      ),
    );
  }

  /// Reports the next selection for a tap on [region].
  ///
  /// Deselecting the last remaining region is a no-op at every cap: the backend
  /// rejects an empty set (`regions` is `required|array|min:1`), so the tap could
  /// only buy a round trip that comes back as an error.
  ///
  /// At a cap of one the tap additionally REPLACES the selection rather than
  /// adding to it, because the operator's intent at that cap is always "probe
  /// from this one instead".
  void _onTileTap(Region region, bool selected) {
    if (_isSingleChoice) {
      if (selected) return;
      onChanged(<String>[region.value]);
      return;
    }

    // Removing the LAST selection is a no-op above a cap of one too, for the
    // same reason the single-choice branch gives: the backend rejects an empty
    // set (`regions` is `required|array|min:1`), so clearing it can only produce
    // a round trip that comes back as an error. The floor used to be enforced
    // only at a cap of one, which let a multi-region plan empty the picker and
    // learn about it from the server.
    if (selected && value.length == 1) return;

    onChanged(
      selected
          ? value.where((entry) => entry != region.value).toList()
          : [...value, region.value],
    );
  }
}
