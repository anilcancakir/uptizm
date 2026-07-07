import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/teams.dart';

/// **Profile settings sub-page (`/settings/profile`).**
///
/// A faithful Flutter port of the React `ProfileSettingsPage.tsx`: a single
/// [Card] holding an avatar tile (initials from [CurrentUser.initials]), a
/// mock "Change avatar" [Button], Name and Email [Input]s seeded from
/// [CurrentUser], and a Save action.
///
/// Save is a local-state mock: it shows a [Magic.success] toast and does not
/// persist anywhere (matches the app-wide mock convention for settings).
///
/// ### Example
/// ```dart
/// MagicRoute.page('/settings/profile', () => const ProfileSettingsView());
/// ```
@immutable
class ProfileSettingsView extends StatefulWidget {
  /// Creates the [ProfileSettingsView].
  const ProfileSettingsView({super.key});

  @override
  State<ProfileSettingsView> createState() => _ProfileSettingsViewState();
}

class _ProfileSettingsViewState extends State<ProfileSettingsView> {
  /// The name field, seeded from [CurrentUser.name].
  late final TextEditingController _nameController;

  /// The email field, seeded from [CurrentUser.email].
  late final TextEditingController _emailController;

  @override
  void initState() {
    super.initState();
    _nameController = TextEditingController(text: currentUser.name);
    _emailController = TextEditingController(text: currentUser.email);
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    super.dispose();
  }

  /// Saves the profile draft (mock: nothing persists).
  void _save() {
    Magic.success(
      trans('uptizm.settings.profile_title'),
      trans('uptizm.settings.profile_save_button'),
    );
  }

  @override
  Widget build(BuildContext context) {
    return MSSettingsScaffold(
      title: trans('uptizm.settings.profile_title'),
      subtitle: trans('uptizm.settings.profile_description'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: '/settings',
      children: [
        MSCard(
          child: WDiv(
            className: 'flex flex-col gap-5',
            children: [
              // 1. Avatar tile + mock "Change avatar" action.
              WDiv(
                className: 'flex flex-row items-center gap-4',
                children: [
                  WDiv(
                    className:
                        'grid place-items-center size-14 shrink-0 '
                        'rounded-full bg-primary-container text-fg',
                    child: WText(
                      currentUser.initials,
                      className: 'text-base font-semibold text-fg',
                    ),
                  ),
                  MSButton(
                    intent: ButtonIntent.secondary,
                    size: ButtonSize.sm,
                    onPressed: () {},
                    child: WText(
                      trans('uptizm.settings.profile_avatar_button'),
                    ),
                  ),
                ],
              ),

              // 2. Name field.
              WDiv(
                className: 'flex flex-col gap-1.5',
                children: [
                  WText(
                    trans('uptizm.settings.profile_name_label'),
                    className: 'text-sm font-medium text-fg',
                  ),
                  MSInput(controller: _nameController),
                ],
              ),

              // 3. Email field.
              WDiv(
                className: 'flex flex-col gap-1.5',
                children: [
                  WText(
                    trans('uptizm.settings.profile_email_label'),
                    className: 'text-sm font-medium text-fg',
                  ),
                  MSInput(controller: _emailController, type: InputType.email),
                ],
              ),

              // 4. Save action, right-aligned, auto-width (never w-full in a
              //    flex-row footer).
              WDiv(
                className: 'flex flex-row justify-end',
                child: MSButton(
                  onPressed: _save,
                  child: WText(trans('uptizm.settings.profile_save_button')),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
