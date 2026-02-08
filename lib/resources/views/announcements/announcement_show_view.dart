import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/announcement_controller.dart';
import '../../../app/models/announcement.dart';
import '../components/app_page_header.dart';
import '../components/monitors/stat_card.dart';

class AnnouncementShowView extends MagicStatefulView<AnnouncementController> {
  const AnnouncementShowView({super.key});

  @override
  State<AnnouncementShowView> createState() => _AnnouncementShowViewState();
}

class _AnnouncementShowViewState
    extends
        MagicStatefulViewState<AnnouncementController, AnnouncementShowView> {
  String get statusPageId =>
      MagicRouter.instance.pathParameter('statusPageId')!;
  String get id => MagicRouter.instance.pathParameter('id')!;

  @override
  void onInit() {
    super.onInit();
    controller.loadAnnouncement(statusPageId, id);
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: controller.selectedAnnouncementNotifier,
      builder: (context, announcement, _) {
        if (announcement == null) {
          return WDiv(
            className: 'py-12 flex items-center justify-center',
            child: WDiv(
              className: 'w-full flex flex-col items-center gap-4',
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
            _buildHeader(announcement),
            WDiv(
              className: 'flex flex-col px-4 lg:px-6 gap-4 lg:gap-6',
              children: [
                _buildStatsSection(announcement),
                _buildBodyCard(announcement),
                _buildDatesCard(announcement),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _buildHeader(Announcement announcement) {
    final createdAt = announcement.createdAt;
    final formattedDate = createdAt != null
        ? createdAt.format('MMM d, yyyy HH:mm')
        : '';

    return AppPageHeader(
      leading: WButton(
        onTap: () => MagicRoute.to('/status-pages/$statusPageId/announcements'),
        className: 'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
        child: WIcon(
          Icons.arrow_back,
          className: 'text-xl text-gray-700 dark:text-gray-300',
        ),
      ),
      title: announcement.title ?? trans('announcements.announcement'),
      subtitle: formattedDate,
      actions: [
        // Edit Button
        WButton(
          onTap: () => MagicRoute.to(
            '/status-pages/$statusPageId/announcements/$id/edit',
          ),
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
              WText(trans('common.edit'), className: 'hidden sm:block'),
            ],
          ),
        ),

        // Delete Button
        WButton(
          onTap: () => controller.destroy(statusPageId, id),
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
              WText(trans('common.delete'), className: 'hidden sm:block'),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildStatsSection(Announcement announcement) {
    String statusLabel = 'Active';
    String statusTextClass = 'text-green-600 dark:text-green-400';

    final now = DateTime.now();

    // Check scheduled
    if (announcement.scheduledAt != null) {
      // Convert Carbon to DateTime for comparison if needed, or assume Carbon is comparable
      // Carbon usually implements Comparable<DateTime> or similar.
      // But to be safe and avoid "Carbon can't be assigned to DateTime" if we pass it to something expecting DateTime
      // we'll use toDateTime() if available or parse.
      // Since we are comparing, let's try strict comparison if Carbon extends DateTime.
      // If not, we parse.
      final scheduled = DateTime.parse(announcement.scheduledAt.toString());
      if (scheduled.isAfter(now)) {
        statusLabel = 'Scheduled';
        statusTextClass = 'text-blue-600 dark:text-blue-400';
      }
    }

    // Check ended
    if (announcement.endedAt != null) {
      final ended = DateTime.parse(announcement.endedAt.toString());
      if (ended.isBefore(now)) {
        statusLabel = 'Ended';
        statusTextClass = 'text-gray-600 dark:text-gray-400';
      }
    }

    String dateStr = '-';
    if (announcement.scheduledAt != null) {
      dateStr = announcement.scheduledAt!.format('MMM d');
    } else if (announcement.createdAt != null) {
      dateStr = announcement.createdAt!.format('MMM d');
    }

    return WDiv(
      className: 'grid grid-cols-2 md:grid-cols-3 gap-4',
      children: [
        // Type
        StatCard(
          label: trans('common.type'),
          value: announcement.type?.label ?? trans('common.unknown'),
          icon: Icons.category_outlined,
        ),
        // Status
        StatCard(
          label: trans('common.status'),
          value: statusLabel,
          icon: Icons.info_outline,
          valueColor: statusTextClass,
        ),
        // Date
        StatCard(
          label: trans('common.date'),
          value: dateStr,
          icon: Icons.calendar_today_outlined,
        ),
      ],
    );
  }

  Widget _buildBodyCard(Announcement announcement) {
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
                className: 'p-2 rounded-lg bg-purple-50 dark:bg-purple-900/20',
                child: WIcon(
                  Icons.campaign_outlined,
                  className: 'text-purple-600 dark:text-purple-400 text-lg',
                ),
              ),
              const WSpacer(className: 'w-3'),
              WText(
                'Content'.toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),
        // Body
        WDiv(
          className: 'p-5',
          child: WText(
            announcement.body ?? '',
            className:
                'text-sm text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap',
          ),
        ),
      ],
    );
  }

  Widget _buildDatesCard(Announcement announcement) {
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
                  Icons.schedule_outlined,
                  className: 'text-blue-600 dark:text-blue-400 text-lg',
                ),
              ),
              const WSpacer(className: 'w-3'),
              WText(
                'Timeline'.toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),
        // Body
        WDiv(
          className: 'p-5 flex flex-col gap-4',
          children: [
            if (announcement.scheduledAt != null)
              _buildDateRow(
                trans('announcements.scheduled_at'),
                announcement.scheduledAt,
                Icons.event_outlined,
              ),
            if (announcement.createdAt != null)
              _buildDateRow(
                trans('announcements.published_at'),
                announcement.createdAt,
                Icons.publish_outlined,
              ),
            if (announcement.endedAt != null)
              _buildDateRow(
                trans('announcements.ended_at'),
                announcement.endedAt,
                Icons.event_busy_outlined,
              ),
          ],
        ),
      ],
    );
  }

  Widget _buildDateRow(String label, dynamic date, IconData icon) {
    String formattedDate = '';
    if (date is Carbon) {
      formattedDate = date.format('MMMM d, yyyy HH:mm');
    } else if (date != null) {
      formattedDate = date.toString();
    }

    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        WIcon(icon, className: 'text-gray-400 dark:text-gray-500 text-lg'),
        Expanded(
          child: WDiv(
            className: 'flex flex-col',
            children: [
              WText(
                label,
                className: 'text-xs text-gray-500 dark:text-gray-400',
              ),
              WText(
                formattedDate,
                className: 'text-sm font-medium text-gray-900 dark:text-white',
              ),
            ],
          ),
        ),
      ],
    );
  }
}
