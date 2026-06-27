import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart'
    show DropdownMenu, DropdownMenuItem;

/// **The Mobile Top Bar**
///
/// A sticky, safe-area-aware glass header shown only below `lg` (the desktop
/// [Sidebar] takes over above it). Ported from the design lab's `MobileTopBar`:
///
/// - **Left:** a workspace (team) switcher opening a dropdown.
/// - **Right:** an account menu (reusing the magic_starter [DropdownMenu]) that
///   carries Settings + Sign out, since Settings is intentionally absent from
///   the bottom tab bar.
/// - **Glass surface:** a [BackdropFilter] over a high-opacity `bg-surface/80`
///   fallback, composed directly because Wind has no backdrop token
///   (PORTING.md §4).
/// - **Safe area:** the notch / status-bar inset is added above the bar via
///   [MediaQuery] padding instead of CSS `env()` (PORTING.md §5).
@immutable
class MobileTopBar extends StatelessWidget {
  /// Creates a [MobileTopBar].
  const MobileTopBar({super.key});

  @override
  Widget build(BuildContext context) {
    // 1. Reserve the status-bar / notch inset above the bar (no CSS env()).
    final topInset = MediaQuery.of(context).viewPadding.top;

    // 2. Glass effect: blur whatever scrolls beneath the sticky bar, over a
    //    high-opacity surface fallback for platforms without real blur.
    return ClipRect(
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
        child: WDiv(
          className: '''
            bg-surface/80 border-b border-color-border
          ''',
          children: [
            SizedBox(height: topInset),
            WDiv(
              className: '''
                h-14 px-4 flex flex-row items-center justify-between gap-3
              ''',
              children: [
                // The switcher flexes so its truncating label can shrink,
                // leaving the fixed-size account button its full footprint.
                Flexible(child: _buildWorkspaceSwitcher()),
                _buildAccountMenu(),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildWorkspaceSwitcher() {
    // The workspace switcher mirrors the desktop team dropdown; in the mock it
    // surfaces the app name as the active workspace label.
    return DropdownMenu(
      alignment: PopoverAlignment.bottomLeft,
      items: [
        DropdownMenuItem(
          label: trans('uptizm.nav.settings'),
          onTap: () => MagicRoute.to('/settings'),
        ),
      ],
      child: WDiv(
        className: '''
          h-11 pr-1 flex flex-row items-center gap-2
          rounded-md hover:bg-surface-container
        ''',
        children: [
          WDiv(
            className: '''
              w-7 h-7 rounded-md bg-primary
              flex items-center justify-center shrink-0
            ''',
            child: WText('U', className: 'text-xs font-bold text-on-primary'),
          ),
          Expanded(
            child: WText(
              trans('app.name'),
              className: 'text-sm font-semibold text-fg truncate',
            ),
          ),
          WIcon(
            Icons.expand_more,
            className: 'text-[18px] text-fg-muted shrink-0',
          ),
        ],
      ),
    );
  }

  Widget _buildAccountMenu() {
    // The account menu carries Settings (which is NOT a bottom tab) and the
    // sign-out action, reusing the shared DropdownMenu.
    return DropdownMenu(
      alignment: PopoverAlignment.bottomRight,
      items: [
        DropdownMenuItem(
          label: trans('uptizm.nav.settings'),
          onTap: () => MagicRoute.to('/settings'),
        ),
        DropdownMenuItem(label: trans('auth.logout'), onTap: () {}),
      ],
      child: WDiv(
        className: '''
          w-11 h-11 rounded-full bg-surface-container
          flex items-center justify-center shrink-0
          hover:bg-surface-container-high
        ''',
        child: WIcon(Icons.person_outline, className: 'text-[20px] text-fg'),
      ),
    );
  }
}
