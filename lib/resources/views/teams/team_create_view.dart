import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/team_controller.dart';
import '../components/app_card.dart';

/// Create Team View
///
/// Form to create a new team with SettingsCard component.
class TeamCreateView extends MagicStatefulView<TeamController> {
  const TeamCreateView({super.key});

  @override
  State<TeamCreateView> createState() => _TeamCreateViewState();
}

class _TeamCreateViewState
    extends MagicStatefulViewState<TeamController, TeamCreateView> {
  late final form = MagicFormData({'name': ''}, controller: controller);

  @override
  void onClose() => form.dispose();

  Future<void> _handleCreate() async {
    if (!form.validate()) return;
    await controller.doCreate(name: form.get('name'));
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

    return MagicForm(
      formData: form,
      child: WDiv(
        className:
            'overflow-y-auto flex flex-col items-stretch w-full p-4 lg:p-6',
        scrollPrimary: true,
        children: [
          // Header
          WDiv(
            className: 'mb-6',
            children: [
              WText(
                trans('teams.create_team'),
                className: '''
                    text-gray-900 dark:text-white 
                    text-2xl font-bold
                  ''',
              ),
              const WSpacer(className: 'h-1'),
              WText(
                trans('teams.create_team_description'),
                className: 'text-gray-600 dark:text-gray-400 text-sm',
              ),
            ],
          ),

          // Error Message
          if (errorMessage != null)
            WDiv(
              className: '''
                  p-3 mb-4
                  bg-red-100 dark:bg-red-900 
                  border border-red-300 dark:border-red-700 
                  rounded-lg
                ''',
              child: WText(
                errorMessage,
                className: 'text-red-700 dark:text-red-200',
              ),
            ),

          // Form Card
          AppCard(
            title: trans('teams.team_details'),
            body: WFormInput(
              label: trans('attributes.team_name'),
              controller: form['name'],
              placeholder: trans('fields.team_name_placeholder'),
              validator: rules([Required(), Min(2), Max(255)], field: 'name'),
              prefix: Icon(
                Icons.edit_outlined,
                size: 20,
                color: wColor(context, 'primary'),
              ),
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
              placeholderClassName: 'text-gray-400 dark:text-gray-500',
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
                  onTap: _handleCreate,
                  className: '''
                      px-4 py-2 rounded-lg 
                      bg-primary hover:bg-green-600 
                      text-white 
                      text-sm font-medium
                    ''',
                  child: WText(trans('teams.create')),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
