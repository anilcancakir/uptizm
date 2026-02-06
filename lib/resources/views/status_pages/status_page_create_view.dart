import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/monitor.dart';

class StatusPageCreateView extends MagicStatefulView<StatusPageController> {
  const StatusPageCreateView({super.key});

  @override
  State<StatusPageCreateView> createState() => _StatusPageCreateViewState();
}

class _StatusPageCreateViewState
    extends MagicStatefulViewState<StatusPageController, StatusPageCreateView> {
  late final MagicFormData form;

  // Selected monitors state
  final List<Map<String, dynamic>> _selectedMonitors = [];

  @override
  void onInit() {
    super.onInit();
    controller.clearErrors();

    // Load monitors for selection
    WidgetsBinding.instance.addPostFrameCallback((_) {
      MonitorController.instance.loadMonitors();
    });

    form = MagicFormData({
      'name': '',
      'slug': '',
      'description': '',
      'logo_url': '',
      'favicon_url': '',
      'primary_color': '#009E60',
      'is_published': false,
    }, controller: controller);

    // Auto-generate slug from name
    form['name'].addListener(() {
      final name = form.get('name');
      final slug = form.get('slug');

      if (name.isNotEmpty && slug.isEmpty) {
        final newSlug = _slugify(name);
        form.set('slug', newSlug);
      }
    });
  }

  String _slugify(String text) {
    return text
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
        .replaceAll(RegExp(r'^-+|-+$'), '');
  }

  @override
  void onClose() {
    form.dispose();
  }

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;

    // Prepare monitors list with order
    final monitors = _selectedMonitors.asMap().entries.map((entry) {
      final index = entry.key;
      final item = entry.value;
      return {
        'monitor_id': item['monitor_id'],
        'display_name': item['display_name'], // Custom label
        'sort_order': index,
      };
    }).toList();

    await controller.store(
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
                trans('status_pages.create_title'),
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

          // Basic Info Section
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

              // Name
              WFormInput(
                controller: form['name'],
                label: trans('common.name'),
                placeholder: trans('status_pages.name_placeholder'),
              ),

              // Slug
              WFormInput(
                controller: form['slug'],
                label: trans('status_pages.slug'),
                placeholder: 'my-status-page',
                prefix: WDiv(
                  className: 'pl-3',
                  child: WText(
                    'uptizm.com/status/',
                    className: 'text-gray-500 text-sm',
                  ),
                ),
              ),

              // Description
              WFormInput(
                controller: form['description'],
                label: trans('common.description'),
                type: InputType.multiline,
                maxLines: 3,
              ),

              // Primary Color
              WFormInput(
                controller: form['primary_color'],
                label: trans('status_pages.primary_color'),
                placeholder: '#009E60',
              ),
            ],
          ),

          // Monitors Section
          _buildMonitorsSection(),

          // Advanced / Optional
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
                placeholder: 'https://...',
              ),

              WFormInput(
                controller: form['favicon_url'],
                label: trans('status_pages.favicon_url'),
                placeholder: 'https://...',
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
                child: WText(trans('common.create')),
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

            // Filter out already selected
            final selectedIds = _selectedMonitors
                .map((m) => m['monitor_id'])
                .toSet();
            final availableMonitors = allMonitors
                .where((m) => !selectedIds.contains(m.id))
                .toList();

            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // List of selected monitors (Reorderable)
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

                // Add Monitor Dropdown
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
                          'display_name':
                              monitor.name, // Default custom label to name
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
