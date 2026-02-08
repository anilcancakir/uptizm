import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/announcement_controller.dart';
import '../../../app/enums/announcement_type.dart';

class AnnouncementEditView extends MagicStatefulView<AnnouncementController> {
  const AnnouncementEditView({super.key});

  @override
  State<AnnouncementEditView> createState() => _AnnouncementEditViewState();
}

class _AnnouncementEditViewState
    extends
        MagicStatefulViewState<AnnouncementController, AnnouncementEditView> {
  late final MagicFormData form;
  AnnouncementType _selectedType = AnnouncementType.informational;
  DateTime? _scheduledAt;
  DateTime? _endedAt;
  bool _initialized = false;

  String get statusPageId =>
      MagicRouter.instance.pathParameter('statusPageId')!;
  String get id => MagicRouter.instance.pathParameter('id')!;

  @override
  void onInit() {
    super.onInit();
    controller.clearErrors();
    form = MagicFormData({'title': '', 'body': ''}, controller: controller);
    controller.loadAnnouncement(statusPageId, id);
  }

  void _initializeFormFromAnnouncement() {
    final announcement = controller.selectedAnnouncementNotifier.value;
    if (announcement != null && !_initialized) {
      form.set('title', announcement.title ?? '');
      form.set('body', announcement.body ?? '');
      _selectedType = announcement.type ?? AnnouncementType.informational;
      _scheduledAt = announcement.scheduledAt != null
          ? DateTime.parse(announcement.scheduledAt.toString())
          : null;
      _endedAt = announcement.endedAt != null
          ? DateTime.parse(announcement.endedAt.toString())
          : null;
      _initialized = true;
    }
  }

  @override
  void onClose() {
    form.dispose();
  }

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;

    await controller.update(
      statusPageId,
      id,
      title: form.get('title'),
      body: form.get('body'),
      type: _selectedType,
      scheduledAt: _scheduledAt?.toIso8601String(),
      endedAt: _endedAt?.toIso8601String(),
    );
  }

  Future<void> _selectDate({required bool isStart}) async {
    final initialDate = isStart ? _scheduledAt : _endedAt;
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: initialDate ?? now,
      firstDate: now.subtract(const Duration(days: 365)),
      lastDate: now.add(const Duration(days: 365 * 5)),
    );

    if (picked != null && mounted) {
      final time = await showTimePicker(
        context: context,
        initialTime: TimeOfDay.fromDateTime(initialDate ?? now),
      );

      if (time != null) {
        final dateTime = DateTime(
          picked.year,
          picked.month,
          picked.day,
          time.hour,
          time.minute,
        );
        setState(() {
          if (isStart) {
            _scheduledAt = dateTime;
          } else {
            _endedAt = dateTime;
          }
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: controller.selectedAnnouncementNotifier,
      builder: (context, announcement, _) {
        if (announcement == null) {
          return WDiv(
            className: 'flex-1 flex items-center justify-center',
            child: const CircularProgressIndicator(),
          );
        }

        _initializeFormFromAnnouncement();

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
    final announcement = controller.selectedAnnouncementNotifier.value;

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
                  if (announcement?.id != null) {
                    MagicRoute.to(
                      '/status-pages/$statusPageId/announcements/${announcement!.id}',
                    );
                  } else {
                    MagicRoute.to('/status-pages/$statusPageId/announcements');
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
                trans('announcements.edit_title'),
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
                      trans('announcements.type_label'),
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
                    WText(
                      trans('announcements.scheduled_at_label'),
                      className:
                          'text-sm font-medium text-gray-700 dark:text-gray-300',
                    ),
                    WAnchor(
                      onTap: () => _selectDate(isStart: true),
                      child: WDiv(
                        className: '''
                          w-full px-3 py-3 rounded-lg text-sm
                          bg-white dark:bg-gray-900
                          text-gray-900 dark:text-white
                          border border-gray-200 dark:border-gray-700
                        ''',
                        child: WText(
                          _scheduledAt != null
                              ? _scheduledAt.toString()
                              : trans('announcements.select_date'),
                          className: _scheduledAt != null
                              ? 'text-gray-900 dark:text-white'
                              : 'text-gray-500 dark:text-gray-400',
                        ),
                      ),
                    ),
                  ],
                ),

                // Ended At
                WDiv(
                  className: 'flex flex-col gap-2',
                  children: [
                    WText(
                      trans('announcements.ended_at_label'),
                      className:
                          'text-sm font-medium text-gray-700 dark:text-gray-300',
                    ),
                    WAnchor(
                      onTap: () => _selectDate(isStart: false),
                      child: WDiv(
                        className: '''
                          w-full px-3 py-3 rounded-lg text-sm
                          bg-white dark:bg-gray-900
                          text-gray-900 dark:text-white
                          border border-gray-200 dark:border-gray-700
                        ''',
                        child: WText(
                          _endedAt != null
                              ? _endedAt.toString()
                              : trans('announcements.select_date'),
                          className: _endedAt != null
                              ? 'text-gray-900 dark:text-white'
                              : 'text-gray-500 dark:text-gray-400',
                        ),
                      ),
                    ),
                  ],
                ),

                // Body
                WFormInput(
                  controller: form['body'],
                  label: trans('announcements.body_label'),
                  hint: trans('announcements.body_placeholder'),
                  minLines: 5,
                  maxLines: 10,
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
                  validator: FormValidator.rules([Required()], field: 'body'),
                ),

                // Info Box
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
                          trans('announcements.edit_info'),
                          className:
                              'text-sm font-medium text-gray-700 dark:text-gray-300',
                        ),
                      ],
                    ),
                    WText(
                      trans('announcements.edit_info_message'),
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
                  if (announcement?.id != null) {
                    MagicRoute.to(
                      '/status-pages/$statusPageId/announcements/${announcement!.id}',
                    );
                  } else {
                    MagicRoute.to('/status-pages/$statusPageId/announcements');
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
