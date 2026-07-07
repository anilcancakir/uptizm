import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/settings.dart';
import '../../../app/mocks/status.dart';
import '../../../ui/components/status_badge/status_badge.dart';

/// **Sessions settings sub-page (`/settings/security/sessions`).**
///
/// A faithful Flutter port of the React `SessionsSettingsPage.tsx`: a single
/// [SettingsSection] of [deviceSessions] rows (device icon, device name,
/// location/recency subtitle), the current row carrying a [StatusBadge]
/// "This device" badge and every other row a ghost "Sign out" [Button], plus
/// a footer "Sign out all other sessions" [Button].
///
/// Both sign-out paths (single device, all others) open a
/// [MagicStarterConfirmDialog] first; on confirm they mutate the local
/// [_sessions] list via [setState] and surface a [Magic.success] toast. This
/// mirrors `StatusPageSubscribersView._confirmRemove` exactly, including the
/// `if (!mounted) return;` guard after the awaited dialog.
///
/// This is a mock screen: nothing persists past the local widget state.
///
/// ### Example
/// ```dart
/// MagicRoute.page(
///   '/settings/security/sessions',
///   () => const SessionsSettingsView(),
/// );
/// ```
@immutable
class SessionsSettingsView extends StatefulWidget {
  /// Creates the [SessionsSettingsView].
  const SessionsSettingsView({super.key});

  @override
  State<SessionsSettingsView> createState() => _SessionsSettingsViewState();
}

class _SessionsSettingsViewState extends State<SessionsSettingsView> {
  /// The mutable working copy of the account's active sessions.
  ///
  /// Seeded once in [initState] from [deviceSessions]; the fixture list is
  /// never mutated in place. Sign-out mutates this list via [setState].
  late List<DeviceSession> _sessions;

  @override
  void initState() {
    super.initState();
    _sessions = deviceSessions.toList();
  }

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.sessions_title'),
      subtitle: trans('uptizm.settings.sessions_description'),
      backLabel: trans('uptizm.settings.sessions_back_label'),
      backFallback: trans('uptizm.settings.sessions_back_to'),
      children: [
        MSSettingsSection(
          children: [
            for (final DeviceSession session in _sessions)
              _buildSessionRow(session),
          ],
        ),
        WDiv(
          className: 'flex flex-row justify-end',
          child: MSButton(
            intent: ButtonIntent.secondary,
            onPressed: _sessions.where((s) => !s.current).isEmpty
                ? null
                : _confirmSignOutAllOthers,
            child: WText(trans('uptizm.settings.sessions_signout_all_button')),
          ),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Session row
  // ---------------------------------------------------------------------------

  /// Builds one device row: icon, device name, location/recency subtitle, and
  /// a trailing "This device" [StatusBadge] or ghost "Sign out" [Button].
  Widget _buildSessionRow(DeviceSession session) {
    return MSSettingsRow(
      icon: Icons.laptop_outlined,
      title: session.device,
      subtitle: '${session.location} · ${session.lastActive}',
      trailing: session.current
          ? StatusBadge(
              StatusKey.up,
              label: trans('uptizm.settings.sessions_current_badge'),
            )
          : MSButton(
              intent: ButtonIntent.ghost,
              size: ButtonSize.sm,
              onPressed: () => _confirmSignOut(session),
              child: WText(trans('uptizm.settings.sessions_signout_button')),
            ),
    );
  }

  // ---------------------------------------------------------------------------
  // Sign-out confirmation
  // ---------------------------------------------------------------------------

  /// Opens the single-device sign-out [MagicStarterConfirmDialog]; on
  /// confirm, removes [session] from the local list and surfaces a success
  /// toast. Mirrors `StatusPageSubscribersView._confirmRemove` exactly.
  Future<void> _confirmSignOut(DeviceSession session) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.settings.sessions_confirm_title'),
      description: trans('uptizm.settings.sessions_confirm_description'),
      confirmLabel: trans('uptizm.settings.sessions_signout_button'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors StatusPageSubscribersView's
    // precedent).
    if (!mounted) return;

    setState(() => _sessions.remove(session));
    Magic.success(
      trans('uptizm.settings.sessions_toast_title'),
      trans('uptizm.settings.sessions_toast_description'),
    );
  }

  /// Opens the sign-out-all-others [MagicStarterConfirmDialog]; on confirm,
  /// removes every non-current session from the local list and surfaces a
  /// success toast. Mirrors `StatusPageSubscribersView._confirmRemove`
  /// exactly.
  Future<void> _confirmSignOutAllOthers() async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.settings.sessions_confirm_title'),
      description: trans('uptizm.settings.sessions_confirm_description'),
      confirmLabel: trans('uptizm.settings.sessions_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors StatusPageSubscribersView's
    // precedent).
    if (!mounted) return;

    setState(() => _sessions.removeWhere((s) => !s.current));
    Magic.success(
      trans('uptizm.settings.sessions_toast_title'),
      trans('uptizm.settings.sessions_toast_description'),
    );
  }
}
