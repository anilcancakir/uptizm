import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../models/incident.dart';
import '../enums/incident_status.dart';
import '../enums/incident_impact.dart';
import '../../resources/views/incidents/incidents_index_view.dart';
import '../../resources/views/incidents/incident_create_view.dart';
import '../../resources/views/incidents/incident_show_view.dart';
import '../../resources/views/incidents/incident_edit_view.dart';

class IncidentController extends MagicController
    with MagicStateMixin<bool>, ValidatesRequests {
  static IncidentController get instance =>
      Magic.findOrPut(IncidentController.new);

  final incidentsNotifier = ValueNotifier<List<Incident>>([]);
  final selectedIncidentNotifier = ValueNotifier<Incident?>(null);
  final statusFilterNotifier = ValueNotifier<IncidentStatus?>(null);

  bool _isLoading = false;
  @override
  bool get isLoading => _isLoading;

  // Actions
  Widget index() => const IncidentsIndexView();
  Widget create() => const IncidentCreateView();
  Widget show(String id) {
    loadIncident(id);
    return const IncidentShowView();
  }

  Widget edit(String id) {
    loadIncident(id);
    return const IncidentEditView();
  }

  // Loaders
  Future<void> loadIncidents() async {
    _isLoading = true;
    notifyListeners();

    try {
      final incidents = await Incident.all();
      // Apply client-side filtering if needed, or pass query params to API
      if (statusFilterNotifier.value != null) {
        incidentsNotifier.value = incidents
            .where((i) => i.status == statusFilterNotifier.value)
            .toList();
      } else {
        incidentsNotifier.value = incidents;
      }
    } catch (e) {
      Log.error('Failed to load incidents', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> loadIncident(String id) async {
    _isLoading = true;
    notifyListeners();

    try {
      final incident = await Incident.find(id);
      selectedIncidentNotifier.value = incident;
    } catch (e) {
      Log.error('Failed to load incident', e);
      Magic.toast(trans('errors.network_error'));
      selectedIncidentNotifier.value = null;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  // CRUD
  Future<void> store({
    required String title,
    required IncidentImpact impact,
    required String message,
    required List<String> monitorIds,
    IncidentStatus status = IncidentStatus.investigating,
  }) async {
    setLoading();
    clearErrors();

    try {
      final incident = Incident()
        ..title = title
        ..impact = impact
        ..status = status
        ..monitorIds = monitorIds;

      // We might need to send the initial message as part of the payload
      // But Incident model doesn't have a 'message' field directly (it's in updates)
      // The API likely handles creating the initial update.
      // Assuming the API expects 'message' in the payload.
      final data = incident.toMap();
      data['message'] = message;

      // Since Incident.save() only saves the model attributes, we might need to use Http.post directly
      // if we need to send extra data like 'message'.
      // Or we can assume the backend handles it if we pass it.
      // Let's use Http.post for custom payload structure if needed.

      final response = await Http.post('/incidents', data: data);

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('incidents.created_successfully'));
        final newId = response.data['data']['id'].toString();
        MagicRoute.to('/incidents/$newId');
        await loadIncidents();
      } else {
        setError(trans('incidents.create_failed'));
      }
    } catch (e) {
      Log.error('Failed to create incident', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> update(
    String id, {
    String? title,
    IncidentImpact? impact,
    IncidentStatus? status,
  }) async {
    setLoading();
    clearErrors();

    try {
      final data = <String, dynamic>{};
      if (title != null) data['title'] = title;
      if (impact != null) data['impact'] = impact.value;
      if (status != null) data['status'] = status.value;

      final response = await Http.put('/incidents/$id', data: data);

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('incidents.updated_successfully'));
        MagicRoute.back(); // Go back to show view
        await loadIncident(id);
        await loadIncidents();
      } else {
        setError(trans('incidents.update_failed'));
      }
    } catch (e) {
      Log.error('Failed to update incident', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> destroy(String id) async {
    final confirmed = await Magic.confirm(
      title: trans('common.confirm'),
      message: trans('incidents.delete_confirm'),
      confirmText: trans('common.delete'),
      cancelText: trans('common.cancel'),
    );

    if (!confirmed) return;

    setLoading();

    try {
      final response = await Http.delete('/incidents/$id');

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('incidents.deleted_successfully'));
        MagicRoute.to('/incidents');
        await loadIncidents();
      } else {
        setError(trans('incidents.delete_failed'));
      }
    } catch (e) {
      Log.error('Failed to delete incident', e);
      setError(trans('errors.network_error'));
    }
  }

  Future<void> addUpdate(
    String incidentId, {
    required IncidentStatus status,
    required String message,
    String? title,
  }) async {
    setLoading();
    clearErrors();

    try {
      final data = {'status': status.value, 'message': message};
      if (title != null && title.isNotEmpty) {
        data['title'] = title;
      }

      final response = await Http.post(
        '/incidents/$incidentId/updates',
        data: data,
      );

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('incidents.update_added_successfully'));
        await loadIncident(incidentId); // Reload to see new update
      } else {
        setError(trans('incidents.add_update_failed'));
      }
    } catch (e) {
      Log.error('Failed to add incident update', e);
      setError(trans('errors.network_error'));
    }
  }

  void setStatusFilter(IncidentStatus? status) {
    statusFilterNotifier.value = status;
    loadIncidents();
  }

  @override
  void dispose() {
    incidentsNotifier.dispose();
    selectedIncidentNotifier.dispose();
    statusFilterNotifier.dispose();
    super.dispose();
  }
}
