import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/controllers/status_page_controller.dart';
import '../../../app/models/status_page.dart';
import '../components/app_page_header.dart';

class StatusPagesIndexView extends MagicStatefulView<StatusPageController> {
  const StatusPagesIndexView({super.key});

  @override
  State<StatusPagesIndexView> createState() => _StatusPagesIndexViewState();
}

class _StatusPagesIndexViewState
    extends MagicStatefulViewState<StatusPageController, StatusPagesIndexView> {
  @override
  void onInit() {
    super.onInit();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      controller.loadStatusPages();
    });
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'overflow-y-auto flex flex-col',
      scrollPrimary: true,
      children: [
        // Header
        AppPageHeader(
          title: trans('navigation.status_pages'),
          subtitle: trans('status_pages.welcome_subtitle'),
          actions: [
            WButton(
              onTap: () => MagicRoute.to('/status-pages/create'),
              className: '''
                px-4 py-2 rounded-lg
                bg-primary hover:bg-green-600
                text-white font-medium text-sm
                flex flex-row items-center gap-2
              ''',
              child: WDiv(
                className: 'flex flex-row items-center gap-2',
                children: [
                  WIcon(Icons.add, className: 'text-lg text-white'),
                  WText(trans('status_pages.create')),
                ],
              ),
            ),
          ],
        ),

        // List
        ValueListenableBuilder<List<StatusPage>>(
          valueListenable: controller.statusPagesNotifier,
          builder: (context, statusPages, _) {
            if (controller.isLoading && statusPages.isEmpty) {
              return _buildLoadingState();
            }

            if (statusPages.isEmpty) {
              return _buildEmptyState();
            }

            return WDiv(
              className: 'w-full grid grid-cols-1 gap-4 p-4 lg:p-6',
              children: statusPages.map(_buildStatusPageCard).toList(),
            );
          },
        ),
      ],
    );
  }

  Widget _buildStatusPageCard(StatusPage page) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-5
        hover:shadow-lg hover:border-primary/50
        transition-all duration-150
      ''',
      children: [
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            // Left: Icon + Info
            WDiv(
              className: 'flex flex-row items-center gap-4',
              children: [
                WDiv(
                  className: '''
                    w-12 h-12 rounded-xl
                    bg-primary/10 flex items-center justify-center
                  ''',
                  child: WIcon(
                    Icons.public,
                    className: 'text-2xl text-primary',
                  ),
                ),
                WDiv(
                  className: 'flex flex-col',
                  children: [
                    WText(
                      page.name,
                      className:
                          'text-lg font-semibold text-gray-900 dark:text-white',
                    ),
                    WText(
                      page.slug,
                      className:
                          'text-sm font-mono text-gray-500 dark:text-gray-400',
                    ),
                  ],
                ),
              ],
            ),

            // Right: Actions
            WDiv(
              className: 'flex flex-row items-center gap-2',
              children: [
                _buildPublishedBadge(page),
                const WSpacer(className: 'w-2'),
                _buildMonitorCountBadge(page),
                const WSpacer(className: 'w-2'),
                _buildActions(page),
              ],
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildPublishedBadge(StatusPage page) {
    final isPublished = page.isPublished;
    return WDiv(
      className:
          '''
        px-2.5 py-1 rounded-full text-xs font-medium
        ${isPublished ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'}
      ''',
      child: WText(
        isPublished
            ? trans('status_pages.published')
            : trans('status_pages.draft'),
      ),
    );
  }

  Widget _buildMonitorCountBadge(StatusPage page) {
    final count = page.monitorIds.length;
    return WDiv(
      className: '''
        px-2.5 py-1 rounded-full text-xs font-medium
        bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400
        flex flex-row items-center gap-1
      ''',
      children: [
        WIcon(Icons.monitor_heart_outlined, className: 'text-xs'),
        WText('$count ${trans('navigation.monitors')}'),
      ],
    );
  }

  Widget _buildActions(StatusPage page) {
    return WPopover(
      alignment: PopoverAlignment.bottomRight,
      triggerBuilder: (context, isOpen, isHovering) {
        return WButton(
          className:
              '''
            p-2 rounded-lg
            hover:bg-gray-100 dark:hover:bg-gray-700
            text-gray-500 dark:text-gray-400
            ${isOpen ? 'bg-gray-100 dark:bg-gray-700' : ''}
          ''',
          child: WIcon(Icons.more_vert),
        );
      },
      contentBuilder: (context, close) {
        return WDiv(
          className: '''
            flex flex-col min-w-[160px] py-1
            bg-white dark:bg-gray-800 
            rounded-xl shadow-xl 
            border border-gray-100 dark:border-gray-700
          ''',
          children: [
            _buildActionItem(
              icon: Icons.edit_outlined,
              label: trans('common.edit'),
              onTap: () {
                close();
                MagicRoute.to('/status-pages/${page.id}/edit');
              },
            ),
            _buildActionItem(
              icon: page.isPublished
                  ? Icons.visibility_off_outlined
                  : Icons.visibility_outlined,
              label: page.isPublished
                  ? trans('status_pages.unpublish')
                  : trans('status_pages.publish'),
              onTap: () {
                close();
                controller.togglePublish(page.id!);
              },
            ),
            _buildActionItem(
              icon: Icons.open_in_new,
              label: trans('status_pages.open_public_page'),
              onTap: () {
                close();
                launchUrl(Uri.parse(page.publicUrl));
              },
            ),
            WDiv(className: 'h-px bg-gray-100 dark:bg-gray-700 my-1'),
            _buildActionItem(
              icon: Icons.delete_outline,
              label: trans('common.delete'),
              isDestructive: true,
              onTap: () {
                close();
                controller.destroy(page.id!);
              },
            ),
          ],
        );
      },
    );
  }

  Widget _buildActionItem({
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

  Widget _buildEmptyState() {
    return WDiv(
      className: 'flex flex-col items-center justify-center py-12 px-4',
      children: [
        WIcon(
          Icons.public_off,
          className: 'text-6xl text-gray-400 dark:text-gray-600 mb-4',
        ),
        WText(
          trans('status_pages.no_status_pages'),
          className:
              'text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2',
        ),
        WText(
          trans('status_pages.no_status_pages_desc'),
          className:
              'text-sm text-gray-600 dark:text-gray-400 mb-6 text-center',
        ),
        WButton(
          onTap: () => MagicRoute.to('/status-pages/create'),
          className: '''
            px-6 py-3 rounded-lg
            bg-primary hover:bg-green-600
            text-white font-medium
          ''',
          child: WText(trans('status_pages.create')),
        ),
      ],
    );
  }

  Widget _buildLoadingState() {
    return WDiv(
      className: 'py-12 flex items-center justify-center',
      child: const CircularProgressIndicator(),
    );
  }
}
