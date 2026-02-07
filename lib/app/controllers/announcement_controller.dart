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

  Future<void> loadAnnouncements(String statusPageId) async {}
  Future<void> loadAnnouncement(String statusPageId, String id) async {}
  Future<void> store(
    String statusPageId, {
    required String title,
    required String body,
    required AnnouncementType type,
    String? scheduledAt,
    String? endedAt,
  }) async {}
  Future<void> update(
    String statusPageId,
    String id, {
    String? title,
    String? body,
    AnnouncementType? type,
    String? scheduledAt,
    String? endedAt,
  }) async {}
  Future<void> destroy(String statusPageId, String id) async {}

  @override
  void dispose() {
    announcementsNotifier.dispose();
    selectedAnnouncementNotifier.dispose();
    typeFilterNotifier.dispose();
    super.dispose();
  }
}
