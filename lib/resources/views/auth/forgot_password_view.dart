import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/auth_controller.dart';
import '../components/auth/auth_form_card.dart';

/// Forgot Password View
///
/// Simple form to request a password reset email.
class ForgotPasswordView extends MagicStatefulView<AuthController> {
  const ForgotPasswordView({super.key});

  @override
  State<ForgotPasswordView> createState() => _ForgotPasswordViewState();
}

class _ForgotPasswordViewState
    extends MagicStatefulViewState<AuthController, ForgotPasswordView> {
  late final form = MagicFormData({'email': ''}, controller: controller);

  @override
  void onClose() => form.dispose();

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;

    await controller.doForgotPassword(email: form.get('email'));
  }

  @override
  Widget build(BuildContext context) {
    return controller.renderState(
      (_) => _buildSuccess(),
      onEmpty: _buildForm(),
      onError: (msg) => _buildForm(errorMessage: msg),
    );
  }

  Widget _buildSuccess() {
    return AuthFormCard(
      title: trans('auth.reset_link_sent_title'),
      subtitle: trans('auth.reset_link_sent_message'),
      child: WDiv(
        className: 'flex flex-col items-center',
        children: [
          WDiv(
            className:
                'w-16 h-16 bg-primary/20 rounded-full flex items-center justify-center mb-4',
            child: WIcon(
              Icons.email_outlined,
              className: 'text-primary text-[32px]',
            ),
          ),
          const SizedBox(height: 8),
          WAnchor(
            onTap: () => MagicRoute.to('/auth/login'),
            child: WText(
              trans('auth.back_to_login'),
              className: 'text-primary hover:text-primary/80',
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildForm({String? errorMessage}) {
    final isLoading = controller.isLoading;

    return AuthFormCard(
      title: trans('auth.forgot_password_title'),
      subtitle: trans('auth.forgot_password_subtitle'),
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
                  className:
                      'w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-3 text-slate-900 dark:text-white focus:border-primary error:border-red-500',
                  placeholderClassName: 'text-slate-400 dark:text-slate-500',
                  labelClassName: 'text-sm font-medium text-slate-900 dark:text-slate-200 mb-1',
                ),
                const SizedBox(height: 24),

                // Submit Button
                WButton(
                  isLoading: isLoading,
                  className:
                      'bg-primary text-white p-4 rounded-xl font-bold text-base hover:bg-primary-dark shadow-lg',
                  onTap: () => _handleSubmit(),
                  child: WText(
                    trans('auth.send_reset_link'),
                    className: 'text-center',
                  ),
                ),
                const SizedBox(height: 24),

                WAnchor(
                  onTap: () => MagicRoute.to('/auth/login'),
                  child: WText(
                    trans('auth.back_to_login'),
                    className: 'text-center text-gray-400 hover:text-gray-300',
                  ),
                ),
              ],
            ),
          ),
    );
  }
}
