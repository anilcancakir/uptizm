import 'package:magic/magic.dart';
import 'package:flutter/material.dart';

import '../../../app/controllers/team_controller.dart';
import '../../../app/models/team.dart';
import '../components/app_card.dart';
import '../components/photo_picker.dart';

/// Team Settings View
///
/// Page for editing current team settings.
/// Uses AppLayout (via route) and real API integration.
class TeamSettingsView extends MagicStatefulView<TeamController> {
  const TeamSettingsView({super.key});

  @override
  State<TeamSettingsView> createState() => _TeamSettingsViewState();
}

class _TeamSettingsViewState
    extends MagicStatefulViewState<TeamController, TeamSettingsView> {
  late final MagicFormData form;
  final ValueNotifier<MagicFile?> photo = ValueNotifier(null);

  @override
  void onInit() {
    super.onInit();
    // Initialize form with current team data
    final team = controller.currentTeam;
    form = MagicFormData({'name': team?.name ?? ''}, controller: controller);
  }

  @override
  void onClose() {
    form.dispose();
    photo.dispose();
  }

  Future<void> _pickPhoto() async {
    final file = await Pick.image(maxWidth: 512, maxHeight: 512);
    if (file != null) {
      photo.value = file;
    }
  }

  Future<void> _handleSave() async {
    if (!form.validate()) return;
    final success = await controller.doUpdate(
      name: form.get('name'),
      photo: photo.value,
    );
    if (success) {
      photo.value = null;
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
    final team = controller.currentTeam;

    return MagicForm(
      formData: form,
      child: WDiv(
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

            // Team Settings Card
            AppCard(
              title: trans('team_settings.general_info'),
              body: WDiv(
                className: 'flex flex-col gap-6',
                children: [
                  // Avatar Section
                  PhotoPicker(
                    photo: photo,
                    currentPhotoUrl: team?.profilePhotoUrl,
                    label: trans('team_settings.team_photo'),
                    description: trans('team_settings.team_photo_desc'),
                    changeButtonText: trans('team_settings.change_photo'),
                    onPick: _pickPhoto,
                  ),

                  WFormInput(
                    label: trans('team_settings.name'),
                    hint: trans('team_settings.name_placeholder'),
                    controller: form['name'],
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
                    onTap: _handleSave,
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

            // Leave Team Section (non-owners only)
            if (team != null && !team.isOwner)
              AppCard(
                title: trans('teams.leave_team'),
                titleClassName: 'text-orange-600 dark:text-orange-400',
                body: WDiv(
                  className: 'flex flex-col gap-4',
                  children: [
                    WText(
                      trans('teams.leave_team_desc'),
                      className: 'text-sm text-gray-600 dark:text-gray-400',
                    ),
                    WDiv(
                      className: 'flex flex-row justify-end',
                      children: [
                        WButton(
                          onTap: () async {
                            final confirmed = await Magic.confirm(
                              title: trans('teams.leave_team'),
                              message: trans('teams.leave_team_confirm'),
                              confirmText: trans('common.leave'),
                              isDangerous: true,
                            );
                            if (confirmed == true) {
                              await controller.leaveTeam(team);
                            }
                          },
                          className: '''
                            px-4 py-2 rounded-lg
                            bg-orange-50 dark:bg-orange-900/20
                            text-orange-600 dark:text-orange-400
                            hover:bg-orange-100 dark:hover:bg-orange-900/30
                            border border-orange-200 dark:border-orange-900/50
                            text-sm font-medium
                          ''',
                          child: WText(trans('teams.leave_team')),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

            // Danger Zone
            _buildDangerZone(team),
          ],
      ),
    );
  }

  Widget _buildDangerZone(Team? team) {
    return MagicCan(
      ability: 'delete-team',
      arguments: team,
      child: AppCard(
        title: trans('teams.danger_zone'),
        titleClassName: 'text-red-600 dark:text-red-400',
        body: WDiv(
          className: 'flex flex-col gap-4',
          children: [
            WText(
              trans('teams.delete_team_desc'),
              className: 'text-sm text-gray-600 dark:text-gray-400',
            ),
            WDiv(
              className: 'flex flex-row justify-end',
              children: [
                WButton(
                  onTap: () async {
                    final confirmed = await Magic.confirm(
                      title: trans('teams.delete_team'),
                      message: trans('teams.delete_team_confirm'),
                      confirmText: trans('common.delete'),
                      isDangerous: true,
                    );

                    if (confirmed == true && team != null) {
                      await controller.deleteTeam(team);
                    }
                  },
                  className: '''
                    px-4 py-2 rounded-lg 
                    bg-red-50 dark:bg-red-900/20 
                    text-red-600 dark:text-red-400 
                    hover:bg-red-100 dark:hover:bg-red-900/30
                    border border-red-200 dark:border-red-900/50
                    text-sm font-medium
                  ''',
                  child: WText(trans('teams.delete_team')),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
