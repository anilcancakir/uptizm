import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../models/status_page.dart';
import '../../resources/views/status_pages/status_pages_index_view.dart';
import '../../resources/views/status_pages/status_page_create_view.dart';
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
  Widget edit() => const StatusPageEditView();

  Future<void> loadStatusPages() async {
    _isLoading = true;
    notifyListeners();

    try {
      final statusPages = await StatusPage.all();
      statusPagesNotifier.value = statusPages;
    } catch (e) {
      Log.error('Failed to load status pages', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> loadStatusPage(int id) async {
    _isLoading = true;
    notifyListeners();

    try {
      final statusPage = await StatusPage.find(id);
      selectedStatusPageNotifier.value = statusPage;
    } catch (e) {
      Log.error('Failed to load status page', e);
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
    List<int>? monitorIds,
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
          // Determine ID if not automatically set, though save() should set it.
          // If save() returns true, we assume persistence worked.
          // If statusPage.id is null, we might need to fetch the last created one or rely on backend returning it.
          // Assuming Magic/Eloquent behavior where ID is populated.
          if (statusPage.id != null) {
            await attachMonitors(statusPage.id!, monitors);
          }
        }

        setSuccess(true);
        Magic.toast(trans('status_pages.created_successfully'));
        MagicRoute.to('/status-pages');
        await loadStatusPages();
      } else {
        setError(trans('status_pages.create_failed'));
      }
    } catch (e) {
      Log.error('Failed to create status page', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> update(
    int id, {
    String? name,
    String? slug,
    String? description,
    String? logoUrl,
    String? faviconUrl,
    String? primaryColor,
    bool? isPublished,
    List<int>? monitorIds,
    List<Map<String, dynamic>>? monitors,
  }) async {
    setLoading();
    clearErrors();

    try {
      final statusPage = await StatusPage.find(id);
      if (statusPage == null) {
        setError(trans('status_pages.not_found'));
        return;
      }

      if (name != null) statusPage.name = name;
      if (slug != null) statusPage.slug = slug;
      if (description != null) statusPage.description = description;
      if (logoUrl != null) statusPage.logoUrl = logoUrl;
      if (faviconUrl != null) statusPage.faviconUrl = faviconUrl;
      if (primaryColor != null) statusPage.primaryColor = primaryColor;
      if (isPublished != null) statusPage.isPublished = isPublished;
      if (monitorIds != null) statusPage.monitorIds = monitorIds;

      final success = await statusPage.save();

      if (success) {
        if (monitors != null) {
          await attachMonitors(id, monitors);
        }

        setSuccess(true);
        Magic.toast(trans('status_pages.updated_successfully'));
        MagicRoute.to('/status-pages');
        await loadStatusPages();
      } else {
        setError(trans('status_pages.update_failed'));
      }
    } catch (e) {
      Log.error('Failed to update status page', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> destroy(int id) async {
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
    } catch (e) {
      Log.error('Failed to delete status page', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> togglePublish(int id) async {
    setLoading();

    try {
      final response = await Http.post('/status-pages/$id/publish');

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('status_pages.toggle_publish_success'));
        await loadStatusPage(id);
      } else {
        setError(trans('status_pages.toggle_publish_failed'));
      }
    } catch (e) {
      Log.error('Failed to toggle publish status', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> attachMonitors(
    int id,
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
    } catch (e) {
      Log.error('Failed to attach monitors', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> detachMonitor(int statusPageId, int monitorId) async {
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
    } catch (e) {
      Log.error('Failed to detach monitor', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> reorderMonitors(
    int id,
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
    } catch (e) {
      Log.error('Failed to reorder monitors', e);
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
