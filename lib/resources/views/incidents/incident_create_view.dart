import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../app/controllers/incident_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/incident_impact.dart';
import '../../../app/enums/incident_status.dart';

class IncidentCreateView extends MagicStatefulView<IncidentController> {
  const IncidentCreateView({super.key});

  @override
  State<IncidentCreateView> createState() => _IncidentCreateViewState();
}

class _IncidentCreateViewState
    extends MagicStatefulViewState<IncidentController, IncidentCreateView> {
  late final MagicFormData form;
  IncidentImpact _selectedImpact = IncidentImpact.partialOutage;
  List<String> _selectedMonitorIds = [];
  List<SelectOption<String>> _monitorOptions = [];

  @override
  void onInit() {
    super.onInit();
    controller.clearErrors();

    form = MagicFormData({'title': '', 'message': ''}, controller: controller);

    // Load monitors for selection
    _loadMonitors();
  }

  Future<void> _loadMonitors() async {
    final monitors = await MonitorController.instance.loadMonitors();
    setState(() {
      _monitorOptions = MonitorController.instance.monitorsNotifier.value
          .map((m) => SelectOption(value: m.id!, label: m.name ?? 'Unnamed'))
          .toList();
    });
  }

  @override
  void onClose() {
    form.dispose();
  }

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;

    if (_selectedMonitorIds.isEmpty) {
      Magic.toast(trans('incidents.select_at_least_one_monitor'));
      return;
    }

    await controller.store(
      title: form.get('title'),
      impact: _selectedImpact,
      message: form.get('message'),
      monitorIds: _selectedMonitorIds,
    );
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
                onTap: () => MagicRoute.to('/incidents'),
                className: '''
                  p-2 rounded-lg
                  hover:bg-gray-100 dark:hover:bg-gray-700
                ''',
                child: WIcon(
                  Icons.arrow_back,
                  className: 'text-xl text-gray-700 dark:text-gray-300',
                ),
              ),
              WText(
                trans('incidents.create_title'),
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
                        if (impact != null) {
                          setState(() => _selectedImpact = impact);
                        }
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

                // Monitors
                WDiv(
                  className: 'flex flex-col gap-2',
                  children: [
                    WText(
                      trans('incidents.affected_monitors'),
                      className:
                          'text-sm font-medium text-gray-700 dark:text-gray-300',
                    ),
                    WFormMultiSelect<String>(
                      values: _selectedMonitorIds,
                      options: _monitorOptions,
                      onMultiChange: (ids) {
                        setState(() => _selectedMonitorIds = ids);
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
                      placeholder: trans('incidents.select_monitors'),
                    ),
                  ],
                ),

                // Initial Message
                WFormInput(
                  controller: form['message'],
                  label: trans('incidents.initial_message'),
                  hint: trans('incidents.message_placeholder'),
                  maxLines: 4,
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
                    Min(10),
                  ], field: 'message'),
                ),
              ],
            ),
          ),

          // Action Buttons
          WDiv(
            className: 'flex flex-row justify-end gap-3 w-full pb-2',
            children: [
              WButton(
                onTap: () => MagicRoute.to('/incidents'),
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
                child: WText(trans('common.create')),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
