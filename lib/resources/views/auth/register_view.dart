import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/auth_controller.dart';
import '../components/auth/auth_form_card.dart';
import '../components/auth/social_login_buttons.dart';

/// Register View
///
/// Modern registration page with clean form design and light/dark mode support.
/// Based on the new Uptizm design system.
class RegisterView extends MagicStatefulView<AuthController> {
  const RegisterView({super.key});

  @override
  State<RegisterView> createState() => _RegisterViewState();
}

class _RegisterViewState
    extends MagicStatefulViewState<AuthController, RegisterView> {
  // Form data
  late final form = MagicFormData({
    'name': '',
    'email': '',
    'password': '',
    'password_confirmation': '',
  }, controller: controller);

  // Password visibility toggles
  bool _obscurePassword = true;
  bool _obscureConfirmPassword = true;

  @override
  void onClose() => form.dispose();

  Future<void> _handleRegister() async {
    if (!form.validate()) return;

    await controller.doRegister(
      name: form.get('name'),
      email: form.get('email'),
      password: form.get('password'),
      passwordConfirmation: form.get('password_confirmation'),
    );
  }

  @override
  Widget build(BuildContext context) {
    return controller.renderState(
      (_) => _buildForm(),
      onEmpty: _buildForm(),
      onError: (msg) => _buildForm(errorMessage: msg),
    );
  }

  Widget _buildForm({String? errorMessage}) {
    final isLoading = controller.isLoading;

    return AuthFormCard(
      title: trans('auth.create_account'),
      subtitle: trans('auth.register_tagline'),
      errorMessage: errorMessage,
      child: MagicForm(
          formData: form,
          child: WDiv(
            className: 'flex flex-col items-stretch',
            children: [
              // Full Name Field
              WFormInput(
                label: trans('attributes.name'),
                controller: form['name'],
                placeholder: trans('fields.full_name_placeholder'),
                validator: rules([Required(), Min(2)], field: 'name'),
                prefix: WIcon(
                  Icons.person_outline,
                  className: 'text-primary text-xl',
                ),
                className: '''
                  w-full bg-white dark:bg-slate-800
                  border border-slate-200 dark:border-slate-700
                  rounded-xl p-3
                  text-slate-900 dark:text-white
                  focus:border-primary error:border-red-500
                ''',
                placeholderClassName: 'text-slate-400 dark:text-slate-500',
                labelClassName:
                    'text-sm font-medium text-slate-900 dark:text-slate-200 mb-1',
              ),
              const SizedBox(height: 16),

              // Email Field
              WFormInput(
                label: trans('attributes.email'),
                controller: form['email'],
                placeholder: trans('fields.email_placeholder'),
                type: InputType.email,
                validator: rules([Required(), Email()], field: 'email'),
                prefix: WIcon(
                  Icons.mail_outline,
                  className: 'text-primary text-xl',
                ),
                className: '''
                  w-full bg-white dark:bg-slate-800
                  border border-slate-200 dark:border-slate-700
                  rounded-xl p-3
                  text-slate-900 dark:text-white
                  focus:border-primary error:border-red-500
                ''',
                placeholderClassName: 'text-slate-400 dark:text-slate-500',
                labelClassName:
                    'text-sm font-medium text-slate-900 dark:text-slate-200 mb-1',
              ),
              const SizedBox(height: 16),

              // Password Field
              WFormInput(
                label: trans('attributes.password'),
                controller: form['password'],
                placeholder: trans('fields.password_placeholder'),
                type: _obscurePassword ? InputType.password : InputType.text,
                validator: rules([Required(), Min(8)], field: 'password'),
                prefix: WIcon(
                  Icons.lock_outline,
                  className: 'text-primary text-xl',
                ),
                suffix: WAnchor(
                  onTap: () =>
                      setState(() => _obscurePassword = !_obscurePassword),
                  child: WIcon(
                    _obscurePassword ? Icons.visibility : Icons.visibility_off,
                    className: 'text-slate-400 text-xl',
                  ),
                ),
                className: '''
                  w-full bg-white dark:bg-slate-800
                  border border-slate-200 dark:border-slate-700
                  rounded-xl p-3
                  text-slate-900 dark:text-white
                  focus:border-primary error:border-red-500
                ''',
                placeholderClassName: 'text-slate-400 dark:text-slate-500',
                labelClassName:
                    'text-sm font-medium text-slate-900 dark:text-slate-200 mb-1',
              ),
              const SizedBox(height: 16),

              // Confirm Password Field
              WFormInput(
                label: trans('attributes.password_confirmation'),
                controller: form['password_confirmation'],
                placeholder: trans('fields.password_confirmation_placeholder'),
                type: _obscureConfirmPassword
                    ? InputType.password
                    : InputType.text,
                validator: rules([
                  Required(),
                  Same('password', valueGetter: () => form['password'].text),
                ], field: 'password_confirmation'),
                prefix: WIcon(
                  Icons.lock_reset,
                  className: 'text-primary text-xl',
                ),
                suffix: WAnchor(
                  onTap: () => setState(
                    () => _obscureConfirmPassword = !_obscureConfirmPassword,
                  ),
                  child: WIcon(
                    _obscureConfirmPassword
                        ? Icons.visibility
                        : Icons.visibility_off,
                    className: 'text-slate-400 text-xl',
                  ),
                ),
                className: '''
                  w-full bg-white dark:bg-slate-800
                  border border-slate-200 dark:border-slate-700
                  rounded-xl p-3
                  text-slate-900 dark:text-white
                  focus:border-primary error:border-red-500
                ''',
                placeholderClassName: 'text-slate-400 dark:text-slate-500',
                labelClassName:
                    'text-sm font-medium text-slate-900 dark:text-slate-200 mb-1',
              ),
              const SizedBox(height: 24),

              // Submit Button
              WButton(
                isLoading: isLoading,
                onTap: _handleRegister,
                className: '''
                  w-full bg-primary hover:bg-primary-dark
                  text-white text-base font-bold
                  p-4 rounded-xl shadow-lg
                ''',
                child: WText(
                  trans('auth.create_account'),
                  className: 'text-center',
                ),
              ),
              const SizedBox(height: 16),

              // Social Login Buttons
              SocialLoginButtons(
                loadingProvider: controller.socialLoginProvider,
                mode: SocialAuthMode.signUp,
                onGoogle: controller.doGoogleLogin,
                onMicrosoft: controller.doMicrosoftLogin,
                onGithub: controller.doGithubLogin,
              ),
              const SizedBox(height: 24),

              // Footer Link
              WAnchor(
                onTap: () => MagicRoute.to('/auth/login'),
                child: WDiv(
                  className: 'flex flex-row justify-center gap-1',
                  children: [
                    WText(
                      trans('auth.already_have_account'),
                      className: 'text-sm text-slate-500 dark:text-slate-400',
                    ),
                    WText(
                      trans('auth.log_in'),
                      className: 'text-sm font-semibold text-primary',
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
    );
  }
}
