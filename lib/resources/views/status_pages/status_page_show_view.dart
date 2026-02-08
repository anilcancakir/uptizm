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
    if (_statusPageId == null) return;
    try {
      final announcements = await Announcement.allForStatusPage(_statusPageId!);
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
                // Reactive conditional sections (incidents + announcements)
                _buildReactiveConditionalSections(statusPage),
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
          className: '''
            w-10 h-10 sm:w-auto sm:h-auto sm:px-3 sm:py-2 rounded-lg
            bg-gray-100 dark:bg-gray-700
            text-gray-700 dark:text-gray-200
            hover:bg-gray-200 dark:hover:bg-gray-600
            text-sm font-medium
            flex items-center justify-center
          ''',
          child: WDiv(
            className: 'flex flex-row items-center sm:gap-2',
            children: [
              WIcon(Icons.edit_outlined, className: 'text-lg'),
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
                w-10 h-10 sm:w-auto sm:h-auto sm:px-3 sm:py-2 rounded-lg
                bg-gray-100 dark:bg-gray-700
                text-gray-700 dark:text-gray-200
                hover:bg-gray-200 dark:hover:bg-gray-600
                text-sm font-medium
                flex items-center justify-center
                ${isOpen ? 'bg-gray-200 dark:bg-gray-600' : ''}
              ''',
              child: WIcon(Icons.more_vert, className: 'text-lg'),
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
                errorBuilder: (context, error, stackTrace) => WDiv(
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

  Widget _buildIncidentHistory(StatusPage statusPage) {
    return AppCard(
      title: trans('status_pages.incident_history'),
      icon: Icons.history,
      body: ValueListenableBuilder<List<Incident>>(
        valueListenable: IncidentController.instance.incidentsNotifier,
        builder: (context, allIncidents, _) {
          // Filter incidents for this page
          final pageMonitorIds = statusPage.monitorIds;
          final relevantIncidents = allIncidents.where((incident) {
            final incidentMonitorIds = incident.monitorIds;
            return incidentMonitorIds.any((id) => pageMonitorIds.contains(id));
          }).toList();

          if (relevantIncidents.isEmpty) {
            return WDiv(
              className: 'py-8 flex flex-col items-center justify-center',
              children: [
                WIcon(
                  Icons.check_circle_outline,
                  className: 'text-4xl text-green-500 mb-2',
                ),
                WText(
                  trans('status_pages.no_past_incidents'),
                  className: 'text-gray-500 dark:text-gray-400',
                ),
              ],
            );
          }

          // Group incidents by date
          final groupedByDate = <String, List<Incident>>{};
          for (final incident in relevantIncidents) {
            if (incident.startedAt == null) continue;
            final date = DateTime.parse(incident.startedAt.toString());
            final dateKey = DateFormat('MMM d, yyyy').format(date);
            groupedByDate.putIfAbsent(dateKey, () => []);
            groupedByDate[dateKey]!.add(incident);
          }

          return WDiv(
            className: 'flex flex-col gap-6',
            children: groupedByDate.entries.map((entry) {
              return WDiv(
                className: 'flex flex-col gap-4',
                children: [
                  // Date header (Claude style)
                  WText(
                    entry.key,
                    className:
                        'text-base font-semibold text-gray-900 dark:text-white',
                  ),
                  // Incidents for this date
                  ...entry.value.map(
                    (incident) => _buildClaudeStyleIncident(incident),
                  ),
                ],
              );
            }).toList(),
          );
        },
      ),
    );
  }

  /// Claude-style incident card with inline updates
  Widget _buildClaudeStyleIncident(Incident incident) {
    return WDiv(
      className:
          'p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl border border-gray-100 dark:border-gray-700',
      children: [
        // Header: Title + Status badge
        WDiv(
          className: 'flex flex-row items-start justify-between gap-3 mb-3',
          children: [
            // Title in amber/orange color (Claude style)
            WDiv(
              className: 'flex-1 min-w-0',
              child: WText(
                incident.title ?? trans('incidents.untitled'),
                className:
                    'text-base font-semibold text-amber-600 dark:text-amber-400',
              ),
            ),
            // Status badge
            WDiv(
              className:
                  'px-2.5 py-1 rounded-full text-xs font-medium border ${_statusOutlineBadgeColor(incident.status)}',
              child: WText(_getIncidentStatusLabel(incident.status)),
            ),
          ],
        ),
        // Updates list (Claude style: Status - Message format)
        if (incident.updates.isNotEmpty)
          WDiv(
            className: 'flex flex-col gap-2',
            children: incident.updates.map((update) {
              return WDiv(
                className: 'flex flex-col',
                children: [
                  // Status - Message
                  WDiv(
                    className: 'wrap gap-1',
                    children: [
                      WText(
                        update.status?.label ?? 'Update',
                        className:
                            'text-sm font-semibold ${_statusTextColor(update.status)}',
                      ),
                      if (update.message != null && update.message!.isNotEmpty)
                        WText(
                          ' - ${update.message}',
                          className: 'text-sm text-gray-700 dark:text-gray-300',
                        ),
                    ],
                  ),
                  // Timestamp
                  if (update.createdAt != null)
                    WText(
                      update.createdAt!.format('MMM d, HH:mm'),
                      className:
                          'text-xs text-gray-500 dark:text-gray-400 mt-0.5',
                    ),
                ],
              );
            }).toList(),
          ),
      ],
    );
  }

  String _statusOutlineBadgeColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400';
      case IncidentStatus.identified:
        return 'border-orange-300 dark:border-orange-700 text-orange-600 dark:text-orange-400';
      case IncidentStatus.monitoring:
        return 'border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400';
      case IncidentStatus.resolved:
        return 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400';
      default:
        return 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400';
    }
  }

  String _statusTextColor(IncidentStatus? status) {
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
                    className: 'wrap gap-2',
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

  /// Build reactive conditional sections (incidents + announcements)
  /// Uses ValueListenableBuilder to react to async data loads
  Widget _buildReactiveConditionalSections(StatusPage statusPage) {
    return ValueListenableBuilder<List<Incident>>(
      valueListenable: IncidentController.instance.incidentsNotifier,
      builder: (context, allIncidents, _) {
        return ValueListenableBuilder<List<Announcement>>(
          valueListenable: _announcementsNotifier,
          builder: (context, allAnnouncements, _) {
            // Filter active incidents for this status page
            final activeIncidents = allIncidents.where((incident) {
              if (incident.status == IncidentStatus.resolved) return false;
              final pageMonitorIds = statusPage.monitorIds;
              final incidentMonitorIds = incident.monitorIds;
              return incidentMonitorIds.any(
                (id) => pageMonitorIds.contains(id),
              );
            }).toList();

            // Filter active announcements for this status page
            final announcements = allAnnouncements.where((a) {
              return a.statusPageId == statusPage.id && a.isActive;
            }).toList();

            // Build sections list
            final sections = <Widget>[];

            if (activeIncidents.isNotEmpty) {
              sections.add(_buildActiveIncidentsCard(activeIncidents));
            }

            // Always show announcements card (with empty state if needed)
            sections.add(_buildAnnouncementsCard(announcements));

            // Return empty widget if no sections (takes no space in gap layout)
            if (sections.isEmpty) {
              return const SizedBox.shrink();
            }

            // Wrap in column with gap when multiple sections
            return WDiv(
              className: 'flex flex-col gap-4 lg:gap-6',
              children: sections,
            );
          },
        );
      },
    );
  }

  Widget _buildActiveIncidentsCard(List<Incident> activeIncidents) {
    return AppCard(
      title: trans('status_pages.active_incidents'),
      icon: Icons.warning_amber_rounded,
      titleClassName: 'text-red-600 dark:text-red-400',
      body: WDiv(
        className: 'flex flex-col gap-4',
        children: activeIncidents.map((i) => _buildIncidentItem(i)).toList(),
      ),
    );
  }

  Widget _buildAnnouncementsCard(List<Announcement> announcements) {
    return AppCard(
      title: trans('status_pages.announcements'),
      icon: Icons.campaign_outlined,
      titleClassName: 'text-blue-600 dark:text-blue-400',
      headerActions: [
        WButton(
          onTap: () => MagicRoute.to(
            '/status-pages/$_statusPageId/announcements/create',
          ),
          className:
              'p-1 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg',
          child: WIcon(
            Icons.add_outlined,
            className: 'text-lg text-blue-600 dark:text-blue-400',
          ),
        ),
      ],
      footer: WButton(
        onTap: () =>
            MagicRoute.to('/status-pages/$_statusPageId/announcements'),
        className:
            'w-full py-3 flex items-center justify-center hover:bg-gray-50 dark:hover:bg-gray-700/50 rounded-b-2xl transition-colors',
        child: WText(
          trans('common.view_all'),
          className: 'text-sm font-medium text-gray-600 dark:text-gray-400',
        ),
      ),
      body: announcements.isEmpty
          ? WDiv(
              className: 'py-8 flex flex-col items-center justify-center gap-3',
              children: [
                WIcon(
                  Icons.campaign_outlined,
                  className: 'text-4xl text-gray-300 dark:text-gray-600',
                ),
                WText(
                  trans('announcements.no_announcements'),
                  className: 'text-sm text-gray-500 dark:text-gray-400',
                ),
                WButton(
                  onTap: () => MagicRoute.to(
                    '/status-pages/$_statusPageId/announcements/create',
                  ),
                  className:
                      'mt-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg',
                  child: WText(trans('announcements.create_first')),
                ),
              ],
            )
          : WDiv(
              className: 'flex flex-col gap-4',
              children: announcements.map((a) {
                return WAnchor(
                  onTap: () => MagicRoute.to(
                    '/status-pages/$_statusPageId/announcements/${a.id}',
                  ),
                  child: WDiv(
                    className:
                        'p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-100 dark:border-blue-900/30 hover:border-blue-300 dark:hover:border-blue-700 transition-colors',
                    children: [
                      WText(
                        a.title ?? '',
                        className:
                            'text-base font-bold text-blue-900 dark:text-blue-100 mb-1',
                      ),
                      if (a.body != null)
                        WText(
                          a.body!,
                          className:
                              'text-sm text-blue-800 dark:text-blue-200 line-clamp-2',
                        ),
                      WDiv(
                        className: 'mt-2 flex items-center gap-2',
                        children: [
                          WIcon(
                            Icons.schedule,
                            className:
                                'text-xs text-blue-600 dark:text-blue-400',
                          ),
                          WText(
                            a.publishedAt != null
                                ? DateFormat.yMMMd().format(
                                    DateTime.parse(a.publishedAt.toString()),
                                  )
                                : '',
                            className:
                                'text-xs font-medium text-blue-600 dark:text-blue-400',
                          ),
                        ],
                      ),
                    ],
                  ),
                );
              }).toList(),
            ),
    );
  }
}
