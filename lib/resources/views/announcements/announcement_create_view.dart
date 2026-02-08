import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../app/controllers/announcement_controller.dart';
import '../../../app/enums/announcement_type.dart';

class AnnouncementCreateView extends MagicStatefulView<AnnouncementController> {
  const AnnouncementCreateView({super.key});

  @override
  State<AnnouncementCreateView> createState() => _AnnouncementCreateViewState();
}

class _AnnouncementCreateViewState
    extends
        MagicStatefulViewState<AnnouncementController, AnnouncementCreateView> {
  late final MagicFormData form;
  AnnouncementType _selectedType = AnnouncementType.maintenance;
  DateTime? _scheduledAt;
  final _dateController = TextEditingController();
  late final String statusPageId;

  @override
  void onInit() {
    super.onInit();
    statusPageId = MagicRouter.instance.pathParameter('statusPageId')!;
    controller.clearErrors();
    form = MagicFormData({'title': '', 'body': ''}, controller: controller);
  }

  @override
  void onClose() {
    form.dispose();
    _dateController.dispose();
  }

  Future<void> _pickDateTime() async {
    final now = DateTime.now();
    final date = await showDatePicker(
      context: context,
      initialDate: _scheduledAt ?? now,
      firstDate: now,
      lastDate: now.add(const Duration(days: 365)),
    );

    if (date == null) return;

    if (!mounted) return;

    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(_scheduledAt ?? now),
    );

    if (time == null) return;

    setState(() {
      _scheduledAt = DateTime(
        date.year,
        date.month,
        date.day,
        time.hour,
        time.minute,
      );
      _dateController.text = DateFormat(
        'yyyy-MM-dd HH:mm',
      ).format(_scheduledAt!);
    });
  }

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;

    await controller.store(
      statusPageId,
      title: form.get('title'),
      body: form.get('body'),
      type: _selectedType,
      scheduledAt: _scheduledAt?.toIso8601String(),
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
                onTap: () =>
                    MagicRoute.to('/status-pages/$statusPageId/announcements'),
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
                trans('announcements.create_title'),
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
                  label: trans('announcements.title_label'),
                  hint: trans('announcements.title_placeholder'),
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

                // Type
                WDiv(
                  className: 'flex flex-col gap-2',
                  children: [
                    WText(
                      trans('announcements.type'),
                      className:
                          'text-sm font-medium text-gray-700 dark:text-gray-300',
                    ),
                    WSelect<AnnouncementType>(
                      value: _selectedType,
                      options: AnnouncementType.selectOptions,
                      onChange: (type) {
                        setState(() => _selectedType = type);
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

                // Scheduled At
                WDiv(
                  className: 'flex flex-col gap-2',
                  children: [
                    WFormInput(
                      controller: _dateController,
                      label: trans('announcements.scheduled_at'),
                      hint: trans('announcements.scheduled_at_placeholder'),
                      readOnly: true,
                      onTap: _pickDateTime,
                      className: '''
                        w-full px-3 py-3 rounded-lg text-sm
                        bg-white dark:bg-gray-900
                        text-gray-900 dark:text-white
                        border border-gray-200 dark:border-gray-700
                        focus:border-primary focus:ring-2 focus:ring-primary/20
                        cursor-pointer
                      ''',
                      labelClassName:
                          'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
                      suffix: WIcon(
                        Icons.calendar_today,
                        className: 'text-gray-400 text-lg',
                      ),
                    ),
                  ],
                ),

                // Body
                WFormInput(
                  controller: form['body'],
                  label: trans('announcements.body_label'),
                  hint: trans('announcements.body_placeholder'),
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
                  ], field: 'body'),
                ),
              ],
            ),
          ),

          // Action Buttons
          WDiv(
            className: 'flex flex-row justify-end gap-3 w-full pb-2',
            children: [
              WButton(
                onTap: () =>
                    MagicRoute.to('/status-pages/$statusPageId/announcements'),
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
