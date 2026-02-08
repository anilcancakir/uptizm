import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/incident_controller.dart';
import '../../../app/enums/incident_impact.dart';
import '../../../app/enums/incident_status.dart';

class IncidentEditView extends MagicStatefulView<IncidentController> {
  const IncidentEditView({super.key});

  @override
  State<IncidentEditView> createState() => _IncidentEditViewState();
}

class _IncidentEditViewState
    extends MagicStatefulViewState<IncidentController, IncidentEditView> {
  late final MagicFormData form;
  IncidentImpact _selectedImpact = IncidentImpact.partialOutage;
  IncidentStatus _selectedStatus = IncidentStatus.investigating;
  bool _initialized = false;

  @override
  void onInit() {
    super.onInit();
    controller.clearErrors();

    form = MagicFormData({'title': ''}, controller: controller);
  }

  void _initializeFormFromIncident() {
    final incident = controller.selectedIncidentNotifier.value;
    if (incident != null && !_initialized) {
      form.set('title', incident.title ?? '');
      _selectedImpact = incident.impact ?? IncidentImpact.partialOutage;
      _selectedStatus = incident.status ?? IncidentStatus.investigating;
      _initialized = true;
    }
  }

  @override
  void onClose() {
    form.dispose();
  }

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;

    final incident = controller.selectedIncidentNotifier.value;
    if (incident?.id == null) return;

    await controller.update(
      incident!.id!,
      title: form.get('title'),
      impact: _selectedImpact,
      status: _selectedStatus,
    );
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: controller.selectedIncidentNotifier,
      builder: (context, incident, _) {
        if (incident == null) {
          return WDiv(
            className: 'flex-1 flex items-center justify-center',
            child: WDiv(
              className: 'w-full flex flex-col items-center gap-4',
              children: [
                const CircularProgressIndicator(),
                WText(
                  trans('common.loading'),
                  className: 'text-gray-500 dark:text-gray-400',
                ),
              ],
            ),
          );
        }

        _initializeFormFromIncident();

        return controller.renderState(
          (_) => _buildForm(),
          onEmpty: _buildForm(),
          onLoading: _buildForm(isLoading: true),
          onError: (msg) => _buildForm(errorMessage: msg),
        );
      },
    );
  }

  Widget _buildForm({bool isLoading = false, String? errorMessage}) {
    final incident = controller.selectedIncidentNotifier.value;

    return MagicForm(
      formData: form,
      child: WDiv(
        className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
        scrollPrimary: true,
        children: [
          // Page Header
          WDiv(
            className: 'flex flex-row items-center gap-3 mb-2',
            children: [
              WButton(
                onTap: () {
                  final incident = controller.selectedIncidentNotifier.value;
                  if (incident?.id != null) {
                    MagicRoute.to('/incidents/${incident!.id}');
                  } else {
                    MagicRoute.to('/incidents');
                  }
                },
                className: '''
                  p-2 rounded-lg
                  hover:bg-gray-100 dark:hover:bg-gray-700
                ''',
                child: WIcon(
                  Icons.arrow_back_outlined,
                  className: 'text-xl text-gray-700 dark:text-gray-300',
                ),
              ),
              WText(
                trans('incidents.edit'),
                className: 'text-2xl font-bold text-gray-900 dark:text-white',
              ),
            ],
          ),

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

          // Form Card
          WDiv(
            className: '''
              bg-white dark:bg-gray-800
              rounded-2xl shadow-sm
              border border-gray-200 dark:border-gray-700
              p-6
            ''',
            child: WDiv(
              className: 'flex flex-col gap-5',
              children: [
                // Title
                WFormInput(
                  controller: form['title'],
                  label: trans('incidents.title_label'),
                  hint: trans('incidents.title_placeholder'),
                  className: '''
                    w-full px-3 py-3 rounded-lg text-sm
                    bg-white dark:bg-gray-900
                    text-gray-900 dark:text-white
                    border border-gray-200 dark:border-gray-700
                    focus:border-primary focus:ring-2 focus:ring-primary/20
                    error:border-red-500
                  ''',
                  labelClassName:
                      'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
                  validator: FormValidator.rules([
                    Required(),
                    Min(3),
                    Max(255),
                  ], field: 'title'),
                ),

                // Impact
                WDiv(
                  className: 'flex flex-col gap-2',
                  children: [
                    WText(
                      trans('incidents.impact'),
                      className:
                          'text-sm font-medium text-gray-700 dark:text-gray-300',
                    ),
                    WSelect<IncidentImpact>(
                      value: _selectedImpact,
                      options: IncidentImpact.selectOptions,
                      onChange: (impact) {
                        setState(() => _selectedImpact = impact);
                      },
                      className: '''
                        w-full px-3 py-3 rounded-lg text-sm
                        bg-white dark:bg-gray-900
                        text-gray-900 dark:text-white
                        border border-gray-200 dark:border-gray-700
                      ''',
                      menuClassName: '''
                        bg-white dark:bg-gray-800
                        border border-gray-200 dark:border-gray-700
                        rounded-xl shadow-xl
                      ''',
                    ),
                  ],
                ),

                // Status
                WDiv(
                  className: 'flex flex-col gap-2',
                  children: [
                    WText(
                      trans('incidents.status'),
                      className:
                          'text-sm font-medium text-gray-700 dark:text-gray-300',
                    ),
                    WSelect<IncidentStatus>(
                      value: _selectedStatus,
                      options: IncidentStatus.selectOptions,
                      onChange: (status) {
                        setState(() => _selectedStatus = status);
                      },
                      className: '''
                        w-full px-3 py-3 rounded-lg text-sm
                        bg-white dark:bg-gray-900
                        text-gray-900 dark:text-white
                        border border-gray-200 dark:border-gray-700
                      ''',
                      menuClassName: '''
                        bg-white dark:bg-gray-800
                        border border-gray-200 dark:border-gray-700
                        rounded-xl shadow-xl
                      ''',
                    ),
                  ],
                ),

                // Info about current incident
                if (incident != null)
                  WDiv(
                    className: '''
                      p-4 rounded-lg
                      bg-gray-50 dark:bg-gray-900
                      border border-gray-200 dark:border-gray-700
                    ''',
                    children: [
                      WDiv(
                        className: 'flex flex-row items-center gap-2 mb-2',
                        children: [
                          WIcon(
                            Icons.info_outline,
                            className: 'text-gray-500 dark:text-gray-400',
                          ),
                          WText(
                            trans('incidents.edit_info'),
                            className:
                                'text-sm font-medium text-gray-700 dark:text-gray-300',
                          ),
                        ],
                      ),
                      WText(
                        trans('incidents.edit_info_message'),
                        className: 'text-xs text-gray-500 dark:text-gray-400',
                      ),
                    ],
                  ),
              ],
            ),
          ),

          // Action Buttons
          WDiv(
            className: 'flex flex-row justify-end gap-3 w-full pb-2',
            children: [
              WButton(
                onTap: () {
                  final incident = controller.selectedIncidentNotifier.value;
                  if (incident?.id != null) {
                    MagicRoute.to('/incidents/${incident!.id}');
                  } else {
                    MagicRoute.to('/incidents');
                  }
                },
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
                onTap: _handleSubmit,
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
        ],
      ),
    );
  }
}
