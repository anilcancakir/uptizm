import 'package:magic/magic.dart';
import 'package:flutter/material.dart';

import '../../../app/controllers/profile_controller.dart';
import '../../../app/helpers/locale_list.dart';
import '../../../app/models/user.dart';
import '../components/app_card.dart';
import '../components/photo_picker.dart';
import '../components/searchable_timezone_select.dart';

/// Profile Settings View
///
/// Page for editing user profile settings.
/// Uses AppLayout (via route) and real API integration.
class ProfileSettingsView extends MagicStatefulView<ProfileController> {
  const ProfileSettingsView({super.key});

  @override
  State<ProfileSettingsView> createState() => _ProfileSettingsViewState();
}

class _ProfileSettingsViewState
    extends MagicStatefulViewState<ProfileController, ProfileSettingsView> {
  late final MagicFormData profileForm;
  late final MagicFormData passwordForm;
  final ValueNotifier<MagicFile?> photo = ValueNotifier(null);

  @override
  void onInit() {
    super.onInit();
    // Initialize profile form with current user data
    final user = User.current;
    profileForm = MagicFormData({
      'name': user.name ?? '',
      'phone': user.phone ?? '',
      'timezone': user.timezone ?? '',
      'language': user.language ?? 'en',
    }, controller: controller);

    // Initialize password form (empty)
    passwordForm = MagicFormData({
      'current_password': '',
      'password': '',
      'password_confirmation': '',
    }, controller: controller);
  }

  @override
  void onClose() {
    profileForm.dispose();
    passwordForm.dispose();
    photo.dispose();
  }

  Future<void> _pickPhoto() async {
    final file = await Pick.image(maxWidth: 512, maxHeight: 512);
    if (file != null) {
      photo.value = file;
    }
  }

  Future<void> _uploadPhoto() async {
    if (photo.value != null) {
      final success = await controller.doUploadPhoto(photo.value!);
      if (success) {
        photo.value = null;
      }
    }
  }

  Future<void> _removePhoto() async {
    final confirmed = await Magic.confirm(
      title: trans('profile_settings.remove_photo'),
      message: trans('profile_settings.remove_photo'),
      confirmText: trans('common.remove'),
      isDangerous: true,
    );
    if (confirmed == true) {
      await controller.doRemovePhoto();
    }
  }

  Future<void> _handleSaveProfile() async {
    if (!profileForm.validate()) return;
    final success = await controller.doUpdateProfile(
      name: profileForm.get('name'),
      phone: profileForm.get('phone'),
      timezone: profileForm.get('timezone'),
      language: profileForm.get('language'),
    );

    // Apply locale change immediately if successful
    if (success) {
      final newLanguage = profileForm.get('language');
      if (newLanguage.isNotEmpty) {
        Lang.setLocale(Locale(newLanguage));
      }
    }
  }

  Future<void> _handleUpdatePassword() async {
    if (!passwordForm.validate()) return;
    final success = await controller.doUpdatePassword(
      currentPassword: passwordForm.get('current_password'),
      password: passwordForm.get('password'),
      passwordConfirmation: passwordForm.get('password_confirmation'),
    );
    if (success) {
      // Clear password fields after successful update
      passwordForm['current_password'].clear();
      passwordForm['password'].clear();
      passwordForm['password_confirmation'].clear();
    }
  }

  Future<void> _handleDeleteAccount() async {
    final confirmed = await Magic.confirm(
      title: trans('profile_settings.delete_account'),
      message: trans('profile_settings.delete_account_confirm'),
      confirmText: trans('profile_settings.delete_account'),
      isDangerous: true,
    );

    if (confirmed == true) {
      // Show password prompt dialog
      final passwordController = TextEditingController();
      final deleteConfirmed = await Magic.dialog<bool>(
        WDiv(
          className: 'flex flex-col gap-4',
          children: [
            WText(
              trans('profile_settings.delete_account'),
              className: 'text-lg font-semibold text-gray-900 dark:text-white',
            ),
            WFormInput(
              label: trans('attributes.password'),
              controller: passwordController,
              type: InputType.password,
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-1',
              className: '''
                w-full bg-gray-50 dark:bg-gray-800
                border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2
                text-gray-900 dark:text-white
              ''',
            ),
            Builder(
              builder: (context) => WDiv(
                className: 'flex flex-row justify-end gap-2 mt-2 w-full',
                children: [
                  WButton(
                    onTap: () => Navigator.of(context).pop(false),
                    className: '''
                      px-3 py-2 rounded-lg
                      bg-gray-200 dark:bg-gray-700
                      text-gray-700 dark:text-gray-200
                      text-sm font-medium
                    ''',
                    child: WText(trans('common.cancel')),
                  ),
                  WButton(
                    onTap: () => Navigator.of(context).pop(true),
                    className: '''
                      px-3 py-2 rounded-lg
                      bg-red-600 text-white
                      text-sm font-medium
                    ''',
                    child: WText(trans('profile_settings.delete_account')),
                  ),
                ],
              ),
            ),
          ],
        ),
      );

      if (deleteConfirmed == true && passwordController.text.isNotEmpty) {
        await controller.doDeleteAccount(password: passwordController.text);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return controller.renderState(
      (_) => _buildForm(),
      onEmpty: _buildForm(),
      onLoading: _buildForm(isLoading: true),
      onError: (msg) => _buildForm(errorMessage: msg),
    );
  }

  Widget _buildForm({bool isLoading = false, String? errorMessage}) {
    final user = User.current;

    return WDiv(
      className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
      scrollPrimary: true,
      children: [
          // Error Message
          if (errorMessage != null)
            WDiv(
              className: '''
                p-3 mb-2
                bg-red-100 dark:bg-red-900
                border border-red-300 dark:border-red-700
                rounded-lg
              ''',
              child: WText(
                errorMessage,
                className: 'text-red-700 dark:text-red-200',
              ),
            ),

          // Personal Information Card
          MagicForm(
            formData: profileForm,
            child: AppCard(
              title: trans('profile_settings.personal_info'),
              body: WDiv(
                className: 'flex flex-col gap-6',
                children: [
                  // Avatar Section
                  PhotoPicker(
                    photo: photo,
                    currentPhotoUrl: user.profilePhotoUrl,
                    label: trans('profile_settings.profile_photo'),
                    description: trans('profile_settings.profile_photo_desc'),
                    changeButtonText: trans('profile_settings.change_photo'),
                    onPick: _pickPhoto,
                    onUpload: _uploadPhoto,
                    onRemove: _removePhoto,
                    removeButtonText: trans('profile_settings.remove_photo'),
                    isLoading: isLoading,
                  ),

                  // Name Input
                  WFormInput(
                    label: trans('profile_settings.name'),
                    hint: trans('profile_settings.name_placeholder'),
                    controller: profileForm['name'],
                    labelClassName: '''
                      text-gray-900 dark:text-gray-200
                      mb-2 text-sm font-medium
                    ''',
                    hintClassName: '''
                      text-gray-500 dark:text-gray-400
                      text-xs font-medium mt-2
                    ''',
                    className: '''
                      w-full bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      rounded-lg
                      border border-gray-200 dark:border-gray-700
                      px-3 py-4
                      text-sm
                      focus:border-primary
                      focus:ring-2 focus:ring-primary/20
                      duration-150
                    ''',
                    validator: rules([
                      Required(),
                      Min(2),
                      Max(255),
                    ], field: 'name'),
                  ),

                  // Email Input (Read-only)
                  WDiv(
                    className: 'flex flex-col gap-1',
                    children: [
                      WText(
                        trans('profile_settings.email'),
                        className:
                            'text-gray-900 dark:text-gray-200 mb-2 text-sm font-medium',
                      ),
                      WDiv(
                        className: '''
                          w-full bg-gray-50 dark:bg-gray-900/50
                          text-gray-500 dark:text-gray-400
                          rounded-lg
                          border border-gray-200 dark:border-gray-700
                          px-3 py-4
                          text-sm
                        ''',
                        child: WText(user.email ?? ''),
                      ),
                      WText(
                        trans('profile_settings.email_desc'),
                        className:
                            'text-gray-500 dark:text-gray-400 text-xs mt-2',
                      ),
                    ],
                  ),

                  // Phone Input
                  WFormInput(
                    label: trans('profile_settings.phone'),
                    hint: trans('profile_settings.phone_placeholder'),
                    controller: profileForm['phone'],
                    labelClassName: '''
                      text-gray-900 dark:text-gray-200
                      mb-2 text-sm font-medium
                    ''',
                    hintClassName: '''
                      text-gray-500 dark:text-gray-400
                      text-xs font-medium mt-2
                    ''',
                    className: '''
                      w-full bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      rounded-lg
                      border border-gray-200 dark:border-gray-700
                      px-3 py-4
                      text-sm
                      focus:border-primary
                      focus:ring-2 focus:ring-primary/20
                      duration-150
                    ''',
                  ),

                  // Language Select
                  WFormSelect<String>(
                    label: trans('profile_settings.language'),
                    value: profileForm.get('language'),
                    labelClassName: '''
                      text-gray-900 dark:text-gray-200
                      mb-2 text-sm font-medium
                    ''',
                    className: '''
                      w-full bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      rounded-lg
                      border border-gray-200 dark:border-gray-700
                      px-3 py-4
                      text-sm
                      focus:border-primary
                      focus:ring-2 focus:ring-primary/20
                      duration-150
                    ''',
                    menuClassName: '''
                      bg-white dark:bg-gray-800
                      border border-gray-200 dark:border-gray-700
                    ''',
                    options: localeOptions,
                    onChange: (value) =>
                        profileForm.set('language', value ?? 'en'),
                  ),

                  // Timezone Select
                  SearchableTimezoneSelect(
                    label: trans('profile_settings.timezone'),
                    value: profileForm.get('timezone'),
                    placeholder: trans('profile_settings.timezone_placeholder'),
                    onChanged: (value) =>
                        profileForm.set('timezone', value ?? ''),
                  ),
                ],
              ),
              footer: WDiv(
                className: 'flex flex-row justify-end gap-3',
                children: [
                  WButton(
                    onTap: () => MagicRoute.back(),
                    className: '''
                      px-4 py-2 rounded-lg
                      bg-gray-200 dark:bg-gray-700
                      text-gray-700 dark:text-gray-200
                      hover:bg-gray-300 dark:hover:bg-gray-600
                      text-sm font-medium
                    ''',
                    child: WText(trans('common.cancel')),
                  ),
                  WButton(
                    isLoading: isLoading,
                    onTap: _handleSaveProfile,
                    className: '''
                      px-4 py-2 rounded-lg
                      bg-primary hover:bg-green-600
                      text-white
                      text-sm font-medium
                    ''',
                    child: WText(trans('common.save')),
                  ),
                ],
              ),
            ),
          ),

          // Change Password Card
          MagicForm(
            formData: passwordForm,
            child: AppCard(
              title: trans('profile_settings.change_password'),
              body: WDiv(
                className: 'flex flex-col gap-6',
                children: [
                  // Current Password
                  WFormInput(
                    label: trans('profile_settings.current_password'),
                    hint: trans(
                      'profile_settings.current_password_placeholder',
                    ),
                    controller: passwordForm['current_password'],
                    type: InputType.password,
                    labelClassName: '''
                      text-gray-900 dark:text-gray-200
                      mb-2 text-sm font-medium
                    ''',
                    hintClassName: '''
                      text-gray-500 dark:text-gray-400
                      text-xs font-medium mt-2
                    ''',
                    className: '''
                      w-full bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      rounded-lg
                      border border-gray-200 dark:border-gray-700
                      px-3 py-4
                      text-sm
                      focus:border-primary
                      focus:ring-2 focus:ring-primary/20
                      duration-150
                    ''',
                    validator: rules([Required()], field: 'current_password'),
                  ),

                  // New Password
                  WFormInput(
                    label: trans('profile_settings.new_password'),
                    hint: trans('profile_settings.new_password_placeholder'),
                    controller: passwordForm['password'],
                    type: InputType.password,
                    labelClassName: '''
                      text-gray-900 dark:text-gray-200
                      mb-2 text-sm font-medium
                    ''',
                    hintClassName: '''
                      text-gray-500 dark:text-gray-400
                      text-xs font-medium mt-2
                    ''',
                    className: '''
                      w-full bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      rounded-lg
                      border border-gray-200 dark:border-gray-700
                      px-3 py-4
                      text-sm
                      focus:border-primary
                      focus:ring-2 focus:ring-primary/20
                      duration-150
                    ''',
                    validator: rules([Required(), Min(8)], field: 'password'),
                  ),

                  // Confirm Password
                  WFormInput(
                    label: trans('profile_settings.confirm_password'),
                    hint: trans(
                      'profile_settings.confirm_password_placeholder',
                    ),
                    controller: passwordForm['password_confirmation'],
                    type: InputType.password,
                    labelClassName: '''
                      text-gray-900 dark:text-gray-200
                      mb-2 text-sm font-medium
                    ''',
                    hintClassName: '''
                      text-gray-500 dark:text-gray-400
                      text-xs font-medium mt-2
                    ''',
                    className: '''
                      w-full bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      rounded-lg
                      border border-gray-200 dark:border-gray-700
                      px-3 py-4
                      text-sm
                      focus:border-primary
                      focus:ring-2 focus:ring-primary/20
                      duration-150
                    ''',
                    validator: rules([
                      Required(),
                      Same('password'),
                    ], field: 'password_confirmation'),
                  ),
                ],
              ),
              footer: WDiv(
                className: 'flex flex-row justify-end',
                children: [
                  WButton(
                    isLoading: isLoading,
                    onTap: _handleUpdatePassword,
                    className: '''
                      px-4 py-2 rounded-lg
                      bg-primary hover:bg-green-600
                      text-white
                      text-sm font-medium
                    ''',
                    child: WText(trans('profile_settings.update_password')),
                  ),
                ],
              ),
            ),
          ),

          // Danger Zone
          AppCard(
            title: trans('profile_settings.danger_zone'),
            titleClassName: 'text-red-600 dark:text-red-400',
            body: WDiv(
              className: 'flex flex-col gap-4',
              children: [
                WText(
                  trans('profile_settings.delete_account_desc'),
                  className: 'text-sm text-gray-600 dark:text-gray-400',
                ),
                WDiv(
                  className: 'flex flex-row justify-end',
                  children: [
                    WButton(
                      onTap: _handleDeleteAccount,
                      className: '''
                        px-4 py-2 rounded-lg
                        bg-red-50 dark:bg-red-900/20
                        text-red-600 dark:text-red-400
                        hover:bg-red-100 dark:hover:bg-red-900/30
                        border border-red-200 dark:border-red-900/50
                        text-sm font-medium
                      ''',
                      child: WText(trans('profile_settings.delete_account')),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
    );
  }
}
