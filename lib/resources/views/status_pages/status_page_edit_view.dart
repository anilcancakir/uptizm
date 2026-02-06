import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/metric_mapping.dart';
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
      final id = m.id ?? (m.get('id') as num?)?.toInt();
      _selectedMonitors.add({
        'monitor_id': id,
        'name': m.name,
        'display_name': pivot?['display_name'] ?? m.name,
        'metric_mappings': m.metricMappings,
        'metric_keys':
            (pivot?['selected_metrics'] as List?)?.cast<String>() ?? [],
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
        'metric_keys': item['metric_keys'],
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

              WFormInput(
                controller: form['name'],
                label: trans('status_pages.name'),
                hint: trans('status_pages.name_placeholder'),
                labelClassName: '''
                  text-gray-900 dark:text-gray-200
                  mb-2 text-sm font-medium
                ''',
                className: '''
                  w-full bg-white dark:bg-gray-800
                  text-gray-900 dark:text-white
                  rounded-lg
                  border border-gray-200 dark:border-gray-700
                  px-3 py-3
                  text-sm
                  focus:border-primary
                  focus:ring-2 focus:ring-primary/20
                  error:border-red-500
                ''',
                validator: FormValidator.rules([
                  Required(),
                  Min(3),
                  Max(100),
                ], field: 'name'),
              ),

              WFormInput(
                controller: form['slug'],
                label: trans('status_pages.slug'),
                hint: trans('status_pages.slug_placeholder'),
                prefix: WDiv(
                  className: 'pl-3',
                  child: WText(
                    'https://',
                    className: 'text-gray-500 dark:text-gray-400 text-sm',
                  ),
                ),
                suffix: WDiv(
                  className: 'pr-3',
                  child: WText(
                    '.uptizm.com',
                    className: 'text-gray-500 dark:text-gray-400 text-sm',
                  ),
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
                  px-3 py-3
                  text-sm font-mono
                  focus:border-primary
                  focus:ring-2 focus:ring-primary/20
                  error:border-red-500
                ''',
                validator: FormValidator.rules([
                  Required(),
                  Min(1),
                  Max(63),
                ], field: 'slug'),
              ),

              WFormInput(
                controller: form['description'],
                label: trans('status_pages.description'),
                hint: trans('status_pages.description_placeholder'),
                type: InputType.multiline,
                maxLines: 3,
                labelClassName: '''
                  text-gray-900 dark:text-gray-200
                  mb-2 text-sm font-medium
                ''',
                className: '''
                  w-full bg-white dark:bg-gray-800
                  text-gray-900 dark:text-white
                  rounded-lg
                  border border-gray-200 dark:border-gray-700
                  px-3 py-3
                  text-sm
                  focus:border-primary
                  focus:ring-2 focus:ring-primary/20
                  error:border-red-500
                ''',
              ),

              WFormInput(
                controller: form['primary_color'],
                label: trans('status_pages.primary_color'),
                hint: trans('status_pages.primary_color_placeholder'),
                labelClassName: '''
                  text-gray-900 dark:text-gray-200
                  mb-2 text-sm font-medium
                ''',
                className: '''
                  w-full bg-white dark:bg-gray-800
                  text-gray-900 dark:text-white
                  rounded-lg
                  border border-gray-200 dark:border-gray-700
                  px-3 py-3
                  text-sm font-mono
                  focus:border-primary
                  focus:ring-2 focus:ring-primary/20
                  error:border-red-500
                ''',
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
                hint: trans('status_pages.logo_url_placeholder'),
                labelClassName: '''
                  text-gray-900 dark:text-gray-200
                  mb-2 text-sm font-medium
                ''',
                className: '''
                  w-full bg-white dark:bg-gray-800
                  text-gray-900 dark:text-white
                  rounded-lg
                  border border-gray-200 dark:border-gray-700
                  px-3 py-3
                  text-sm
                  focus:border-primary
                  focus:ring-2 focus:ring-primary/20
                  error:border-red-500
                ''',
              ),

              WFormInput(
                controller: form['favicon_url'],
                label: trans('status_pages.favicon_url'),
                hint: trans('status_pages.favicon_url_placeholder'),
                labelClassName: '''
                  text-gray-900 dark:text-gray-200
                  mb-2 text-sm font-medium
                ''',
                className: '''
                  w-full bg-white dark:bg-gray-800
                  text-gray-900 dark:text-white
                  rounded-lg
                  border border-gray-200 dark:border-gray-700
                  px-3 py-3
                  text-sm
                  focus:border-primary
                  focus:ring-2 focus:ring-primary/20
                  error:border-red-500
                ''',
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
        flex flex-col gap-6
      ''',
      children: [
        // Section Header
        WDiv(
          className: 'flex flex-col gap-1',
          children: [
            WText(
              trans('status_pages.monitors_section'),
              className: 'text-lg font-bold text-gray-900 dark:text-white',
            ),
            WText(
              trans('status_pages.monitors_section_hint'),
              className: 'text-sm text-gray-500 dark:text-gray-400',
            ),
          ],
        ),

        ValueListenableBuilder<List<Monitor>>(
          valueListenable: MonitorController.instance.monitorsNotifier,
          builder: (context, allMonitors, _) {
            if (MonitorController.instance.isLoading && allMonitors.isEmpty) {
              return WDiv(
                className: 'py-8 flex items-center justify-center',
                child: const CircularProgressIndicator(),
              );
            }

            final selectedIds = _selectedMonitors
                .map((m) => m['monitor_id'])
                .toSet();
            final availableMonitors = allMonitors
                .where((m) => !selectedIds.contains(m.id))
                .toList();

            return WDiv(
              className: 'flex flex-col gap-4',
              children: [
                // Selected Monitors List
                if (_selectedMonitors.isNotEmpty)
                  WDiv(
                    className: 'flex flex-col gap-3',
                    children: [
                      ReorderableListView(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        proxyDecorator: (child, index, animation) {
                          return Material(
                            color: Colors.transparent,
                            elevation: 0,
                            child: WDiv(
                              className:
                                  'shadow-lg rounded-xl opacity-90 scale-105 transition-transform',
                              children: [child],
                            ),
                          );
                        },
                        onReorder: (oldIndex, newIndex) {
                          setState(() {
                            if (oldIndex < newIndex) {
                              newIndex -= 1;
                            }
                            final item = _selectedMonitors.removeAt(oldIndex);
                            _selectedMonitors.insert(newIndex, item);
                          });
                        },
                        children: _selectedMonitors.asMap().entries.map((
                          entry,
                        ) {
                          final index = entry.key;
                          final item = entry.value;
                          return WDiv(
                            key: ValueKey(item['monitor_id']),
                            className: '''
                              group
                              flex flex-row items-start gap-3 p-4 mb-3
                              bg-gray-50 dark:bg-gray-900/50
                              border border-gray-200 dark:border-gray-700
                              rounded-xl
                              hover:border-primary/50 dark:hover:border-primary/50
                              transition-colors duration-200
                            ''',
                            children: [
                              // Drag Handle
                              WDiv(
                                className:
                                    'h-6 flex items-center justify-center cursor-grab active:cursor-grabbing text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400',
                                children: [
                                  WIcon(
                                    Icons.drag_indicator,
                                    className: 'text-xl',
                                  ),
                                ],
                              ),

                              // Content
                              Expanded(
                                child: WDiv(
                                  className: 'flex flex-col gap-3',
                                  children: [
                                    // Monitor Info
                                    WDiv(
                                      className:
                                          'flex flex-row items-center gap-2',
                                      children: [
                                        // Status Dot (Simulated for UI)
                                        WDiv(
                                          className:
                                              'w-2 h-2 rounded-full bg-green-500 shadow-glow-green',
                                        ),
                                        WText(
                                          item['name'] ?? 'Unknown Monitor',
                                          className:
                                              'text-sm font-bold text-gray-900 dark:text-white',
                                        ),
                                        WDiv(
                                          className:
                                              'px-2 py-0.5 rounded-md bg-gray-200 dark:bg-gray-700',
                                          children: [
                                            WText(
                                              '#${index + 1}',
                                              className:
                                                  'text-xs font-mono font-medium text-gray-500 dark:text-gray-400',
                                            ),
                                          ],
                                        ),
                                      ],
                                    ),

                                    // Custom Label Input
                                    WInput(
                                      value: item['display_name'] ?? '',
                                      placeholder: trans(
                                        'status_pages.custom_label',
                                      ),
                                      onChanged: (val) {
                                        setState(() {
                                          item['display_name'] = val;
                                        });
                                      },
                                      className: '''
                                        w-full
                                        bg-white dark:bg-gray-800
                                        text-gray-900 dark:text-white
                                        text-sm
                                        px-3 py-2
                                        rounded-lg
                                        border border-gray-200 dark:border-gray-700
                                        focus:border-primary focus:ring-2 focus:ring-primary/10
                                        placeholder:text-gray-400 dark:placeholder:text-gray-500
                                      ''',
                                    ),

                                    // Metric Selection
                                    if (item['metric_mappings'] != null &&
                                        (item['metric_mappings'] as List)
                                            .isNotEmpty)
                                      WDiv(
                                        className:
                                            'mt-2 pt-3 border-t border-gray-100 dark:border-gray-800',
                                        children: [
                                          WText(
                                            trans(
                                              'status_pages.custom_metrics',
                                            ),
                                            className:
                                                'text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2',
                                          ),
                                          WDiv(
                                            className: 'flex flex-col gap-2',
                                            children: (item['metric_mappings'] as List)
                                                .map(
                                                  (
                                                    m,
                                                  ) => MetricMapping.tryFromMap(
                                                    m as Map<String, dynamic>,
                                                  ),
                                                )
                                                .whereType<MetricMapping>()
                                                .map((mapping) {
                                                  final selectedKeys =
                                                      (item['metric_keys']
                                                              as List?)
                                                          ?.cast<String>() ??
                                                      [];
                                                  final isSelected =
                                                      selectedKeys.contains(
                                                        mapping.path,
                                                      );

                                                  return WDiv(
                                                    className:
                                                        'flex flex-row items-center gap-2 cursor-pointer',
                                                    children: [
                                                      WCheckbox(
                                                        value: isSelected,
                                                        onChanged: (val) {
                                                          setState(() {
                                                            final currentKeys =
                                                                (item['metric_keys']
                                                                        as List?)
                                                                    ?.cast<
                                                                      String
                                                                    >() ??
                                                                [];
                                                            if (val == true) {
                                                              currentKeys.add(
                                                                mapping.path,
                                                              );
                                                            } else {
                                                              currentKeys
                                                                  .remove(
                                                                    mapping
                                                                        .path,
                                                                  );
                                                            }
                                                            item['metric_keys'] =
                                                                currentKeys;
                                                          });
                                                        },
                                                      ),
                                                      GestureDetector(
                                                        onTap: () {
                                                          setState(() {
                                                            final currentKeys =
                                                                (item['metric_keys']
                                                                        as List?)
                                                                    ?.cast<
                                                                      String
                                                                    >() ??
                                                                [];
                                                            if (!isSelected) {
                                                              currentKeys.add(
                                                                mapping.path,
                                                              );
                                                            } else {
                                                              currentKeys
                                                                  .remove(
                                                                    mapping
                                                                        .path,
                                                                  );
                                                            }
                                                            item['metric_keys'] =
                                                                currentKeys;
                                                          });
                                                        },
                                                        child: WText(
                                                          '${mapping.label} (${mapping.path})',
                                                          className:
                                                              'text-sm text-gray-700 dark:text-gray-300',
                                                        ),
                                                      ),
                                                    ],
                                                  );
                                                })
                                                .toList(),
                                          ),
                                        ],
                                      ),
                                  ],
                                ),
                              ),

                              // Remove Button
                              WDiv(
                                className:
                                    'h-6 flex items-center justify-center',
                                children: [
                                  WButton(
                                    onTap: () {
                                      setState(() {
                                        _selectedMonitors.removeAt(index);
                                      });
                                    },
                                    className: '''
                                      p-1 rounded-lg
                                      text-gray-400 hover:text-red-500 hover:bg-red-50
                                      dark:text-gray-500 dark:hover:text-red-400 dark:hover:bg-red-900/20
                                      transition-colors
                                    ''',
                                    child: WIcon(
                                      Icons.close,
                                      className: 'text-lg',
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          );
                        }).toList(),
                      ),
                    ],
                  )
                else
                  // Empty State
                  WDiv(
                    className: '''
                      flex flex-col items-center justify-center py-10 px-4
                      bg-gray-50 dark:bg-gray-900/30
                      border-2 border-dashed border-gray-200 dark:border-gray-700
                      rounded-xl
                    ''',
                    children: [
                      WDiv(
                        className:
                            'w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-3',
                        children: [
                          WIcon(
                            Icons.monitor_heart_outlined,
                            className:
                                'text-2xl text-gray-400 dark:text-gray-500',
                          ),
                        ],
                      ),
                      WText(
                        trans('status_pages.no_monitors_selected'),
                        className:
                            'text-sm font-semibold text-gray-900 dark:text-white mb-1',
                      ),
                      WText(
                        trans('status_pages.no_monitors_selected_hint'),
                        className:
                            'text-xs text-gray-500 dark:text-gray-400 text-center max-w-xs',
                      ),
                    ],
                  ),

                // Add Monitor Select
                if (availableMonitors.isNotEmpty)
                  WDiv(
                    className: 'mt-2',
                    children: [
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
                        className: '''
                          w-full
                          bg-white dark:bg-gray-800
                          text-gray-900 dark:text-white
                          rounded-xl
                          border border-gray-200 dark:border-gray-700
                          px-4 py-3
                          text-sm font-medium
                          focus:border-primary focus:ring-2 focus:ring-primary/20
                          shadow-sm
                        ''',
                        menuClassName: '''
                          bg-white dark:bg-gray-800
                          border border-gray-200 dark:border-gray-700
                          rounded-xl shadow-xl mt-2
                        ''',
                        onChange: (monitorId) {
                          final monitor = availableMonitors.firstWhere(
                            (m) => m.id == monitorId,
                          );
                          setState(() {
                            _selectedMonitors.add({
                              'monitor_id':
                                  monitor.id ?? monitorId, // Ensure ID is used
                              'name': monitor.name,
                              'display_name': monitor.name,
                              'metric_mappings': monitor.metricMappings,
                              'metric_keys': <String>[],
                            });
                          });
                        },
                      ),
                    ],
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}
