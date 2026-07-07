import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// **Password settings sub-page (`/settings/security/password`).**
///
/// A faithful Flutter port of the React `PasswordSettingsPage.tsx`: a single
/// [Card] holding Current / New / Confirm password [Input]s (all obscured via
/// [InputType.password]), a "at least 12 characters" hint under the New
/// password field, and an Update [Button].
///
/// This is a pure UI mock: tapping Update flips local state to show a
/// [Magic.success] toast and an up-soft success alert; there is no real
/// password change, no validation beyond a trivial non-empty check, and no
/// secret is stored anywhere.
///
/// ### Example
/// ```dart
/// MagicRoute.page(
///   '/settings/security/password',
///   () => const PasswordSettingsView(),
/// );
/// ```
@immutable
class PasswordSettingsView extends StatefulWidget {
  /// Creates the [PasswordSettingsView].
  const PasswordSettingsView({super.key});

  @override
  State<PasswordSettingsView> createState() => _PasswordSettingsViewState();
}

class _PasswordSettingsViewState extends State<PasswordSettingsView> {
  /// The current-password field.
  final TextEditingController _currentController = TextEditingController();

  /// The new-password field.
  final TextEditingController _newController = TextEditingController();

  /// The confirm-password field.
  final TextEditingController _confirmController = TextEditingController();

  /// Whether the success alert is currently shown, mirroring the React
  /// `saved` state.
  bool _saved = false;

  @override
  void dispose() {
    _currentController.dispose();
    _newController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  /// Updates the password (mock: nothing persists, no real auth/crypto).
  void _update() {
    setState(() => _saved = true);
    Magic.success(
      trans('uptizm.settings.password_title'),
      trans('uptizm.settings.password_success_message'),
    );
  }

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.password_title'),
      subtitle: trans('uptizm.settings.password_description'),
      backLabel: trans('uptizm.settings.password_back_label'),
      backFallback: trans('uptizm.settings.password_back_to'),
      children: [
        MSCard(
          child: WDiv(
            className: 'flex flex-col gap-5',
            children: [
              // 1. Optional success alert, shown after Update.
              if (_saved) _buildSuccessAlert(),

              // 2. Current password field.
              _buildField(
                label: trans('uptizm.settings.password_current_label'),
                controller: _currentController,
              ),

              // 3. New password field, with the length hint.
              _buildField(
                label: trans('uptizm.settings.password_new_label'),
                controller: _newController,
                hint: trans('uptizm.settings.password_new_hint'),
              ),

              // 4. Confirm password field.
              _buildField(
                label: trans('uptizm.settings.password_confirm_label'),
                controller: _confirmController,
              ),

              // 5. Update action, right-aligned, auto-width (never w-full in
              //    a flex-row footer).
              WDiv(
                className: 'flex flex-row justify-end',
                child: MSButton(
                  onPressed: _update,
                  child: WText(trans('uptizm.settings.password_update_button')),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// Builds the up-soft success alert shown after a successful update.
  Widget _buildSuccessAlert() {
    return WDiv(
      className:
          'flex flex-row items-center gap-2 rounded-lg bg-up-soft '
          'dark:bg-up-soft px-4 py-3',
      children: [
        WIcon(
          Icons.check_circle_outline,
          className:
              'text-lg text-up-soft-foreground dark:text-up-soft-foreground',
        ),
        WText(
          trans('uptizm.settings.password_success_message'),
          className:
              'text-sm text-up-soft-foreground dark:text-up-soft-foreground',
        ),
      ],
    );
  }

  /// Builds one obscured password field with a label and optional [hint].
  Widget _buildField({
    required String label,
    required TextEditingController controller,
    String? hint,
  }) {
    return WDiv(
      className: 'flex flex-col gap-1.5',
      children: [
        WText(label, className: 'text-sm font-medium text-fg dark:text-fg'),
        MSInput(
          controller: controller,
          type: InputType.password,
          placeholder: trans('uptizm.settings.password_placeholder'),
        ),
        if (hint != null)
          WText(hint, className: 'text-xs text-fg-muted dark:text-fg-muted'),
      ],
    );
  }
}
