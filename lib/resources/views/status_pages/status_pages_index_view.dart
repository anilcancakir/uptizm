import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

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
    return WAnchor(
      onTap: () => MagicRoute.to('/status-pages/${page.id}'),
      child: WDiv(
        className: '''
          bg-white dark:bg-gray-800
          border border-gray-100 dark:border-gray-700
          rounded-2xl p-5
          hover:shadow-lg hover:border-primary/50
          transition-all duration-150 cursor-pointer
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

              // Right: Badges
              WDiv(
                className: 'flex flex-row items-center gap-2',
                children: [
                  _buildPublishedBadge(page),
                  const WSpacer(className: 'w-2'),
                  _buildMonitorCountBadge(page),
                ],
              ),
            ],
          ),
        ],
      ),
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
