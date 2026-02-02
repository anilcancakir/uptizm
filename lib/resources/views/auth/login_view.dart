import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/auth_controller.dart';
import '../components/auth/auth_form_card.dart';
import '../components/auth/social_login_buttons.dart';

/// Login View
///
/// Modern login page with clean form design and light/dark mode support.
/// Based on the new Uptizm design system.
class LoginView extends MagicStatefulView<AuthController> {
  const LoginView({super.key});

  @override
  State<LoginView> createState() => _LoginViewState();
}

class _LoginViewState
    extends MagicStatefulViewState<AuthController, LoginView> {
  // Form data
  late final form = MagicFormData({
    'email': '',
    'password': '',
    'remember_me': false,
  }, controller: controller);

  // Password visibility toggle
  bool _obscurePassword = true;

  @override
  void onClose() => form.dispose();

  Future<void> _handleLogin() async {
    if (!form.validate()) return;

    await controller.doLogin(
      email: form.get('email'),
      password: form.get('password'),
      rememberMe: form.value<bool>('remember_me'),
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
      title: trans('auth.login_title'),
      subtitle: trans('auth.login_subtitle'),
      errorMessage: errorMessage,
      child: MagicForm(
          formData: form,
          child: WDiv(
            className: 'flex flex-col items-stretch',
            children: [
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
                validator: rules([Required()], field: 'password'),
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
              const SizedBox(height: 20),

              // Remember Me & Forgot Password
              WDiv(
                className: 'flex flex-row items-center justify-between',
                children: [
                  WFormCheckbox(
                    value: form.value<bool>('remember_me'),
                    onChanged: (value) => form.setValue('remember_me', value),
                    label: WText(
                      trans('auth.remember_me'),
                      className:
                          'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 ml-1',
                    ),
                  ),
                  WAnchor(
                    onTap: () => MagicRoute.to('/auth/forgot-password'),
                    child: WText(
                      trans('auth.forgot_password'),
                      className:
                          'text-sm text-primary hover:text-green-600 dark:hover:text-green-400',
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 24),

              // Submit Button
              WButton(
                isLoading: isLoading,
                onTap: _handleLogin,
                className: '''
                  w-full bg-primary hover:bg-primary-dark
                  text-white text-base font-bold
                  p-4 rounded-xl shadow-lg
                ''',
                child: WText(
                  trans('auth.login_title'),
                  className: 'text-center',
                ),
              ),
              const SizedBox(height: 16),

              // Social Login Buttons
              SocialLoginButtons(
                loadingProvider: controller.socialLoginProvider,
                mode: SocialAuthMode.signIn,
                onGoogle: controller.doGoogleLogin,
                onMicrosoft: controller.doMicrosoftLogin,
                onGithub: controller.doGithubLogin,
              ),
              const SizedBox(height: 24),

              // Footer Link
              WAnchor(
                onTap: () => MagicRoute.to('/auth/register'),
                child: WDiv(
                  className: 'flex flex-row justify-center gap-1',
                  children: [
                    WText(
                      trans('auth.dont_have_account'),
                      className: 'text-sm text-slate-500 dark:text-slate-400',
                    ),
                    WText(
                      trans('auth.sign_up'),
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
