import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../components/app_card.dart';
import '../components/app_page_header.dart';

class ProfileSettingsView extends MagicStatefulView<StarterProfileController> {
  const ProfileSettingsView({super.key});

  @override
  State<ProfileSettingsView> createState() => _ProfileSettingsViewState();
}

class _ProfileSettingsViewState
    extends MagicStatefulViewState<StarterProfileController, ProfileSettingsView> {
  late final profileForm = MagicFormData({
    'name': '',
    'email': '',
  }, controller: controller);

  late final passwordForm = MagicFormData({
    'current_password': '',
    'password': '',
    'password_confirmation': '',
  }, controller: controller);

  bool _obscureCurrent = true;
  bool _obscureNew = true;
  bool _obscureConfirmation = true;

  @override
  void onInit() {
    final user = Auth.user();
    if (user != null) {
      profileForm.set('name', user.get<String>('name') ?? '');
      profileForm.set('email', user.get<String>('email') ?? '');
    }
    controller.clearErrors();
    controller.setEmpty();
  }

  @override
  void onClose() {
    profileForm.dispose();
    passwordForm.dispose();
  }

  Future<void> _submitProfile() async {
    if (!profileForm.validate()) return;
    await controller.doUpdateProfile(
      name: profileForm.get('name'),
      email: profileForm.get('email'),
    );
  }

  Future<void> _submitPassword() async {
    if (!passwordForm.validate()) return;
    final success = await controller.doUpdatePassword(
      currentPassword: passwordForm.get('current_password'),
      password: passwordForm.get('password'),
      passwordConfirmation: passwordForm.get('password_confirmation'),
    );
    if (success) {
      passwordForm.set('current_password', '');
      passwordForm.set('password', '');
      passwordForm.set('password_confirmation', '');
    }
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'overflow-y-auto flex flex-col gap-6',
      scrollPrimary: true,
      children: [
        AppPageHeader(
          leading: WButton(
            onTap: () => MagicRoute.to('/'),
            className:
                'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
            child: const WIcon(
              Icons.arrow_back,
              className: 'text-xl text-gray-700 dark:text-gray-300',
            ),
          ),
          title: trans('profile.settings'),
          subtitle: trans('profile.settings_subtitle'),
        ),
        WDiv(
          className: 'flex flex-col gap-6 p-4 lg:p-6 pt-0 lg:pt-0',
          children: [_buildProfileSection(), _buildPasswordSection()],
        ),
      ],
    );
  }

  Widget _buildProfileSection() {
    return AppCard(
      title: trans('profile.profile_information'),
      icon: Icons.person_outline,
      body: MagicForm(
        formData: profileForm,
        child: WDiv(
          className: 'flex flex-col gap-4',
          children: [
            WFormInput(
              controller: profileForm['name'],
              label: trans('attributes.name'),
              validator: rules([Required(), Min(2)], field: 'name'),
              prefix: WIcon(
                Icons.person_outline,
                className: 'text-primary text-xl',
              ),
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
              className:
                  'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500',
            ),
            WFormInput(
              controller: profileForm['email'],
              label: trans('attributes.email'),
              type: InputType.email,
              validator: rules([Required(), Email()], field: 'email'),
              prefix: WIcon(
                Icons.mail_outline,
                className: 'text-primary text-xl',
              ),
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
              className:
                  'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500',
            ),
            WDiv(
              className: 'flex justify-end',
              child: WButton(
                onTap: _submitProfile,
                isLoading: controller.isLoading,
                className:
                    'px-4 py-2 rounded-lg bg-primary hover:bg-green-600 dark:hover:bg-green-500 text-white text-sm font-medium',
                child: WText(trans('common.save')),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildPasswordSection() {
    return AppCard(
      title: trans('profile.update_password'),
      icon: Icons.lock_outline,
      body: MagicForm(
        formData: passwordForm,
        child: WDiv(
          className: 'flex flex-col gap-4',
          children: [
            WFormInput(
              controller: passwordForm['current_password'],
              label: trans('attributes.current_password'),
              type: _obscureCurrent ? InputType.password : InputType.text,
              validator: rules([Required()], field: 'current_password'),
              prefix: WIcon(
                Icons.lock_outline,
                className: 'text-primary text-xl',
              ),
              suffix: WAnchor(
                onTap: () => setState(() => _obscureCurrent = !_obscureCurrent),
                child: WIcon(
                  _obscureCurrent
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                  className: 'text-gray-400 text-xl',
                ),
              ),
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
              className:
                  'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500',
            ),
            WFormInput(
              controller: passwordForm['password'],
              label: trans('attributes.new_password'),
              type: _obscureNew ? InputType.password : InputType.text,
              validator: rules([Required(), Min(8)], field: 'password'),
              prefix: WIcon(
                Icons.lock_outline,
                className: 'text-primary text-xl',
              ),
              suffix: WAnchor(
                onTap: () => setState(() => _obscureNew = !_obscureNew),
                child: WIcon(
                  _obscureNew
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                  className: 'text-gray-400 text-xl',
                ),
              ),
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
              className:
                  'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500',
            ),
            WFormInput(
              controller: passwordForm['password_confirmation'],
              label: trans('attributes.password_confirmation'),
              type: _obscureConfirmation ? InputType.password : InputType.text,
              validator: rules([Required()], field: 'password_confirmation'),
              prefix: WIcon(
                Icons.lock_outline,
                className: 'text-primary text-xl',
              ),
              suffix: WAnchor(
                onTap: () => setState(
                  () => _obscureConfirmation = !_obscureConfirmation,
                ),
                child: WIcon(
                  _obscureConfirmation
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                  className: 'text-gray-400 text-xl',
                ),
              ),
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
              className:
                  'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500',
            ),
            WDiv(
              className: 'flex justify-end',
              child: WButton(
                onTap: _submitPassword,
                isLoading: controller.isLoading,
                className:
                    'px-4 py-2 rounded-lg bg-primary hover:bg-green-600 dark:hover:bg-green-500 text-white text-sm font-medium',
                child: WText(trans('profile.update_password')),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
