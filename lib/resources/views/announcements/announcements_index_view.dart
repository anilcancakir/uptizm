import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/announcement_controller.dart';
import '../../../app/enums/announcement_type.dart';
import '../../../app/models/announcement.dart';
import '../components/app_page_header.dart';
import '../components/monitors/stat_card.dart';

class AnnouncementsIndexView extends MagicStatefulView<AnnouncementController> {
  const AnnouncementsIndexView({super.key});

  @override
  State<AnnouncementsIndexView> createState() => _AnnouncementsIndexViewState();
}

class _AnnouncementsIndexViewState
    extends
        MagicStatefulViewState<AnnouncementController, AnnouncementsIndexView> {
  String get _statusPageId =>
      MagicRouter.instance.pathParameter('statusPageId')!;

  @override
  void onInit() {
    super.onInit();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      controller.loadAnnouncements(_statusPageId);
    });
  }

  IconData _iconForType(AnnouncementType type) {
    switch (type) {
      case AnnouncementType.maintenance:
        return Icons.handyman_outlined;
      case AnnouncementType.improvement:
        return Icons.rocket_launch_outlined;
      case AnnouncementType.informational:
        return Icons.info_outline;
    }
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'overflow-y-auto flex flex-col',
      scrollPrimary: true,
      children: [
        // Header
        AppPageHeader(
          title: trans('announcements.title'),
          subtitle: trans('announcements.subtitle'),
          leading: WButton(
            onTap: () => MagicRoute.to('/status-pages/$_statusPageId'),
            className:
                'mr-4 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500',
            child: WIcon(Icons.arrow_back, className: 'text-xl'),
          ),
          actions: [
            WButton(
              onTap: () => MagicRoute.to(
                '/status-pages/$_statusPageId/announcements/create',
              ),
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
                  WText(trans('announcements.create')),
                ],
              ),
            ),
          ],
        ),

        // Stats Row
        ValueListenableBuilder<List<Announcement>>(
          valueListenable: controller.announcementsNotifier,
          builder: (context, announcements, _) {
            return _buildStatsRow(announcements);
          },
        ),

        // Filter Tabs
        _buildFilterTabs(),

        // List
        ValueListenableBuilder<List<Announcement>>(
          valueListenable: controller.announcementsNotifier,
          builder: (context, announcements, _) {
            return ValueListenableBuilder<AnnouncementType?>(
              valueListenable: controller.typeFilterNotifier,
              builder: (context, typeFilter, _) {
                if (controller.isLoading && announcements.isEmpty) {
                  return _buildLoadingState();
                }

                if (announcements.isEmpty) {
                  return _buildEmptyState();
                }

                final filtered = typeFilter == null
                    ? announcements
                    : announcements.where((a) => a.type == typeFilter).toList();

                if (filtered.isEmpty) {
                  return _buildNoResultsState();
                }

                return _buildList(filtered);
              },
            );
          },
        ),
      ],
    );
  }

  Widget _buildStatsRow(List<Announcement> announcements) {
    final total = announcements.length;
    final active = announcements.where((a) => a.isActive).length;
    final scheduled = announcements.where((a) => a.isScheduled).length;

    return WDiv(
      className:
          'w-full p-4 lg:p-6 border-b border-gray-200 dark:border-gray-700',
      children: [
        WDiv(
          className: 'grid grid-cols-2 md:grid-cols-4 gap-4',
          children: [
            StatCard(
              label: trans('announcements.stats.total'),
              value: '$total',
              icon: Icons.article_outlined,
            ),
            StatCard(
              label: trans('announcements.stats.active'),
              value: '$active',
              icon: Icons.check_circle_outline,
              valueColor: 'text-green-500',
            ),
            StatCard(
              label: trans('announcements.stats.scheduled'),
              value: '$scheduled',
              icon: Icons.calendar_today_outlined,
              valueColor: 'text-blue-500',
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildFilterTabs() {
    return ValueListenableBuilder<AnnouncementType?>(
      valueListenable: controller.typeFilterNotifier,
      builder: (context, selectedType, _) {
        return WDiv(
          className: '''
            w-full
            flex flex-row gap-2 p-4
            border-b border-gray-200 dark:border-gray-700
            overflow-x-auto
          ''',
          children: [
            _buildFilterTab(null, trans('common.all'), selectedType == null),
            ...AnnouncementType.values.map(
              (type) => _buildFilterTab(type, type.label, selectedType == type),
            ),
          ],
        );
      },
    );
  }

  Widget _buildFilterTab(
    AnnouncementType? type,
    String label,
    bool isSelected,
  ) {
    return WButton(
      onTap: () => controller.typeFilterNotifier.value = type,
      className:
          '''
        px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap
        ${isSelected ? 'bg-primary text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'}
        hover:bg-opacity-90 transition-colors
      ''',
      child: WText(label),
    );
  }

  Widget _buildList(List<Announcement> announcements) {
    return WDiv(
      className: 'w-full grid grid-cols-1 gap-4 p-4 lg:p-6',
      children: announcements.map((a) => _buildCard(a)).toList(),
    );
  }

  Widget _buildCard(Announcement announcement) {
    final typeColor = announcement.type?.color ?? 'gray';
    final typeLabel = announcement.type?.label ?? 'Unknown';
    final icon = announcement.type != null
        ? _iconForType(announcement.type!)
        : Icons.help_outline;

    return WAnchor(
      onTap: () => MagicRoute.to(
        '/status-pages/$_statusPageId/announcements/${announcement.id}',
      ),
      child: WDiv(
        className: '''
          bg-white dark:bg-gray-800
          border border-gray-100 dark:border-gray-700
          rounded-2xl p-5
          hover:shadow-lg hover:border-primary/50
          transition-all duration-150 cursor-pointer
        ''',
        children: [
          // Header Row
          WDiv(
            className: 'flex flex-row items-start justify-between gap-4',
            children: [
              // Icon + Title + Type
              WDiv(
                className: 'flex-1 flex flex-col gap-2',
                children: [
                  WDiv(
                    className: 'flex flex-row items-center gap-2',
                    children: [
                      WText(
                        announcement.title ?? trans('common.untitled'),
                        className:
                            'text-lg font-semibold text-gray-900 dark:text-white line-clamp-1',
                      ),
                    ],
                  ),
                  // Badges Row
                  WDiv(
                    className: 'wrap gap-2 items-center',
                    children: [
                      // Type Badge
                      WDiv(
                        className:
                            '''
                          flex flex-row items-center gap-1.5
                          px-2.5 py-1 rounded-md
                          bg-$typeColor-100 dark:bg-$typeColor-900/30
                          text-$typeColor-700 dark:text-$typeColor-300
                          text-xs font-medium
                        ''',
                        children: [
                          WIcon(icon, className: 'text-sm'),
                          WText(typeLabel),
                        ],
                      ),
                      // Status Badge
                      if (announcement.isActive)
                        _buildStatusBadge(
                          trans('announcements.status.active'),
                          'green',
                          Icons.check_circle,
                        )
                      else if (announcement.isScheduled)
                        _buildStatusBadge(
                          trans('announcements.status.scheduled'),
                          'blue',
                          Icons.schedule,
                        )
                      else if (announcement.isEnded)
                        _buildStatusBadge(
                          trans('announcements.status.ended'),
                          'gray',
                          Icons.history,
                        ),
                    ],
                  ),
                ],
              ),
              // Date
              WText(
                announcement.isScheduled
                    ? (announcement.scheduledAt?.diffForHumans() ?? '')
                    : (announcement.publishedAt?.diffForHumans() ?? ''),
                className: 'text-xs text-gray-500 dark:text-gray-400 font-mono',
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildStatusBadge(String label, String color, IconData icon) {
    return WDiv(
      className:
          '''
        flex flex-row items-center gap-1.5
        px-2.5 py-1 rounded-md
        bg-$color-100 dark:bg-$color-900/30
        text-$color-700 dark:text-$color-300
        text-xs font-medium
      ''',
      children: [
        WIcon(icon, className: 'text-sm'),
        WText(label),
      ],
    );
  }

  Widget _buildLoadingState() {
    return WDiv(
      className: 'py-12 flex items-center justify-center',
      child: const CircularProgressIndicator(),
    );
  }

  Widget _buildEmptyState() {
    return WDiv(
      className: 'flex flex-col items-center justify-center py-16 px-4',
      children: [
        WIcon(
          Icons.campaign_outlined,
          className: 'text-6xl text-gray-300 dark:text-gray-600 mb-4',
        ),
        WText(
          trans('announcements.empty_title'),
          className:
              'text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2',
        ),
        WText(
          trans('announcements.empty_desc'),
          className:
              'text-sm text-gray-600 dark:text-gray-400 mb-6 text-center max-w-sm',
        ),
        WButton(
          onTap: () => MagicRoute.to(
            '/status-pages/$_statusPageId/announcements/create',
          ),
          className: '''
            px-6 py-3 rounded-lg
            bg-primary hover:bg-green-600
            text-white font-medium
          ''',
          child: WText(trans('announcements.create_first')),
        ),
      ],
    );
  }

  Widget _buildNoResultsState() {
    return WDiv(
      className: 'flex flex-col items-center justify-center py-12 px-4',
      children: [
        WIcon(
          Icons.filter_list_off,
          className: 'text-6xl text-gray-300 dark:text-gray-600 mb-4',
        ),
        WText(
          trans('search.no_results'),
          className:
              'text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2',
        ),
        WButton(
          onTap: () => controller.typeFilterNotifier.value = null,
          className: '''
            px-4 py-2 rounded-lg
            bg-gray-100 dark:bg-gray-800
            hover:bg-gray-200 dark:hover:bg-gray-700
            text-gray-700 dark:text-gray-300 font-medium
            mt-4
          ''',
          child: WText(trans('common.clear_filters')),
        ),
      ],
    );
  }
}
