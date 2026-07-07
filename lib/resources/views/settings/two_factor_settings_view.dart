import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// **Two-factor authentication settings sub-page (`/settings/security/2fa`).**
///
/// A faithful Flutter port of the React `TwoFactorSettingsPage.tsx`: a
/// [SettingsRow] "Authenticator app" with a [Switch], and — when enabled — a
/// conditional recovery-codes [Card] rendering 6 mock codes in a
/// `grid grid-cols-2 sm:grid-cols-3 gap-2` mono grid plus Copy and Regenerate
/// [Button]s.
///
/// This is a pure UI mock: toggling the switch and tapping Copy/Regenerate
/// only flip local state and show a [Magic.success] toast; there is no real
/// enrollment, no secret, and nothing is persisted.
///
/// ### Example
/// ```dart
/// MagicRoute.page(
///   '/settings/security/2fa',
///   () => const TwoFactorSettingsView(),
/// );
/// ```
@immutable
class TwoFactorSettingsView extends StatefulWidget {
  /// Creates the [TwoFactorSettingsView].
  const TwoFactorSettingsView({super.key});

  @override
  State<TwoFactorSettingsView> createState() => _TwoFactorSettingsViewState();
}

class _TwoFactorSettingsViewState extends State<TwoFactorSettingsView> {
  /// The 6 mock recovery codes, matching the React fixture exactly.
  static const List<String> _recoveryCodes = [
    '4f9a-2c1d',
    '8b3e-77aa',
    '1d20-9f4c',
    'a6e1-3b88',
    'c0f5-12de',
    '9k2m-6t7p',
  ];

  /// Whether the authenticator app is enabled, mirroring the React `enabled`
  /// state.
  bool _enabled = false;

  /// Toggles authenticator-app enrollment (mock: no real enrollment).
  void _toggle(bool value) {
    setState(() => _enabled = value);
  }

  /// Copies the recovery codes (mock: no clipboard write, toast only).
  void _copyCodes() {
    Magic.success(
      trans('uptizm.settings.twofa_recovery_heading'),
      trans('uptizm.settings.twofa_recovery_copy_button'),
    );
  }

  /// Regenerates the recovery codes (mock: the fixture list never changes).
  void _regenerate() {
    Magic.success(
      trans('uptizm.settings.twofa_recovery_heading'),
      trans('uptizm.settings.twofa_recovery_regenerate_button'),
    );
  }

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.twofa_title'),
      subtitle: trans('uptizm.settings.twofa_description'),
      backLabel: trans('uptizm.settings.twofa_back_label'),
      backFallback: trans('uptizm.settings.twofa_back_to'),
      children: [
        MSCard(
          child: MSSettingsRow(
            title: trans('uptizm.settings.twofa_authenticator_title'),
            subtitle: trans('uptizm.settings.twofa_authenticator_subtitle'),
            trailing: MSSwitch(value: _enabled, onChanged: _toggle),
          ),
        ),
        if (_enabled) _buildRecoveryCodesCard(),
      ],
    );
  }

  /// Builds the conditional recovery-codes card shown when 2FA is enabled.
  Widget _buildRecoveryCodesCard() {
    return MSCard(
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          // 1. Heading + description.
          WDiv(
            className: 'flex flex-col gap-1',
            children: [
              WText(
                trans('uptizm.settings.twofa_recovery_heading'),
                className: 'text-sm font-semibold text-fg dark:text-fg',
              ),
              WText(
                trans('uptizm.settings.twofa_recovery_description'),
                className: 'text-sm text-fg-muted dark:text-fg-muted',
              ),
            ],
          ),

          // 2. The 6 mock codes in a responsive mono grid.
          WDiv(
            className:
                'grid grid-cols-2 sm:grid-cols-3 gap-2 rounded-lg '
                'border border-color-border dark:border-color-border '
                'bg-surface dark:bg-surface p-3',
            children: [
              for (final code in _recoveryCodes)
                WText(
                  code,
                  className:
                      'font-mono text-sm tabular-nums text-fg dark:text-fg',
                ),
            ],
          ),

          // 3. Copy + Regenerate actions.
          WDiv(
            className: 'flex flex-row gap-2',
            children: [
              MSButton(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                onPressed: _copyCodes,
                child: WText(
                  trans('uptizm.settings.twofa_recovery_copy_button'),
                ),
              ),
              MSButton(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                onPressed: _regenerate,
                child: WText(
                  trans('uptizm.settings.twofa_recovery_regenerate_button'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
