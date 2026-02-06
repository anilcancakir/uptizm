import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/models/status_page.dart';
import '../components/app_page_header.dart';

class StatusPageShowView extends MagicStatefulView<StatusPageController> {
  const StatusPageShowView({super.key});

  @override
  State<StatusPageShowView> createState() => _StatusPageShowViewState();
}

class _StatusPageShowViewState
    extends MagicStatefulViewState<StatusPageController, StatusPageShowView> {
  String? _statusPageId;

  @override
  void onInit() {
    super.onInit();
    final idParam = MagicRouter.instance.pathParameter('id');
    if (idParam != null) {
      _statusPageId = idParam;
      WidgetsBinding.instance.addPostFrameCallback((_) async {
        controller.selectedStatusPageNotifier.value = null;
        await controller.loadStatusPage(_statusPageId!);
      });
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
    return WDiv(
      className:
          'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-2xl overflow-hidden',
      children: [
        // Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WIcon(
                Icons.info_outline,
                className: 'text-lg text-gray-500 dark:text-gray-400 mr-2',
              ),
              WText(
                trans('status_pages.info'),
                className:
                    'text-base font-semibold text-gray-900 dark:text-white',
              ),
            ],
          ),
        ),
        // Content
        WDiv(
          className: 'p-5',
          child: Column(
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
                    className:
                        'text-sm font-mono text-gray-700 dark:text-gray-300',
                  ),
                ],
              ),
              // Logo preview
              if (statusPage.logoUrl != null &&
                  statusPage.logoUrl!.isNotEmpty) ...[
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
        ),
      ],
    );
  }

  Widget _buildMonitorsCard(StatusPage statusPage) {
    final monitors = statusPage.monitors;

    return WDiv(
      className:
          'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-2xl overflow-hidden',
      children: [
        // Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WIcon(
                Icons.monitor_heart_outlined,
                className: 'text-lg text-gray-500 dark:text-gray-400 mr-2',
              ),
              WText(
                trans('status_pages.monitors'),
                className:
                    'text-base font-semibold text-gray-900 dark:text-white',
              ),
              const SizedBox(width: 8),
              WDiv(
                className:
                    'px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded-full',
                child: WText(
                  '${monitors.length}',
                  className:
                      'text-xs font-medium text-gray-600 dark:text-gray-300',
                ),
              ),
            ],
          ),
        ),
        // Content
        if (monitors.isEmpty)
          WDiv(
            className: 'p-5',
            child: WText(
              trans('status_pages.no_monitors'),
              className: 'text-sm text-gray-500 dark:text-gray-400',
            ),
          )
        else
          WDiv(
            className: 'divide-y divide-gray-100 dark:divide-gray-700',
            children: monitors
                .map(
                  (monitor) => WDiv(
                    className:
                        'px-5 py-3 flex flex-row items-center justify-between',
                    children: [
                      WDiv(
                        className: 'flex flex-row items-center gap-3',
                        children: [
                          WDiv(
                            className:
                                'w-2 h-2 rounded-full ${_getStatusColor(monitor.status?.value)}',
                          ),
                          WText(
                            monitor.name ?? trans('monitors.unnamed'),
                            className:
                                'text-sm font-medium text-gray-900 dark:text-white',
                          ),
                        ],
                      ),
                      WText(
                        _getStatusLabel(monitor.status?.value),
                        className:
                            'text-xs font-medium ${_getStatusTextColor(monitor.status?.value)}',
                      ),
                    ],
                  ),
                )
                .toList(),
          ),
      ],
    );
  }

  Widget _buildStatusCard(StatusPage statusPage) {
    return WDiv(
      className:
          'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-2xl overflow-hidden',
      children: [
        WDiv(
          className: 'p-5',
          child: Row(
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
                      className:
                          'text-2xl font-bold text-gray-900 dark:text-white',
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ],
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

  String _getStatusColor(String? status) {
    switch (status) {
      case 'active':
        return 'bg-green-500';
      case 'paused':
        return 'bg-yellow-500';
      case 'down':
        return 'bg-red-500';
      default:
        return 'bg-gray-400';
    }
  }

  String _getStatusTextColor(String? status) {
    switch (status) {
      case 'active':
        return 'text-green-600 dark:text-green-400';
      case 'paused':
        return 'text-yellow-600 dark:text-yellow-400';
      case 'down':
        return 'text-red-600 dark:text-red-400';
      default:
        return 'text-gray-500 dark:text-gray-400';
    }
  }

  String _getStatusLabel(String? status) {
    switch (status) {
      case 'active':
        return trans('monitor_status.active');
      case 'paused':
        return trans('monitor_status.paused');
      case 'down':
        return trans('monitor_status.down');
      default:
        return trans('monitor_status.unknown');
    }
  }
}
