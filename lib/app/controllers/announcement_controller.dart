import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../models/announcement.dart';
import '../enums/announcement_type.dart';
import '../../resources/views/announcements/announcements_index_view.dart';
import '../../resources/views/announcements/announcement_create_view.dart';
import '../../resources/views/announcements/announcement_show_view.dart';
import '../../resources/views/announcements/announcement_edit_view.dart';

class AnnouncementController extends MagicController
    with MagicStateMixin<bool>, ValidatesRequests {
  static AnnouncementController get instance =>
      Magic.findOrPut(AnnouncementController.new);

  final announcementsNotifier = ValueNotifier<List<Announcement>>([]);
  final selectedAnnouncementNotifier = ValueNotifier<Announcement?>(null);
  final typeFilterNotifier = ValueNotifier<AnnouncementType?>(null);

  bool _isLoading = false;
  @override
  bool get isLoading => _isLoading;

  Widget index(String statusPageId) => const AnnouncementsIndexView();
  Widget create(String statusPageId) => const AnnouncementCreateView();
  Widget show(String statusPageId, String id) => const AnnouncementShowView();
  Widget edit(String statusPageId, String id) => const AnnouncementEditView();

  Future<void> loadAnnouncements(String statusPageId) async {
    _isLoading = true;
    notifyListeners();

    try {
      final announcements = await Announcement.allForStatusPage(statusPageId);
      announcementsNotifier.value = announcements;
    } catch (e, s) {
      Log.error('Failed to load announcements: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> loadAnnouncement(String statusPageId, String id) async {
    _isLoading = true;
    notifyListeners();

    try {
      final announcement = await Announcement.findForStatusPage(
        statusPageId,
        id,
      );
      selectedAnnouncementNotifier.value = announcement;
    } catch (e, s) {
      Log.error('Failed to load announcement: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
      selectedAnnouncementNotifier.value = null;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> store(
    String statusPageId, {
    required String title,
    required String body,
    required AnnouncementType type,
    String? scheduledAt,
    String? endedAt,
  }) async {
    setLoading();
    clearErrors();

    try {
      final data = <String, dynamic>{
        'title': title,
        'body': body,
        'type': type.value,
      };
      if (scheduledAt != null) data['scheduled_at'] = scheduledAt;
      if (endedAt != null) data['ended_at'] = endedAt;

      final announcement = await Announcement.createForStatusPage(
        statusPageId,
        data,
      );

      if (announcement != null) {
        setSuccess(true);
        Magic.toast(trans('announcements.created_successfully'));
        MagicRoute.back();
        await loadAnnouncements(statusPageId);
      } else {
        setError(trans('announcements.create_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to create announcement: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> update(
    String statusPageId,
    String id, {
    String? title,
    String? body,
    AnnouncementType? type,
    String? scheduledAt,
    String? endedAt,
  }) async {
    setLoading();
    clearErrors();

    try {
      final announcement = await Announcement.findForStatusPage(
        statusPageId,
        id,
      );
      if (announcement == null) {
        setError(trans('announcements.not_found'));
        return;
      }

      if (title != null) announcement.title = title;
      if (body != null) announcement.body = body;
      if (type != null) announcement.type = type;
      if (scheduledAt != null) {
        announcement.scheduledAt = Carbon.parse(scheduledAt);
      }
      if (endedAt != null) {
        announcement.endedAt = Carbon.parse(endedAt);
      }

      final success = await announcement.updateForStatusPage(statusPageId);

      if (success) {
        setSuccess(true);
        Magic.toast(trans('announcements.updated_successfully'));
        MagicRoute.back();
        await loadAnnouncements(statusPageId);
      } else {
        setError(trans('announcements.update_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to update announcement: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> destroy(String statusPageId, String id) async {
    final confirmed = await Magic.confirm(
      title: trans('common.confirm'),
      message: trans('announcements.delete_confirm'),
      confirmText: trans('common.delete'),
      cancelText: trans('common.cancel'),
    );

    if (!confirmed) return;

    setLoading();

    try {
      final announcement = await Announcement.findForStatusPage(
        statusPageId,
        id,
      );
      if (announcement == null) {
        setError(trans('announcements.not_found'));
        return;
      }

      final success = await announcement.deleteForStatusPage(statusPageId);

      if (success) {
        setSuccess(true);
        Magic.toast(trans('announcements.deleted_successfully'));
        MagicRoute.back();
        await loadAnnouncements(statusPageId);
      } else {
        setError(trans('announcements.delete_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to delete announcement: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  @override
  void dispose() {
    announcementsNotifier.dispose();
    selectedAnnouncementNotifier.dispose();
    typeFilterNotifier.dispose();
    super.dispose();
  }
}
