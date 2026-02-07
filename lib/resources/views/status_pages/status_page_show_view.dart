import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/controllers/incident_controller.dart';
import '../../../app/models/incident.dart';
import '../../../app/models/announcement.dart';
import '../../../app/models/status_page.dart';
import '../../../app/enums/incident_status.dart';
import '../../../app/enums/incident_impact.dart';
import '../components/app_card.dart';
import '../components/app_page_header.dart';

class StatusPageShowView extends MagicStatefulView<StatusPageController> {
  const StatusPageShowView({super.key});

  @override
  State<StatusPageShowView> createState() => _StatusPageShowViewState();
}

class _StatusPageShowViewState
    extends MagicStatefulViewState<StatusPageController, StatusPageShowView> {
  String? _statusPageId;
  final _announcementsNotifier = ValueNotifier<List<Announcement>>([]);

  @override
  void onInit() {
    super.onInit();
    final idParam = MagicRouter.instance.pathParameter('id');
    if (idParam != null) {
      _statusPageId = idParam;
      WidgetsBinding.instance.addPostFrameCallback((_) async {
        controller.selectedStatusPageNotifier.value = null;
        await controller.loadStatusPage(_statusPageId!);
        IncidentController.instance.loadIncidents();
        _loadAnnouncements();
      });
    }
  }

  @override
  void dispose() {
    _announcementsNotifier.dispose();
    super.dispose();
  }

  Future<void> _loadAnnouncements() async {
    try {
      final announcements = await Announcement.all();
      _announcementsNotifier.value = announcements;
    } catch (e) {
      Log.error('Failed to load announcements', e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<StatusPage?>(
      valueListenable: controller.selectedStatusPageNotifier,
      builder: (context, statusPage, _) {
        // Loading state
        if (controller.isLoading && statusPage == null) {
          return WDiv(
            className: 'py-12 flex items-center justify-center',
            child: const CircularProgressIndicator(),
          );
        }

        // Not found state
        if (statusPage == null) {
          return WDiv(
            className: 'py-12 flex items-center justify-center',
            child: WText(
              trans('status_pages.not_found'),
              className: 'text-gray-500 dark:text-gray-400',
            ),
          );
        }

        return WDiv(
          className: 'overflow-y-auto flex flex-col gap-4 lg:gap-6 pb-4',
          scrollPrimary: true,
          children: [
            _buildHeader(statusPage),
            WDiv(
              className: 'flex flex-col px-4 lg:px-6 gap-4 lg:gap-6',
              children: [
                _buildInfoCard(statusPage),
                _buildMonitorsCard(statusPage),
                _buildActiveIncidents(statusPage),
                _buildAnnouncements(statusPage),
                _buildIncidentHistory(statusPage),
                _buildStatusCard(statusPage),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _buildHeader(StatusPage statusPage) {
    return AppPageHeader(
      leading: WButton(
        onTap: () => MagicRoute.to('/status-pages'),
        className: 'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
        child: WIcon(
          Icons.arrow_back,
          className: 'text-xl text-gray-700 dark:text-gray-300',
        ),
      ),
      title: statusPage.name,
      subtitle: statusPage.publicUrl,
      actions: [
        // Edit button
        WButton(
          onTap: () => MagicRoute.to('/status-pages/$_statusPageId/edit'),
          className:
              'px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-medium',
          child: WDiv(
            className: 'flex flex-row items-center sm:gap-2',
            children: [
              WIcon(Icons.edit_outlined, className: 'text-base'),
              WText(trans('common.edit'), className: 'hidden sm:block'),
            ],
          ),
        ),
        // More actions popover
        WPopover(
          alignment: PopoverAlignment.bottomRight,
          className: '''
            w-56
            bg-white dark:bg-gray-800
            border border-gray-100 dark:border-gray-700
            rounded-xl shadow-xl
            z-50
          ''',
          triggerBuilder: (context, isOpen, isHovering) {
            return WButton(
              className:
                  '''
                px-3 py-2 rounded-lg
                bg-gray-100 dark:bg-gray-700
                text-gray-700 dark:text-gray-200
                hover:bg-gray-200 dark:hover:bg-gray-600
                text-sm font-medium
                ${isOpen ? 'bg-gray-200 dark:bg-gray-600' : ''}
              ''',
              child: WIcon(Icons.more_vert, className: 'text-xl'),
            );
          },
          contentBuilder: (context, close) {
            return WDiv(
              className: 'flex flex-col py-1',
              children: [
                // Open public page
                _buildPopoverItem(
                  icon: Icons.open_in_new,
                  label: trans('status_pages.open_public_page'),
                  onTap: () async {
                    close();
                    final url = Uri.parse(statusPage.publicUrl);
                    if (await canLaunchUrl(url)) {
                      await launchUrl(
                        url,
                        mode: LaunchMode.externalApplication,
                      );
                    }
                  },
                ),
                // Publish/Unpublish toggle
                _buildPopoverItem(
                  icon: statusPage.isPublished
                      ? Icons.unpublished_outlined
                      : Icons.publish_outlined,
                  label: statusPage.isPublished
                      ? trans('status_pages.unpublish')
                      : trans('status_pages.publish'),
                  onTap: () async {
                    close();
                    await controller.togglePublish(_statusPageId!);
                  },
                ),
                // Divider
                WDiv(className: 'h-px bg-gray-100 dark:bg-gray-700 my-1'),
                // Delete
                _buildPopoverItem(
                  icon: Icons.delete_outline,
                  label: trans('common.delete'),
                  isDestructive: true,
                  onTap: () {
                    close();
                    controller.destroy(_statusPageId!);
                  },
                ),
              ],
            );
          },
        ),
      ],
    );
  }

  Widget _buildPopoverItem({
    required IconData icon,
    required String label,
    required VoidCallback onTap,
    bool isDestructive = false,
  }) {
    return WButton(
      onTap: onTap,
      className:
          '''
        w-full px-4 py-2 text-left flex flex-row items-center gap-2
        hover:bg-gray-50 dark:hover:bg-gray-700/50
        ${isDestructive ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-200'}
      ''',
      child: WDiv(
        className: 'flex flex-row items-center gap-2',
        children: [
          WIcon(icon, className: 'text-lg'),
          WText(label, className: 'text-sm'),
        ],
      ),
    );
  }

  Widget _buildInfoCard(StatusPage statusPage) {
    return AppCard(
      title: trans('status_pages.info'),
      icon: Icons.info_outline,
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Description
          if (statusPage.description != null &&
              statusPage.description!.isNotEmpty) ...[
            WText(
              trans('status_pages.description'),
              className:
                  'text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1',
            ),
            WText(
              statusPage.description!,
              className: 'text-sm text-gray-700 dark:text-gray-300 mb-4',
            ),
          ],
          // Slug
          WText(
            trans('status_pages.slug'),
            className:
                'text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1',
          ),
          WText(
            statusPage.slug,
            className:
                'text-sm font-mono text-gray-700 dark:text-gray-300 mb-4',
          ),
          // Primary color
          WText(
            trans('status_pages.primary_color'),
            className:
                'text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1',
          ),
          Row(
            children: [
              Container(
                width: 24,
                height: 24,
                decoration: BoxDecoration(
                  color: _parseColor(statusPage.primaryColor),
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: Colors.grey.shade300, width: 1),
                ),
              ),
              const SizedBox(width: 8),
              WText(
                statusPage.primaryColor,
                className: 'text-sm font-mono text-gray-700 dark:text-gray-300',
              ),
            ],
          ),
          // Logo preview
          if (statusPage.logoUrl != null && statusPage.logoUrl!.isNotEmpty) ...[
            const SizedBox(height: 16),
            WText(
              trans('status_pages.logo'),
              className:
                  'text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2',
            ),
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.network(
                statusPage.logoUrl!,
                height: 48,
                fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => WDiv(
                  className:
                      'w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center',
                  child: WIcon(
                    Icons.image_not_supported_outlined,
                    className: 'text-gray-400',
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildMonitorsCard(StatusPage statusPage) {
    final monitors = statusPage.monitors;

    return AppCard(
      title: trans('status_pages.monitors'),
      icon: Icons.monitor_heart_outlined,
      headerActions: [
        WDiv(
          className: 'px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded-full',
          child: WText(
            '${monitors.length}',
            className: 'text-xs font-medium text-gray-600 dark:text-gray-300',
          ),
        ),
      ],
      body: monitors.isEmpty
          ? WText(
              trans('status_pages.no_monitors'),
              className: 'text-sm text-gray-500 dark:text-gray-400',
            )
          : WDiv(
              className: 'divide-y divide-gray-100 dark:divide-gray-700',
              children: monitors
                  .map(
                    (monitor) => WDiv(
                      className:
                          'py-3 flex flex-row items-center justify-between',
                      children: [
                        WDiv(
                          className: 'flex flex-row items-center gap-3',
                          children: [
                            WDiv(
                              className:
                                  'w-2 h-2 rounded-full ${_getStatusColor(monitor.lastStatus?.value)}',
                            ),
                            WText(
                              monitor.name ?? trans('monitors.unnamed'),
                              className:
                                  'text-sm font-medium text-gray-900 dark:text-white',
                            ),
                          ],
                        ),
                        WText(
                          _getStatusLabel(monitor.lastStatus?.value),
                          className:
                              'text-xs font-medium ${_getStatusTextColor(monitor.lastStatus?.value)}',
                        ),
                      ],
                    ),
                  )
                  .toList(),
            ),
    );
  }

  Widget _buildStatusCard(StatusPage statusPage) {
    return AppCard(
      title: trans('status_pages.overview'),
      body: Row(
        children: [
          // Published status
          Expanded(
            child: WDiv(
              className: 'flex flex-col items-center',
              children: [
                WText(
                  trans('status_pages.published_status'),
                  className:
                      'text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2',
                ),
                WDiv(
                  className:
                      'px-3 py-1 rounded-full ${statusPage.isPublished ? 'bg-green-100 dark:bg-green-900/30' : 'bg-gray-100 dark:bg-gray-700'}',
                  child: WText(
                    statusPage.isPublished
                        ? trans('status_pages.published')
                        : trans('status_pages.draft'),
                    className:
                        'text-sm font-medium ${statusPage.isPublished ? 'text-green-700 dark:text-green-400' : 'text-gray-600 dark:text-gray-400'}',
                  ),
                ),
              ],
            ),
          ),
          // Divider
          WDiv(className: 'w-px h-12 bg-gray-200 dark:bg-gray-700'),
          // Monitor count
          Expanded(
            child: WDiv(
              className: 'flex flex-col items-center',
              children: [
                WText(
                  trans('status_pages.monitor_count'),
                  className:
                      'text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2',
                ),
                WText(
                  '${statusPage.monitors.length}',
                  className: 'text-2xl font-bold text-gray-900 dark:text-white',
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Color _parseColor(String hexColor) {
    try {
      final hex = hexColor.replaceFirst('#', '');
      return Color(int.parse('FF$hex', radix: 16));
    } catch (_) {
      return Colors.grey;
    }
  }

  // CheckStatus values: 'up', 'down', 'degraded'
  String _getStatusColor(String? status) {
    switch (status) {
      case 'up':
        return 'bg-green-500';
      case 'degraded':
        return 'bg-yellow-500';
      case 'down':
        return 'bg-red-500';
      default:
        return 'bg-gray-400';
    }
  }

  String _getStatusTextColor(String? status) {
    switch (status) {
      case 'up':
        return 'text-green-600 dark:text-green-400';
      case 'degraded':
        return 'text-yellow-600 dark:text-yellow-400';
      case 'down':
        return 'text-red-600 dark:text-red-400';
      default:
        return 'text-gray-500 dark:text-gray-400';
    }
  }

  Widget _buildActiveIncidents(StatusPage statusPage) {
    return ValueListenableBuilder<List<Incident>>(
      valueListenable: IncidentController.instance.incidentsNotifier,
      builder: (context, allIncidents, _) {
        final activeIncidents = allIncidents.where((incident) {
          if (incident.status == IncidentStatus.resolved) return false;
          final pageMonitorIds = statusPage.monitorIds;
          final incidentMonitorIds = incident.monitorIds;
          return incidentMonitorIds.any((id) => pageMonitorIds.contains(id));
        }).toList();

        if (activeIncidents.isEmpty) return const SizedBox.shrink();

        return AppCard(
          title: trans('status_pages.active_incidents'),
          icon: Icons.warning_amber_rounded,
          titleClassName: 'text-red-600 dark:text-red-400',
          body: WDiv(
            className: 'flex flex-col gap-4',
            children: activeIncidents
                .map((i) => _buildIncidentItem(i))
                .toList(),
          ),
        );
      },
    );
  }

  Widget _buildAnnouncements(StatusPage statusPage) {
    return ValueListenableBuilder<List<Announcement>>(
      valueListenable: _announcementsNotifier,
      builder: (context, allAnnouncements, _) {
        final announcements = allAnnouncements.where((a) {
          return a.statusPageId == statusPage.id && a.isActive;
        }).toList();

        if (announcements.isEmpty) return const SizedBox.shrink();

        return AppCard(
          title: trans('status_pages.announcements'),
          icon: Icons.campaign_outlined,
          titleClassName: 'text-blue-600 dark:text-blue-400',
          body: WDiv(
            className: 'flex flex-col gap-4',
            children: announcements.map((a) {
              return WDiv(
                className:
                    'p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-100 dark:border-blue-900/30',
                children: [
                  WText(
                    a.title ?? '',
                    className:
                        'text-base font-bold text-blue-900 dark:text-blue-100 mb-1',
                  ),
                  if (a.body != null)
                    WText(
                      a.body!,
                      className: 'text-sm text-blue-800 dark:text-blue-200',
                    ),
                  WDiv(
                    className: 'mt-2 flex items-center gap-2',
                    children: [
                      WIcon(
                        Icons.schedule,
                        className: 'text-xs text-blue-600 dark:text-blue-400',
                      ),
                      WText(
                        DateFormat.yMMMd().format(
                          DateTime.parse(a.publishedAt.toString()),
                        ),
                        className:
                            'text-xs font-medium text-blue-600 dark:text-blue-400',
                      ),
                    ],
                  ),
                ],
              );
            }).toList(),
          ),
        );
      },
    );
  }

  Widget _buildIncidentHistory(StatusPage statusPage) {
    return AppCard(
      title: trans('status_pages.incident_history'),
      icon: Icons.history,
      body: ValueListenableBuilder<List<Incident>>(
        valueListenable: IncidentController.instance.incidentsNotifier,
        builder: (context, allIncidents, _) {
          // Get last 15 days
          final now = DateTime.now();
          final historyDays = List.generate(15, (index) {
            return now.subtract(Duration(days: index));
          });

          // Filter incidents for this page
          final pageMonitorIds = statusPage.monitorIds;
          final relevantIncidents = allIncidents.where((incident) {
            final incidentMonitorIds = incident.monitorIds;
            return incidentMonitorIds.any((id) => pageMonitorIds.contains(id));
          }).toList();

          return WDiv(
            className: 'flex flex-col gap-4',
            children: historyDays.map((date) {
              final incidentsForDay = relevantIncidents.where((i) {
                if (i.startedAt == null) return false;
                final start = DateTime.parse(i.startedAt.toString());
                return start.year == date.year &&
                    start.month == date.month &&
                    start.day == date.day;
              }).toList();

              return WDiv(
                className: 'flex flex-row items-start gap-4',
                children: [
                  // Date
                  WDiv(
                    className: 'w-24 flex-shrink-0 pt-1',
                    child: WText(
                      DateFormat.MMMd().format(date),
                      className: 'text-sm font-medium text-gray-500',
                    ),
                  ),
                  // Timeline line
                  WDiv(
                    className: 'flex flex-col items-center self-stretch',
                    children: [
                      WDiv(
                        className:
                            'w-3 h-3 rounded-full ${incidentsForDay.isEmpty ? 'bg-green-500' : 'bg-orange-500'}',
                      ),
                      if (date != historyDays.last)
                        Expanded(
                          child: WDiv(
                            className:
                                'w-0.5 bg-gray-100 dark:bg-gray-700 my-1',
                          ),
                        ),
                    ],
                  ),
                  // Content
                  Expanded(
                    child: incidentsForDay.isEmpty
                        ? WText(
                            trans('status_pages.no_incidents'),
                            className: 'text-sm text-gray-400 py-0.5',
                          )
                        : WDiv(
                            className: 'flex flex-col gap-3 pb-4',
                            children: incidentsForDay
                                .map(
                                  (i) => _buildIncidentItem(i, minimal: true),
                                )
                                .toList(),
                          ),
                  ),
                ],
              );
            }).toList(),
          );
        },
      ),
    );
  }

  Widget _buildIncidentItem(Incident incident, {bool minimal = false}) {
    return WDiv(
      className:
          'p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl border border-gray-100 dark:border-gray-700',
      children: [
        WDiv(
          className: 'flex flex-row items-start justify-between gap-4',
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  WText(
                    incident.title ?? trans('incidents.untitled'),
                    className:
                        'text-base font-bold text-gray-900 dark:text-white',
                  ),
                  const SizedBox(height: 4),
                  WDiv(
                    className: 'flex flex-row items-center gap-2',
                    children: [
                      _buildBadge(
                        label: _getIncidentStatusLabel(incident.status),
                        color: _statusBadgeColor(incident.status),
                      ),
                      if (incident.impact != null)
                        _buildBadge(
                          label: _getIncidentImpactLabel(incident.impact),
                          color: _impactBadgeColor(incident.impact),
                        ),
                    ],
                  ),
                ],
              ),
            ),
            if (incident.startedAt != null)
              WText(
                DateFormat.MMMd().add_jm().format(
                  DateTime.parse(incident.startedAt.toString()),
                ),
                className: 'text-xs text-gray-500 font-medium',
              ),
          ],
        ),
        if (!minimal && incident.updates.isNotEmpty) ...[
          const SizedBox(height: 12),
          WDiv(className: 'h-px bg-gray-200 dark:bg-gray-600 mb-3'),
          WText(
            trans('incidents.latest_update'),
            className:
                'text-xs font-bold uppercase tracking-wide text-gray-500 mb-2',
          ),
          WText(
            incident.updates.first.message ?? '',
            className: 'text-sm text-gray-700 dark:text-gray-300 line-clamp-2',
          ),
        ],
      ],
    );
  }

  Widget _buildBadge({required String label, required String color}) {
    return WDiv(
      className: 'px-2 py-0.5 rounded text-xs font-medium $color',
      child: WText(label),
    );
  }

  String _impactBadgeColor(IncidentImpact? impact) {
    switch (impact) {
      case IncidentImpact.majorOutage:
        return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
      case IncidentImpact.partialOutage:
        return 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400';
      case IncidentImpact.degradedPerformance:
        return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400';
      case IncidentImpact.underMaintenance:
        return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400';
      default:
        return 'bg-gray-100 text-gray-700';
    }
  }

  String _statusBadgeColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
      case IncidentStatus.identified:
        return 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400';
      case IncidentStatus.monitoring:
        return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400';
      case IncidentStatus.resolved:
        return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400';
      default:
        return 'bg-gray-100 text-gray-700';
    }
  }

  String _getIncidentStatusLabel(IncidentStatus? status) {
    return status?.label ?? trans('common.unknown');
  }

  String _getIncidentImpactLabel(IncidentImpact? impact) {
    return impact?.label ?? trans('common.unknown');
  }

  String _getStatusLabel(String? status) {
    switch (status) {
      case 'up':
        return trans('check_status.up');
      case 'degraded':
        return trans('check_status.degraded');
      case 'down':
        return trans('check_status.down');
      default:
        return trans('monitor_status.unknown');
    }
  }
}
