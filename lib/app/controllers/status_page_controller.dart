import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../models/status_page.dart';
import '../../resources/views/status_pages/status_pages_index_view.dart';
import '../../resources/views/status_pages/status_page_create_view.dart';
import '../../resources/views/status_pages/status_page_show_view.dart';
import '../../resources/views/status_pages/status_page_edit_view.dart';

class StatusPageController extends MagicController
    with MagicStateMixin<bool>, ValidatesRequests {
  static StatusPageController get instance =>
      Magic.findOrPut(StatusPageController.new);

  final statusPagesNotifier = ValueNotifier<List<StatusPage>>([]);
  final selectedStatusPageNotifier = ValueNotifier<StatusPage?>(null);

  bool _isLoading = false;
  @override
  bool get isLoading => _isLoading;

  Widget index() => const StatusPagesIndexView();
  Widget create() => const StatusPageCreateView();
  Widget show() => const StatusPageShowView();
  Widget edit() => const StatusPageEditView();

  Future<void> loadStatusPages() async {
    _isLoading = true;
    notifyListeners();

    try {
      final statusPages = await StatusPage.all();
      statusPagesNotifier.value = statusPages;
    } catch (e, s) {
      Log.error('Failed to load status pages: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> loadStatusPage(String id) async {
    _isLoading = true;
    notifyListeners();

    try {
      final statusPage = await StatusPage.find(id);
      selectedStatusPageNotifier.value = statusPage;
    } catch (e, s) {
      Log.error('Failed to load status page: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
      selectedStatusPageNotifier.value = null;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> store({
    required String name,
    required String slug,
    String? description,
    String? logoUrl,
    String? faviconUrl,
    String? primaryColor,
    bool isPublished = false,
    List<String>? monitorIds,
    List<Map<String, dynamic>>? monitors,
  }) async {
    setLoading();
    clearErrors();

    try {
      final statusPage = StatusPage()
        ..name = name
        ..slug = slug
        ..description = description
        ..logoUrl = logoUrl
        ..faviconUrl = faviconUrl
        ..primaryColor = primaryColor ?? '#009E60'
        ..isPublished = isPublished
        ..monitorIds = monitorIds ?? [];

      final success = await statusPage.save();

      if (success) {
        if (monitors != null && monitors.isNotEmpty) {
          final validMonitors = monitors
              .where((m) => m['monitor_id'] != null)
              .toList();
          if (validMonitors.isNotEmpty) {
            if (statusPage.id != null) {
              await attachMonitors(statusPage.id!, validMonitors);
            }
          }
        }

        setSuccess(true);
        Magic.toast(trans('status_pages.created_successfully'));
        MagicRoute.to('/status-pages');
        await loadStatusPages();
      } else {
        setError(trans('status_pages.create_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to create status page: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> update(
    String id, {
    String? name,
    String? slug,
    String? description,
    String? logoUrl,
    String? faviconUrl,
    String? primaryColor,
    bool? isPublished,
    List<String>? monitorIds,
    List<Map<String, dynamic>>? monitors,
  }) async {
    setLoading();
    clearErrors();

    try {
      // Build update payload directly - no need to fetch first
      final data = <String, dynamic>{};
      if (name != null) data['name'] = name;
      if (slug != null) data['slug'] = slug;
      if (description != null) data['description'] = description;
      if (logoUrl != null) data['logo_url'] = logoUrl;
      if (faviconUrl != null) data['favicon_url'] = faviconUrl;
      if (primaryColor != null) data['primary_color'] = primaryColor;
      if (isPublished != null) data['is_published'] = isPublished;
      if (monitorIds != null) data['monitor_ids'] = monitorIds;

      final response = await Http.put('/status-pages/$id', data: data);

      if (response.successful) {
        // Now sync monitors with their metric_keys
        if (monitors != null && monitors.isNotEmpty) {
          final validMonitors = monitors
              .where((m) => m['monitor_id'] != null)
              .toList();
          if (validMonitors.isNotEmpty) {
            await attachMonitors(id, validMonitors);
          }
        }

        setSuccess(true);
        Magic.toast(trans('status_pages.updated_successfully'));
        MagicRoute.to('/status-pages');
        await loadStatusPages();
      } else {
        setError(trans('status_pages.update_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to update status page: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> destroy(String id) async {
    final confirmed = await Magic.confirm(
      title: trans('common.confirm'),
      message: trans('status_pages.delete_confirm'),
      confirmText: trans('common.delete'),
      cancelText: trans('common.cancel'),
    );

    if (!confirmed) return;

    setLoading();

    try {
      final statusPage = await StatusPage.find(id);
      if (statusPage == null) {
        setError(trans('status_pages.not_found'));
        return;
      }

      final success = await statusPage.delete();

      if (success) {
        setSuccess(true);
        Magic.toast(trans('status_pages.deleted_successfully'));
        MagicRoute.to('/status-pages');
        await loadStatusPages();
      } else {
        setError(trans('status_pages.delete_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to delete status page: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> togglePublish(String id) async {
    setLoading();

    try {
      final response = await Http.post('/status-pages/$id/toggle-publish');

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('status_pages.toggle_publish_success'));
        await loadStatusPage(id);
      } else {
        setError(trans('status_pages.toggle_publish_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to toggle publish status: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> attachMonitors(
    String id,
    List<Map<String, dynamic>> monitors,
  ) async {
    setLoading();

    try {
      final response = await Http.post(
        '/status-pages/$id/monitors',
        data: {'monitors': monitors},
      );

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('status_pages.monitors_attached_successfully'));
        await loadStatusPage(id);
      } else {
        setError(trans('status_pages.attach_monitors_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to attach monitors: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> detachMonitor(String statusPageId, String monitorId) async {
    setLoading();

    try {
      final response = await Http.delete(
        '/status-pages/$statusPageId/monitors/$monitorId',
      );

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('status_pages.monitor_detached_successfully'));
        await loadStatusPage(statusPageId);
      } else {
        setError(trans('status_pages.detach_monitor_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to detach monitor: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> reorderMonitors(
    String id,
    List<Map<String, dynamic>> monitors,
  ) async {
    setLoading();

    try {
      final response = await Http.put(
        '/status-pages/$id/monitors/reorder',
        data: {'monitors': monitors},
      );

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('status_pages.monitors_reordered_successfully'));
        await loadStatusPage(id);
      } else {
        setError(trans('status_pages.reorder_monitors_failed'));
      }
    } catch (e, s) {
      Log.error('Failed to reorder monitors: $e\n$s', e);
      setError(trans('errors.network_error'));
    }
  }

  @override
  void dispose() {
    statusPagesNotifier.dispose();
    selectedStatusPageNotifier.dispose();
    super.dispose();
  }
}
