import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/status_page.dart';

class StatusPageEditView extends MagicStatefulView<StatusPageController> {
  const StatusPageEditView({super.key});

  @override
  State<StatusPageEditView> createState() => _StatusPageEditViewState();
}

class _StatusPageEditViewState
    extends MagicStatefulViewState<StatusPageController, StatusPageEditView> {
  late final MagicFormData form;
  final List<Map<String, dynamic>> _selectedMonitors = [];
  bool _isDataLoaded = false;
  int? _pageId;

  @override
  void onInit() {
    super.onInit();
    controller.clearErrors();

    // Load monitors for selection
    WidgetsBinding.instance.addPostFrameCallback((_) {
      MonitorController.instance.loadMonitors();
    });

    final idStr = MagicRouter.instance.pathParameter('id');
    if (idStr != null) {
      _pageId = int.tryParse(idStr);
      if (_pageId != null) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          controller.loadStatusPage(_pageId!);
        });
      }
    }

    form = MagicFormData({
      'name': '',
      'slug': '',
      'description': '',
      'logo_url': '',
      'favicon_url': '',
      'primary_color': '#009E60',
      'is_published': false,
    }, controller: controller);
  }

  @override
  void onClose() {
    form.dispose();
  }

  void _populateForm(StatusPage page) {
    if (_isDataLoaded) return;

    form.set('name', page.name);
    form.set('slug', page.slug);
    form.set('description', page.description ?? '');
    form.set('logo_url', page.logoUrl ?? '');
    form.set('favicon_url', page.faviconUrl ?? '');
    form.set('primary_color', page.primaryColor);
    form.setValue('is_published', page.isPublished);

    // Populate monitors
    _selectedMonitors.clear();
    // Sort monitors by sort_order if available in pivot
    final sortedMonitors = List<Monitor>.from(page.monitors);
    sortedMonitors.sort((a, b) {
      final pivotA = a.get<Map<String, dynamic>>('pivot');
      final pivotB = b.get<Map<String, dynamic>>('pivot');
      final orderA = pivotA?['sort_order'] as int? ?? 0;
      final orderB = pivotB?['sort_order'] as int? ?? 0;
      return orderA.compareTo(orderB);
    });

    for (var m in sortedMonitors) {
      final pivot = m.get<Map<String, dynamic>>('pivot');
      _selectedMonitors.add({
        'monitor_id': m.id,
        'name': m.name,
        'display_name': pivot?['display_name'] ?? m.name,
      });
    }

    _isDataLoaded = true;
  }

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;
    if (_pageId == null) return;

    // Prepare monitors list with order
    final monitors = _selectedMonitors.asMap().entries.map((entry) {
      final index = entry.key;
      final item = entry.value;
      return {
        'monitor_id': item['monitor_id'],
        'display_name': item['display_name'],
        'sort_order': index,
      };
    }).toList();

    await controller.update(
      _pageId!,
      name: form.get('name'),
      slug: form.get('slug'),
      description: form.get('description'),
      logoUrl: form.get('logo_url'),
      faviconUrl: form.get('favicon_url'),
      primaryColor: form.get('primary_color'),
      isPublished: form.value<bool>('is_published'),
      monitors: monitors,
    );
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<StatusPage?>(
      valueListenable: controller.selectedStatusPageNotifier,
      builder: (context, page, _) {
        if (controller.isLoading && page == null) {
          return _buildForm(isLoading: true);
        }

        if (page != null) {
          _populateForm(page);
        }

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
                onTap: () => MagicRoute.to('/status-pages'),
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
                trans('status_pages.edit_title'),
                className: 'text-2xl font-bold text-gray-900 dark:text-white',
              ),
            ],
          ),

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

          // Basic Info
          WDiv(
            className: '''
              bg-white dark:bg-gray-800
              border border-gray-100 dark:border-gray-700
              rounded-2xl p-6
              flex flex-col gap-4
            ''',
            children: [
              WText(
                trans('status_pages.basic_info'),
                className:
                    'text-lg font-semibold text-gray-900 dark:text-white',
              ),

              WFormInput(controller: form['name'], label: trans('common.name')),

              WFormInput(
                controller: form['slug'],
                label: trans('status_pages.slug'),
                prefix: WDiv(
                  className: 'pl-3',
                  child: WText(
                    'uptizm.com/status/',
                    className: 'text-gray-500 text-sm',
                  ),
                ),
              ),

              WFormInput(
                controller: form['description'],
                label: trans('common.description'),
                type: InputType.multiline,
                maxLines: 3,
              ),

              WFormInput(
                controller: form['primary_color'],
                label: trans('status_pages.primary_color'),
              ),
            ],
          ),

          // Monitors Section
          _buildMonitorsSection(),

          // Branding
          WDiv(
            className: '''
              bg-white dark:bg-gray-800
              border border-gray-100 dark:border-gray-700
              rounded-2xl p-6
              flex flex-col gap-4
            ''',
            children: [
              WText(
                trans('status_pages.branding'),
                className:
                    'text-lg font-semibold text-gray-900 dark:text-white',
              ),

              WFormInput(
                controller: form['logo_url'],
                label: trans('status_pages.logo_url'),
              ),

              WFormInput(
                controller: form['favicon_url'],
                label: trans('status_pages.favicon_url'),
              ),

              WFormCheckbox(
                value: form.value<bool>('is_published'),
                onChanged: (val) => form.setValue('is_published', val),
                label: WText(trans('status_pages.publish_immediately')),
              ),
            ],
          ),

          // Actions
          WDiv(
            className: 'flex flex-row justify-end gap-3 w-full pb-2',
            children: [
              WButton(
                onTap: () => MagicRoute.to('/status-pages'),
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
                child: WText(trans('common.save_changes')),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildMonitorsSection() {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-6
        flex flex-col gap-4
      ''',
      children: [
        WText(
          trans('navigation.monitors'),
          className: 'text-lg font-semibold text-gray-900 dark:text-white',
        ),

        ValueListenableBuilder<List<Monitor>>(
          valueListenable: MonitorController.instance.monitorsNotifier,
          builder: (context, allMonitors, _) {
            if (MonitorController.instance.isLoading && allMonitors.isEmpty) {
              return const CircularProgressIndicator();
            }

            final selectedIds = _selectedMonitors
                .map((m) => m['monitor_id'])
                .toSet();
            final availableMonitors = allMonitors
                .where((m) => !selectedIds.contains(m.id))
                .toList();

            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (_selectedMonitors.isNotEmpty)
                  ReorderableListView(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    onReorder: (oldIndex, newIndex) {
                      setState(() {
                        if (oldIndex < newIndex) {
                          newIndex -= 1;
                        }
                        final item = _selectedMonitors.removeAt(oldIndex);
                        _selectedMonitors.insert(newIndex, item);
                      });
                    },
                    children: _selectedMonitors.asMap().entries.map((entry) {
                      final index = entry.key;
                      final item = entry.value;
                      return ListTile(
                        key: ValueKey(item['monitor_id']),
                        title: WText(item['name'] ?? 'Unknown Monitor'),
                        leading: const Icon(
                          Icons.drag_handle,
                          color: Colors.grey,
                        ),
                        trailing: IconButton(
                          icon: const Icon(Icons.remove_circle_outline),
                          onPressed: () {
                            setState(() {
                              _selectedMonitors.removeAt(index);
                            });
                          },
                        ),
                        subtitle: WInput(
                          value: item['display_name'] ?? '',
                          placeholder: trans('status_pages.custom_label'),
                          onChanged: (val) {
                            setState(() {
                              item['display_name'] = val;
                            });
                          },
                          className:
                              'mt-1 text-sm border-gray-200 dark:border-gray-700 rounded',
                        ),
                      );
                    }).toList(),
                  ),

                const SizedBox(height: 16),

                if (availableMonitors.isNotEmpty)
                  WSelect<int>(
                    options: availableMonitors
                        .map(
                          (m) => SelectOption<int>(
                            value: m.id!,
                            label: m.name ?? 'Monitor #${m.id}',
                          ),
                        )
                        .toList(),
                    value: null,
                    placeholder: trans('status_pages.add_monitor'),
                    onChange: (monitorId) {
                      final monitor = availableMonitors.firstWhere(
                        (m) => m.id == monitorId,
                      );
                      setState(() {
                        _selectedMonitors.add({
                          'monitor_id': monitor.id,
                          'name': monitor.name,
                          'display_name': monitor.name,
                        });
                      });
                    },
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}
