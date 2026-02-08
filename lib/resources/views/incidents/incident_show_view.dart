import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/incident_controller.dart';
import '../../../app/models/incident.dart';
import '../../../app/enums/incident_status.dart';
import '../../../app/enums/incident_impact.dart';
import '../components/app_page_header.dart';

class IncidentShowView extends MagicStatefulView<IncidentController> {
  const IncidentShowView({super.key});

  @override
  State<IncidentShowView> createState() => _IncidentShowViewState();
}

class _IncidentShowViewState
    extends MagicStatefulViewState<IncidentController, IncidentShowView> {
  late final MagicFormData _updateForm;
  IncidentStatus _updateStatus = IncidentStatus.investigating;
  bool _isSubmitting = false;

  @override
  void onInit() {
    super.onInit();
    controller.selectedIncidentNotifier.addListener(_rebuild);
    _updateForm = MagicFormData({
      'title': '',
      'message': '',
    }, controller: controller);
  }

  @override
  void onClose() {
    controller.selectedIncidentNotifier.removeListener(_rebuild);
    _updateForm.dispose();
  }

  void _rebuild() => setState(() {});

  void _showResolveDialog(Incident incident) {
    final resolveMessageController = TextEditingController();

    Magic.dialog(
      WDiv(
        className:
            'bg-white dark:bg-gray-800 rounded-2xl w-full max-w-sm overflow-y-auto max-h-[90vh]',
        scrollPrimary: true,
        children: [
          // Header
          WDiv(
            className: 'flex flex-row items-center gap-2 mb-3',
            children: [
              WDiv(
                className: 'p-1.5 rounded-lg bg-green-50 dark:bg-green-900/20',
                child: WIcon(
                  Icons.check_circle_outline,
                  className: 'text-green-600 dark:text-green-400 text-lg',
                ),
              ),
              WDiv(
                className: 'flex-1 min-w-0',
                child: WText(
                  trans('incidents.resolve_incident'),
                  className:
                      'text-base font-semibold text-gray-900 dark:text-white',
                ),
              ),
            ],
          ),

          // Description
          WText(
            trans('incidents.resolve_description'),
            className: 'text-sm text-gray-600 dark:text-gray-400 mb-3',
          ),

          // Message input
          WDiv(
            className: 'mb-4',
            children: [
              WFormInput(
                controller: resolveMessageController,
                label: trans('incidents.resolution_message'),
                hint: trans('incidents.resolution_message_hint'),
                maxLines: 3,
                labelClassName:
                    'text-sm font-medium text-gray-700 dark:text-gray-300 mb-1',
                className: '''
                  w-full px-3 py-3 rounded-lg text-sm
                  bg-white dark:bg-gray-900
                  text-gray-900 dark:text-white
                  border border-gray-300 dark:border-gray-700
                  focus:border-primary focus:ring-2 focus:ring-primary/20
                ''',
              ),
            ],
          ),

          // Actions
          WDiv(
            className: 'flex flex-row justify-end gap-2',
            children: [
              WButton(
                onTap: () => Magic.closeDialog(),
                className: '''
                  px-3 py-2 rounded-lg
                  bg-gray-100 dark:bg-gray-700
                  text-gray-700 dark:text-gray-200
                  hover:bg-gray-200 dark:hover:bg-gray-600
                  text-sm font-medium
                ''',
                child: WText(trans('common.cancel')),
              ),
              WButton(
                onTap: () async {
                  Magic.closeDialog();
                  final message = resolveMessageController.text.trim();
                  await controller.addUpdate(
                    incident.id!,
                    status: IncidentStatus.resolved,
                    message: message.isEmpty
                        ? trans('incidents.incident_resolved')
                        : message,
                  );
                },
                className: '''
                  px-3 py-2 rounded-lg
                  bg-green-600 hover:bg-green-700
                  text-white
                  text-sm font-medium
                ''',
                child: WDiv(
                  className: 'flex flex-row items-center gap-1',
                  children: [
                    WIcon(Icons.check, className: 'text-base'),
                    WText(trans('incidents.resolve')),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _handleAddUpdate() async {
    final incident = controller.selectedIncidentNotifier.value;
    if (incident?.id == null) return;

    final message = _updateForm.get('message');
    if (message.toString().isEmpty) {
      Magic.toast('Message is required');
      return;
    }

    setState(() => _isSubmitting = true);

    await controller.addUpdate(
      incident!.id!,
      status: _updateStatus,
      message: message,
      title: _updateForm.get('title'),
    );

    // Reset form
    _updateForm.set('title', '');
    _updateForm.set('message', '');

    setState(() => _isSubmitting = false);
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: controller.selectedIncidentNotifier,
      builder: (context, incident, _) {
        if (incident == null) {
          return WDiv(
            className: 'py-12 flex items-center justify-center',
            child: WDiv(
              className: 'flex flex-col items-center gap-4',
              children: [
                const CircularProgressIndicator(),
                WText(
                  'Loading...',
                  className: 'text-gray-500 dark:text-gray-400',
                ),
              ],
            ),
          );
        }

        return WDiv(
          className: 'overflow-y-auto flex flex-col gap-4 lg:gap-6 pb-4',
          scrollPrimary: true,
          children: [
            _buildHeader(incident),
            WDiv(
              className: 'flex flex-col px-4 lg:px-6 gap-4 lg:gap-6',
              children: [
                _buildStatsSection(incident),
                _buildAffectedMonitors(incident),
                _buildTimeline(incident),
                if (!incident.isResolved) _buildAddUpdateSection(incident),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _buildHeader(Incident incident) {
    return AppPageHeader(
      leading: WButton(
        onTap: () => MagicRoute.to('/incidents'),
        className: 'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
        child: WIcon(
          Icons.arrow_back,
          className: 'text-xl text-gray-700 dark:text-gray-300',
        ),
      ),
      title: incident.title ?? 'Incident',
      subtitle: incident.startedAt?.format('MMM d, yyyy HH:mm'),
      actions: [
        // Resolve Button (only for unresolved incidents)
        if (!incident.isResolved)
          WButton(
            onTap: () => _showResolveDialog(incident),
            className: '''
              px-3 py-2 rounded-lg
              bg-green-50 dark:bg-green-900/20
              text-green-600 dark:text-green-400
              hover:bg-green-100 dark:hover:bg-green-900/30
              text-sm font-medium
            ''',
            child: WDiv(
              className: 'flex flex-row items-center sm:gap-2',
              children: [
                WIcon(Icons.check_circle_outline, className: 'text-base'),
                WText(trans('incidents.resolve'), className: 'hidden sm:block'),
              ],
            ),
          ),

        // Edit Button
        if (!incident.isResolved)
          WButton(
            onTap: () => MagicRoute.to('/incidents/${incident.id}/edit'),
            className: '''
              px-3 py-2 rounded-lg
              bg-gray-100 dark:bg-gray-700
              text-gray-700 dark:text-gray-200
              hover:bg-gray-200 dark:hover:bg-gray-600
              text-sm font-medium
            ''',
            child: WDiv(
              className: 'flex flex-row items-center sm:gap-2',
              children: [
                WIcon(Icons.edit_outlined, className: 'text-base'),
                WText('Edit', className: 'hidden sm:block'),
              ],
            ),
          ),

        // Delete Button
        WButton(
          onTap: () => controller.destroy(incident.id!),
          className: '''
            px-3 py-2 rounded-lg
            bg-red-50 dark:bg-red-900/20
            text-red-600 dark:text-red-400
            hover:bg-red-100 dark:hover:bg-red-900/30
            text-sm font-medium
          ''',
          child: WDiv(
            className: 'flex flex-row items-center sm:gap-2',
            children: [
              WIcon(Icons.delete_outline, className: 'text-base'),
              WText('Delete', className: 'hidden sm:block'),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildStatsSection(Incident incident) {
    return WDiv(
      className: 'grid grid-cols-2 md:grid-cols-4 gap-4',
      children: [
        // Impact
        _buildStatCard(
          label: 'Impact',
          value: incident.impact?.label ?? 'Unknown',
          icon: Icons.warning_amber_outlined,
          valueColor: _impactTextColor(incident.impact),
          bgColor: _impactBgColor(incident.impact),
        ),
        // Status
        _buildStatCard(
          label: 'Status',
          value: incident.status?.label ?? 'Unknown',
          icon: Icons.info_outline,
          valueColor: _statusTextColor(incident.status),
          bgColor: _statusBgColor(incident.status),
        ),
        // Duration
        _buildStatCard(
          label: 'Duration',
          value: _formatDuration(incident),
          icon: Icons.timer_outlined,
        ),
        // Updates
        _buildStatCard(
          label: 'Updates',
          value: '${incident.updates.length}',
          icon: Icons.history,
        ),
      ],
    );
  }

  Widget _buildStatCard({
    required String label,
    required String value,
    required IconData icon,
    String? valueColor,
    String? bgColor,
  }) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-4
      ''',
      children: [
        WDiv(
          className: 'flex flex-row items-center gap-2 mb-3',
          children: [
            WDiv(
              className:
                  'p-2 rounded-lg ${bgColor ?? 'bg-gray-100 dark:bg-gray-700'}',
              child: WIcon(
                icon,
                className:
                    'text-base ${valueColor ?? 'text-gray-600 dark:text-gray-400'}',
              ),
            ),
          ],
        ),
        WText(
          label.toUpperCase(),
          className:
              'text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1',
        ),
        WText(
          value,
          className:
              'text-lg font-semibold ${valueColor ?? 'text-gray-900 dark:text-white'}',
        ),
      ],
    );
  }

  Widget _buildAffectedMonitors(Incident incident) {
    if (incident.monitors == null || incident.monitors!.isEmpty) {
      return const SizedBox.shrink();
    }

    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WDiv(
                className: 'p-2 rounded-lg bg-orange-50 dark:bg-orange-900/20',
                child: WIcon(
                  Icons.monitor_heart_outlined,
                  className: 'text-orange-600 dark:text-orange-400 text-lg',
                ),
              ),
              const WSpacer(className: 'w-3'),
              WText(
                'AFFECTED MONITORS',
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),
        // Monitors list
        WDiv(
          className: 'p-5',
          child: WDiv(
            className: 'wrap gap-2',
            children: incident.monitors!.map((monitor) {
              return WDiv(
                className: '''
                  px-3 py-2 rounded-lg
                  bg-gray-50 dark:bg-gray-700
                  border border-gray-200 dark:border-gray-600
                ''',
                children: [
                  WText(
                    monitor.name ?? 'Unknown',
                    className:
                        'text-sm font-medium text-gray-900 dark:text-white',
                  ),
                ],
              );
            }).toList(),
          ),
        ),
      ],
    );
  }

  Widget _buildTimeline(Incident incident) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Header
        WDiv(
          className: 'p-4 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WDiv(
                className: 'p-2 rounded-lg bg-primary/10',
                child: WIcon(Icons.timeline, className: 'text-primary text-lg'),
              ),
              const WSpacer(className: 'w-3'),
              WText(
                trans('incidents.timeline'),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),
        // Timeline entries (Claude style)
        if (incident.updates.isEmpty)
          WDiv(
            className: 'p-12 flex flex-col items-center justify-center',
            children: [
              WIcon(
                Icons.history,
                className: 'text-4xl text-gray-300 dark:text-gray-600 mb-3',
              ),
              WText(
                trans('incidents.no_updates'),
                className: 'text-gray-500 dark:text-gray-400',
              ),
            ],
          )
        else
          WDiv(
            className: 'p-4',
            child: WDiv(
              className: 'flex flex-col gap-4',
              children: incident.updates.map((update) {
                return WDiv(
                  className: 'flex flex-col',
                  children: [
                    // Status - Message (Claude style)
                    WDiv(
                      className: 'wrap gap-1',
                      children: [
                        WText(
                          update.status?.label ?? 'Update',
                          className:
                              'text-sm font-bold ${_statusTextColorForTimeline(update.status)}',
                        ),
                        if (update.message != null &&
                            update.message!.isNotEmpty)
                          WText(
                            ' - ${update.message}',
                            className:
                                'text-sm text-gray-700 dark:text-gray-300',
                          ),
                      ],
                    ),
                    // Timestamp
                    if (update.createdAt != null)
                      WText(
                        update.createdAt!.format('MMM d, HH:mm'),
                        className:
                            'text-xs text-gray-500 dark:text-gray-400 mt-1',
                      ),
                  ],
                );
              }).toList(),
            ),
          ),
      ],
    );
  }

  String _statusTextColorForTimeline(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'text-gray-700 dark:text-gray-300';
      case IncidentStatus.identified:
        return 'text-orange-600 dark:text-orange-400';
      case IncidentStatus.monitoring:
        return 'text-blue-600 dark:text-blue-400';
      case IncidentStatus.resolved:
        return 'text-green-600 dark:text-green-400';
      default:
        return 'text-gray-700 dark:text-gray-300';
    }
  }

  Widget _buildAddUpdateSection(Incident incident) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WDiv(
                className: 'p-2 rounded-lg bg-blue-50 dark:bg-blue-900/20',
                child: WIcon(
                  Icons.add_comment_outlined,
                  className: 'text-blue-600 dark:text-blue-400 text-lg',
                ),
              ),
              const WSpacer(className: 'w-3'),
              WText(
                'ADD UPDATE',
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),
        // Form
        WDiv(
          className: 'p-5',
          child: MagicForm(
            formData: _updateForm,
            child: WDiv(
              className: 'flex flex-col gap-4',
              children: [
                // Status
                WDiv(
                  className: 'flex flex-col gap-2',
                  children: [
                    WText(
                      'Status',
                      className:
                          'text-sm font-medium text-gray-700 dark:text-gray-300',
                    ),
                    WSelect<IncidentStatus>(
                      value: _updateStatus,
                      options: IncidentStatus.selectOptions,
                      onChange: (status) {
                        if (status != null) {
                          setState(() => _updateStatus = status);
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

                // Title (optional)
                WFormInput(
                  controller: _updateForm['title'],
                  label: 'Update Title (Optional)',
                  hint: 'Brief summary of this update',
                  className: '''
                    w-full px-3 py-3 rounded-lg text-sm
                    bg-white dark:bg-gray-900
                    text-gray-900 dark:text-white
                    border border-gray-200 dark:border-gray-700
                    focus:border-primary focus:ring-2 focus:ring-primary/20
                  ''',
                  labelClassName:
                      'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2',
                ),

                // Message
                WFormInput(
                  controller: _updateForm['message'],
                  label: 'Message',
                  hint: 'Describe the current situation...',
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
                  ], field: 'message'),
                ),

                // Submit button
                WDiv(
                  className: 'flex flex-row justify-end',
                  child: WButton(
                    isLoading: _isSubmitting,
                    onTap: _handleAddUpdate,
                    className: '''
                      px-4 py-2.5 rounded-lg
                      bg-primary hover:bg-green-600
                      text-white
                      text-sm font-medium
                    ''',
                    child: WDiv(
                      className: 'flex flex-row items-center gap-2',
                      children: [
                        WIcon(Icons.send_outlined, className: 'text-base'),
                        WText('Post Update'),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  String _formatDuration(Incident incident) {
    if (incident.duration == null) {
      if (incident.startedAt != null) {
        final now = DateTime.now();
        final startedDateTime = incident.startedAt!.toDateTime;
        final diff = now.difference(startedDateTime);
        if (diff.inMinutes < 60) {
          return '${diff.inMinutes}m';
        } else if (diff.inHours < 24) {
          return '${diff.inHours}h ${diff.inMinutes % 60}m';
        } else {
          return '${diff.inDays}d ${diff.inHours % 24}h';
        }
      }
      return 'Ongoing';
    }
    final duration = incident.duration!;
    if (duration.inMinutes < 60) {
      return '${duration.inMinutes}m';
    } else if (duration.inHours < 24) {
      return '${duration.inHours}h ${duration.inMinutes % 60}m';
    } else {
      return '${duration.inDays}d ${duration.inHours % 24}h';
    }
  }

  String _impactTextColor(IncidentImpact? impact) {
    switch (impact) {
      case IncidentImpact.majorOutage:
        return 'text-red-600 dark:text-red-400';
      case IncidentImpact.partialOutage:
        return 'text-orange-600 dark:text-orange-400';
      case IncidentImpact.degradedPerformance:
        return 'text-yellow-600 dark:text-yellow-400';
      case IncidentImpact.underMaintenance:
        return 'text-blue-600 dark:text-blue-400';
      default:
        return 'text-gray-600 dark:text-gray-400';
    }
  }

  String _impactBgColor(IncidentImpact? impact) {
    switch (impact) {
      case IncidentImpact.majorOutage:
        return 'bg-red-50 dark:bg-red-900/20';
      case IncidentImpact.partialOutage:
        return 'bg-orange-50 dark:bg-orange-900/20';
      case IncidentImpact.degradedPerformance:
        return 'bg-yellow-50 dark:bg-yellow-900/20';
      case IncidentImpact.underMaintenance:
        return 'bg-blue-50 dark:bg-blue-900/20';
      default:
        return 'bg-gray-100 dark:bg-gray-700';
    }
  }

  String _statusTextColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'text-gray-600 dark:text-gray-400';
      case IncidentStatus.identified:
        return 'text-orange-600 dark:text-orange-400';
      case IncidentStatus.monitoring:
        return 'text-blue-600 dark:text-blue-400';
      case IncidentStatus.resolved:
        return 'text-green-600 dark:text-green-400';
      default:
        return 'text-gray-600 dark:text-gray-400';
    }
  }

  String _statusBgColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'bg-gray-100 dark:bg-gray-700';
      case IncidentStatus.identified:
        return 'bg-orange-50 dark:bg-orange-900/20';
      case IncidentStatus.monitoring:
        return 'bg-blue-50 dark:bg-blue-900/20';
      case IncidentStatus.resolved:
        return 'bg-green-50 dark:bg-green-900/20';
      default:
        return 'bg-gray-100 dark:bg-gray-700';
    }
  }

  String _statusDotColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'bg-gray-400 dark:bg-gray-500';
      case IncidentStatus.identified:
        return 'bg-orange-500';
      case IncidentStatus.monitoring:
        return 'bg-blue-500';
      case IncidentStatus.resolved:
        return 'bg-green-500';
      default:
        return 'bg-gray-400';
    }
  }

  String _statusBadgeColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
      case IncidentStatus.identified:
        return 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300';
      case IncidentStatus.monitoring:
        return 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300';
      case IncidentStatus.resolved:
        return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300';
      default:
        return 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
    }
  }
}
