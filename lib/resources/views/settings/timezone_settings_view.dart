import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/settings.dart';

/// **Time zone settings sub-page (`/settings/timezone`).**
///
/// A faithful Flutter port of the React `TimezoneSettingsPage.tsx`'s combobox
/// UX, expressed as an inline searchable list (no popover/portal): a "Set
/// automatically" [SettingsRow] with a [Switch] toggle, followed by a search
/// [Input] filtering [timezonesFromApi] via [searchTimezones], and a
/// scrollable list of tappable zone rows (city + "region · offset"
/// subtitle), the selected zone checkmarked.
///
/// When auto is on, the selector list is disabled/dimmed and the effective
/// zone follows [_autoZone] (a fixed device-detected stand-in, since a mock
/// screen has no real device timezone to read). Turning auto off re-enables
/// the selector over the user's last manual pick.
///
/// The zone list itself is API-framed mock data (`timezonesFromApi`), never a
/// client-side `Intl` enumeration.
///
/// Selection is local-state only: toggling auto or tapping a zone updates
/// local state; nothing persists (matches the app-wide mock convention for
/// settings).
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/timezone', () => const TimezoneSettingsView());
/// ```
@immutable
class TimezoneSettingsView extends StatefulWidget {
  /// Creates the [TimezoneSettingsView].
  const TimezoneSettingsView({super.key});

  @override
  State<TimezoneSettingsView> createState() => _TimezoneSettingsViewState();
}

class _TimezoneSettingsViewState extends State<TimezoneSettingsView> {
  /// The device-detected zone stood in for a mock screen with no real device
  /// timezone to read. Used as the effective zone whenever [_auto] is on.
  static const String _autoZone = 'Europe/Istanbul';

  /// Whether the zone follows the device automatically.
  ///
  /// Defaults to `true`, mirroring the React source's
  /// `localStorage.getItem(AUTO_KEY) ?? "true"` fallback.
  bool _auto = true;

  /// The user's manually selected zone, used when [_auto] is off. Defaults to
  /// [_autoZone], mirroring the React source's `zoneId` initial state.
  String _selectedZone = _autoZone;

  /// The current search query filtering the zone list.
  String _query = '';

  /// The zone value currently in effect: [_autoZone] when [_auto] is on,
  /// otherwise [_selectedZone].
  String get _effectiveZone => _auto ? _autoZone : _selectedZone;

  /// Zones matching the current [_query] via [searchTimezones].
  List<AppTimezone> get _visibleZones => searchTimezones(_query);

  /// Toggles [_auto]. Mirrors the React source's `toggleAuto`.
  void _toggleAuto(bool value) {
    setState(() => _auto = value);
  }

  /// Selects [zone] as the manual pick and shows a confirmation toast (mock:
  /// nothing persists). Mirrors the React source's `select`.
  void _select(AppTimezone zone) {
    setState(() => _selectedZone = zone.value);
    Magic.success(
      trans('uptizm.settings.timezone_title'),
      '${zone.city} (${zone.offset})',
    );
  }

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.timezone_title'),
      subtitle: trans('uptizm.settings.timezone_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        MSSettingsSection(
          children: [
            MSSettingsRow(
              icon: Icons.access_time_outlined,
              title: trans('uptizm.settings.timezone_auto_label'),
              subtitle: _auto
                  ? trans('uptizm.settings.timezone_auto_on')
                  : trans('uptizm.settings.timezone_auto_off'),
              trailing: MSSwitch(
                value: _auto,
                onChanged: _toggleAuto,
                semanticLabel: trans('uptizm.settings.timezone_auto_label'),
              ),
            ),
          ],
        ),
        _buildZoneSelector(),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Zone selector
  // ---------------------------------------------------------------------------

  /// Builds the search [Input] plus the scrollable list of zone rows. Dimmed
  /// and non-interactive while [_auto] is on.
  Widget _buildZoneSelector() {
    final List<AppTimezone> visible = _visibleZones;

    return WDiv(
      className: _auto
          ? 'flex flex-col gap-3 opacity-50'
          : 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.settings.timezone_field_label'),
          className: 'text-sm font-medium text-fg',
        ),
        MSInput(
          value: _query,
          onChanged: _auto ? null : (value) => setState(() => _query = value),
          enabled: !_auto,
          placeholder: trans('uptizm.settings.timezone_search_placeholder'),
        ),
        MSSettingsSection(
          footer: trans('uptizm.settings.timezone_field_hint'),
          children: visible.isEmpty
              ? [_buildNoMatchRow()]
              : [for (final AppTimezone zone in visible) _buildZoneRow(zone)],
        ),
      ],
    );
  }

  /// Builds the "no zones match" placeholder row.
  Widget _buildNoMatchRow() {
    return MSSettingsRow(title: trans('uptizm.settings.timezone_no_match'));
  }

  /// Builds one tappable zone row: city title, "region · offset" subtitle,
  /// and a checkmark [trailing] on the currently effective zone. Inert while
  /// [_auto] is on.
  Widget _buildZoneRow(AppTimezone zone) {
    final bool selected = zone.value == _effectiveZone;

    return MSSettingsRow(
      title: zone.city,
      subtitle: '${zone.region} · ${zone.offset}',
      onTap: _auto ? null : () => _select(zone),
      trailing: selected
          ? const WIcon(Icons.check, className: 'text-primary')
          : null,
    );
  }
}
