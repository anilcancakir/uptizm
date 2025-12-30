import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/auth_controller.dart';

/// Login View
///
/// Simple login page with clean form design.
/// Validation is handled here, login logic is in AuthController.
class LoginView extends MagicStatefulView<AuthController> {
  const LoginView({super.key});

  @override
  State<LoginView> createState() => _LoginViewState();
}

class _LoginViewState
    extends MagicStatefulViewState<AuthController, LoginView> {
  // Centralized form data management
  late final form = MagicFormData({
    'email': '',
    'password': '',
    'remember_me': false,
  }, controller: controller);

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
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final isLoading = controller.isLoading;

        return WDiv(
          className:
              'max-w-[400px] p-6 bg-slate-900 border border-gray-700 rounded-lg',
          child: MagicForm(
            formData: form,
            child: WDiv(
              className: 'flex flex-col items-stretch',
              children: [
                WText(
                  trans('auth.login_title'),
                  className: 'text-2xl font-semibold text-white text-center',
                ),
                const SizedBox(height: 4),
                WText(
                  trans('auth.login_subtitle'),
                  className: 'text-sm text-gray-400 text-center',
                ),
                const SizedBox(height: 24),

                // Error Message
                if (errorMessage != null) ...[
                  WDiv(
                    className:
                        'p-2 bg-red-500 border border-red-500 rounded-lg text-center text-white mb-6',
                    child: WText(errorMessage),
                  ),
                  const SizedBox(height: 16),
                ],

                // Email Field
                WFormInput(
                  label: trans('attributes.email'),
                  controller: form['email'],
                  placeholder: trans('fields.email_placeholder'),
                  type: InputType.email,
                  validator: rules([Required(), Email()], field: 'email'),
                  className:
                      'w-full bg-slate-900 border border-gray-700 rounded-lg p-3 text-white focus:border-primary error:border-red-500',
                  placeholderClassName: 'text-gray-400',
                  labelClassName: 'text-sm font-medium text-gray-300 mb-1',
                ),
                const SizedBox(height: 16),

                // Password Field
                WFormInput(
                  label: trans('attributes.password'),
                  controller: form['password'],
                  placeholder: trans('fields.password_placeholder'),
                  type: InputType.password,
                  validator: rules([Required()], field: 'password'),
                  className:
                      'w-full bg-slate-900 border border-gray-700 rounded-lg p-3 text-white focus:border-primary error:border-red-500',
                  placeholderClassName: 'text-gray-400',
                  labelClassName: 'text-sm font-medium text-gray-300 mb-1',
                ),
                const SizedBox(height: 20),

                // Remember Me & Forgot Password
                WDiv(
                  className: 'flex items-center justify-between',
                  children: [
                    WFormCheckbox(
                      value: form.value<bool>('remember_me'),
                      onChanged: (value) => form.setValue('remember_me', value),
                      label: WText(
                        trans('auth.remember_me'),
                        className: 'text-gray-300 hover:text-gray-400 ml-1',
                      ),
                    ),
                    WAnchor(
                      onTap: () {
                        // TODO: Implement forgot password
                        Magic.success(
                          'Info',
                          'Forgot password not implemented yet',
                        );
                      },
                      child: WText(
                        trans('auth.forgot_password'),
                        className: 'text-sm text-primary hover:text-primary/80',
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 24),

                // Submit Button
                WButton(
                  isLoading: isLoading,
                  className:
                      'bg-primary text-white p-4 rounded-lg font-medium text-base hover:bg-primary/80',
                  onTap: () => _handleLogin(),
                  child: WText(
                    trans('auth.login_title'),
                    className: 'text-center',
                  ),
                ),
                const SizedBox(height: 24),

                WAnchor(
                  onTap: () => MagicRoute.to('/auth/register'),
                  child: WText(
                    trans('auth.register_link'),
                    className: 'text-center text-gray-400 hover:text-gray-300',
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
