import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// Appearance settings sub-page (`/settings/appearance`): a real Light/Dark
/// theme picker.
///
/// Mirrors the React `AppearanceSettingsPage.tsx`: two radio cards (Light /
/// Dark), each a glyph tile plus label plus description plus a selected ring
/// and dot. The selected card reflects the CURRENT effective brightness read
/// live from [WindTheme] via `WindTheme.of(context).brightness`.
///
/// Tapping a card is a genuine app-wide theme change, not local state: it
/// rebuilds the whole widget tree in the chosen brightness. It also clears
/// `syncWithSystem` so a later platform-brightness change cannot silently
/// override the user's manual pick. The sync-clearing path is
/// `setTheme(data.copyWith(brightness: target, syncWithSystem: false))`; the
/// plain `updateTheme(brightness:)` helper does NOT clear the flag and would
/// leave the pick vulnerable to `didChangePlatformBrightness`.
///
/// Persistence is best-effort: the change is session-live only. Cross-restart
/// persistence is intentionally out of scope for this view.
@immutable
class AppearanceSettingsView extends StatelessWidget {
  /// Creates the [AppearanceSettingsView].
  const AppearanceSettingsView({super.key});

  @override
  Widget build(BuildContext context) {
    // 1. Read the theme controller through the InheritedNotifier so this view
    //    rebuilds when the controller notifies after a theme change. Reading
    //    `.brightness` here (not a cached snapshot) keeps the selected card in
    //    sync with the live theme.
    final WindThemeController theme = WindTheme.of(context);
    final Brightness current = theme.brightness;

    return SettingsScaffold(
      title: trans('uptizm.settings.appearance_title'),
      subtitle: trans('uptizm.settings.appearance_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        WDiv(
          className: 'flex flex-col gap-3 rounded-lg border '
              'border-color-border bg-surface-container p-4 '
              'dark:border-color-border dark:bg-surface-container',
          children: [
            _buildThemeCard(
              context: context,
              controller: theme,
              target: Brightness.light,
              icon: Icons.light_mode_outlined,
              label: trans('uptizm.settings.appearance_light_label'),
              description: trans('uptizm.settings.appearance_light_desc'),
              selected: current == Brightness.light,
            ),
            _buildThemeCard(
              context: context,
              controller: theme,
              target: Brightness.dark,
              icon: Icons.dark_mode_outlined,
              label: trans('uptizm.settings.appearance_dark_label'),
              description: trans('uptizm.settings.appearance_dark_desc'),
              selected: current == Brightness.dark,
            ),
          ],
        ),
      ],
    );
  }

  /// Builds one selectable theme card.
  ///
  /// The card is a [WAnchor] (the app's clickable-row primitive: it sets the
  /// pointer cursor and drives the `hover:` state), mirroring the React
  /// `<button>` row. Every color className carries its `dark:` counterpart.
  Widget _buildThemeCard({
    required BuildContext context,
    required WindThemeController controller,
    required Brightness target,
    required IconData icon,
    required String label,
    required String description,
    required bool selected,
  }) {
    // 1. Selected state tints the card and swaps the border to the brand ring;
    //    unselected keeps the neutral surface with a hover affordance.
    final String cardClassName = selected
        ? 'flex flex-row items-center gap-3 rounded-lg border '
            'border-primary bg-primary-container p-4 transition-colors '
            'dark:border-primary dark:bg-primary-container'
        : 'flex flex-row items-center gap-3 rounded-lg border '
            'border-color-border bg-surface p-4 transition-colors '
            'hover:bg-surface-container '
            'dark:border-color-border dark:bg-surface '
            'dark:hover:bg-surface-container';

    return WAnchor(
      onTap: () => _select(controller, target),
      child: WDiv(
        className: cardClassName,
        children: [
          // 2. Glyph tile: brand tint when selected, muted otherwise.
          _buildGlyphTile(icon: icon, selected: selected),

          // 3. Label plus description, filling the remaining row width.
          Expanded(
            child: WDiv(
              className: 'flex flex-col',
              children: [
                WText(
                  label,
                  className: 'text-sm font-medium text-fg dark:text-fg',
                ),
                WText(
                  description,
                  className: 'text-xs text-fg-muted dark:text-fg-muted',
                ),
              ],
            ),
          ),

          // 4. Radio indicator: ring plus centered dot when selected.
          _buildRadioIndicator(selected: selected),
        ],
      ),
    );
  }

  /// Builds the leading glyph tile.
  Widget _buildGlyphTile({
    required IconData icon,
    required bool selected,
  }) {
    final String tileClassName = selected
        ? 'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg '
            'bg-primary-container text-primary '
            'dark:bg-primary-container dark:text-primary'
        : 'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg '
            'bg-surface-container-high text-fg-muted '
            'dark:bg-surface-container-high dark:text-fg-muted';

    return WDiv(
      className: tileClassName,
      child: WIcon(icon, className: 'text-lg'),
    );
  }

  /// Builds the trailing radio indicator (ring plus dot).
  Widget _buildRadioIndicator({required bool selected}) {
    final String ringClassName = selected
        ? 'flex h-5 w-5 shrink-0 items-center justify-center rounded-full '
            'border-2 border-primary dark:border-primary'
        : 'flex h-5 w-5 shrink-0 items-center justify-center rounded-full '
            'border-2 border-color-border dark:border-color-border';

    return WDiv(
      className: ringClassName,
      child: selected
          ? WDiv(
              className: 'h-2.5 w-2.5 rounded-full bg-primary dark:bg-primary',
            )
          : const SizedBox.shrink(),
    );
  }

  /// Applies the chosen [target] brightness app-wide and pins it against
  /// system-brightness changes.
  ///
  /// Uses `setTheme(data.copyWith(brightness:, syncWithSystem: false))` so the
  /// pick survives a later `didChangePlatformBrightness`. `setTheme` no-ops
  /// when the target already matches, so a tap on the active card is inert.
  void _select(WindThemeController controller, Brightness target) {
    controller.setTheme(
      controller.data.copyWith(
        brightness: target,
        syncWithSystem: false,
      ),
    );
  }
}
