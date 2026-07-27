import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../resources/views/monitors/monitor_metrics_support.dart';

// ---------------------------------------------------------------------------
// Wire <-> form vocabulary maps.
//
// The Flutter metric form (`monitor_metrics_support.dart`'s `kMetricSources`/
// `kMetricUnits`) uses a shorter vocabulary (`'json'`, `'%'`, `'req_s'`, ...)
// than the backend `MetricSource`/`MetricUnit` enums (`'json_path'`,
// `'percent'`, ...). These maps translate both ways so a write round-trips
// through the backend's enum validation and a read decodes back into the
// form's existing option values.
// ---------------------------------------------------------------------------

/// Form `source` value -> backend `MetricSource` enum value.
const Map<String, String> _sourceToWire = {
  'json': 'json_path',
  'regex': 'regex',
  'xpath': 'xpath',
  'header': 'header',
  'http_status': 'http_status',
};

/// Form `unit` value -> backend `MetricUnit` enum value.
///
/// `req_s` (requests/sec) has no backend equivalent (the enum has no
/// throughput unit); it maps to `custom` as the nearest fit and, on the way
/// back, decodes to `custom` rather than round-tripping to `req_s` exactly.
/// This is a deliberate best-effort divergence, not a silent failure: the
/// mapped value always validates against the backend enum.
const Map<String, String> _unitToWire = {
  'ms': 'millisecond',
  's': 'second',
  '%': 'percent',
  'count': 'count',
  'bytes': 'byte',
  'req_s': 'custom',
  'custom': 'custom',
};

String _sourceToWireValue(String source) => _sourceToWire[source] ?? 'json_path';

String _sourceFromWire(String? wire) {
  for (final MapEntry<String, String> entry in _sourceToWire.entries) {
    if (entry.value == wire) return entry.key;
  }
  return 'json';
}

String _unitToWireValue(String unit) => _unitToWire[unit] ?? 'custom';

String _unitFromWire(String? wire) {
  for (final MapEntry<String, String> entry in _unitToWire.entries) {
    if (entry.value == wire) return entry.key;
  }
  return 'custom';
}

String _directionToWireValue(String direction) =>
    direction == 'low' ? 'low_bad' : 'high_bad';

String _directionFromWire(String? wire) => wire == 'low_bad' ? 'low' : 'high';

// ---------------------------------------------------------------------------
// MonitorMetricRecord: a persisted custom metric definition.
// ---------------------------------------------------------------------------

/// A custom metric definition as persisted by the backend, pairing the wire
/// `id` (needed for `update`/`delete`) with the [MetricForm] edit-model
/// fields the metrics tab already renders (`monitor_metrics_support.dart`).
@immutable
class MonitorMetricRecord {
  /// The backend `monitor_metrics.id`.
  final String id;

  /// The metric fields, decoded into the same string-backed edit model the
  /// create/edit sheet and the historical detail sheet already consume.
  final MetricForm form;

  const MonitorMetricRecord({required this.id, required this.form});

  /// Builds a [MonitorMetricRecord] from a `MonitorMetricResource` payload
  /// (backend `api/v1` snake_case keys; see
  /// `backend/app/Http/Resources/MonitorMetricResource.php`).
  ///
  /// The optional nested `latest` block (`{numeric_value, ...}`) carries the
  /// most recent extracted reading; when absent the catalog baseline
  /// ([MetricForm.value]) defaults to `0`.
  factory MonitorMetricRecord.fromMap(Map<String, dynamic> map) {
    final Object? latest = map['latest'];
    final num latestValue = latest is Map
        ? (latest['numeric_value'] as num?) ?? 0
        : 0;

    return MonitorMetricRecord(
      id: map['id']?.toString() ?? '',
      form: MetricForm(
        label: (map['label'] as String?) ?? '',
        key: (map['key'] as String?) ?? '',
        type: (map['type'] as String?) ?? 'numeric',
        source: _sourceFromWire(map['source'] as String?),
        path: (map['extraction_path'] as String?) ?? '',
        unit: _unitFromWire(map['unit'] as String?),
        direction: _directionFromWire(map['threshold_direction'] as String?),
        warn: (map['warn_bound'] as num?)?.toString() ?? '',
        critical: (map['critical_bound'] as num?)?.toString() ?? '',
        value: latestValue,
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// MonitorMetricsController
// ---------------------------------------------------------------------------

/// Controller backing [MonitorMetricsTab]'s custom-metrics catalog.
///
/// Sources a monitor's custom metric definitions + latest readings from the
/// live `api/v1` metrics endpoints (`routes/api.php:99-116`) and drives
/// create/update/delete/reorder against the same endpoints, following the
/// reload/action pattern established by `monitor_controller.dart:61-83` and
/// `:145-221`.
///
/// The inventory is cached per monitor id in [_byMonitor] so [metricsFor]
/// (read synchronously inside a view's `build()`) never needs to await a
/// request; [reload] keeps that cache warm. There is no system/custom
/// discriminator on the backend `monitor_metrics` table (every row is
/// user-defined; see `backend/app/Models/MonitorMetric.php`), so this
/// controller owns only the custom metrics catalog; the metrics tab's
/// system section stays a separate, client-derived concern.
class MonitorMetricsController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller on first access.
  static MonitorMetricsController get instance =>
      Magic.findOrPut(MonitorMetricsController.new);

  /// In-memory cache of each monitor's custom metric catalog, populated by
  /// [reload] and mutated in place by the write actions below.
  final Map<String, List<MonitorMetricRecord>> _byMonitor = {};

  /// The custom metric catalog for [monitorId], sourced from `GET
  /// /monitors/:id/metrics`. Empty until [reload] resolves for that monitor.
  List<MonitorMetricRecord> metricsFor(String monitorId) =>
      _byMonitor[monitorId] ?? const [];

  /// Seeds the in-memory catalog for [monitorId] directly for a
  /// widget/controller test, bypassing the network. Notifies listeners so an
  /// already-mounted view rebuilds against the seeded catalog.
  @visibleForTesting
  void seedForTest(String monitorId, List<MonitorMetricRecord> seed) {
    _byMonitor[monitorId] = List<MonitorMetricRecord>.from(seed);
    refreshUI();
  }

  /// Non-destructive catalog refresh for [monitorId]: fetches `GET
  /// /monitors/:id/metrics` and republishes the catalog on success.
  /// Preserves the previously loaded catalog on any failure (network error,
  /// non-2xx, or malformed payload) so the tab never flickers into an empty
  /// state between reloads.
  Future<void> reload(String monitorId) async {
    try {
      final response = await Http.get('/monitors/$monitorId/metrics');
      if (!response.successful) return;

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return;

      _byMonitor[monitorId] = raw
          .whereType<Map<String, dynamic>>()
          .map(MonitorMetricRecord.fromMap)
          .toList();
      refreshUI();
    } catch (_) {
      // Deliberate degradation: a transport failure (including an
      // unregistered `network` service in a bare test host) or a malformed
      // payload keeps the last-known-good catalog (empty before the first
      // successful fetch) instead of throwing out of the tab's `initState`.
    }
  }

  /// Drops every monitor's cached metric catalog and publishes the cleared
  /// state. There is nothing to refetch here.
  ///
  /// [reload] is per monitor (`reload(String monitorId)`), and after an identity
  /// change this controller holds no id worth refetching: the cached ids belong
  /// to the previous session's monitors, and the new session's monitor ids are
  /// not known here (only [MonitorController] fetches those). Refetching the
  /// cached ids would probe another team's monitors and get a masked 404 for
  /// each. The metrics tab issues its own `reload(monitorId)` from `initState`
  /// when it mounts for a monitor, so the new session's catalog lands there;
  /// clearing plus [refreshUI] is the whole reset.
  @override
  Future<void> resetForSession() async {
    _byMonitor.clear();
    refreshUI();
  }

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Creates a custom metric on [monitorId] via `POST /monitors/:id/metrics`
  /// and reloads the catalog on success.
  ///
  /// Returns the backend per-field validation errors (single message per field,
  /// keyed by the wire field name the form posts: `label`, `key`,
  /// `extraction_path`, `warn_bound`, `critical_bound`, ...) so the metric form
  /// can render a server 422 inline; an empty map means success (the caller
  /// closes the sheet). A 422 that carries field errors STAYS on the form with
  /// no toast so the user corrects the flagged fields; a non-field failure (a
  /// transport error / 500) keeps the generic save-failed toast and returns an
  /// empty map. Mirrors `monitor_controller.dart`'s [create] contract, reading
  /// the errors from [MagicResponse.errors] (this write path is raw `Http.post`,
  /// not an ORM `Model.save()`, so there is no `model.validationErrors`; both
  /// resolve the same Laravel 422 shape).
  Future<Map<String, String>> create(String monitorId, MetricForm form) async {
    try {
      final response = await Http.post(
        '/monitors/$monitorId/metrics',
        data: _toWirePayload(form),
      );
      if (!response.successful) {
        Log.error(
          '[MonitorMetricsController.create] $monitorId: ${response.errorMessage}',
        );
        return _fieldErrorsOrToast(response);
      }

      await reload(monitorId);
      return const {};
    } catch (error) {
      Log.error(
        '[MonitorMetricsController.create] $monitorId failed: $error',
      );
      _notifySaveFailed(null);
      return const {};
    }
  }

  /// Updates the custom metric [metricId] on [monitorId] via `PUT
  /// /monitors/:id/metrics/:metricId` and reloads the catalog on success.
  ///
  /// Shares the metric form (and therefore its [create] return contract): the
  /// same [MonitorMetricForm] backs both create and edit, so this returns the
  /// backend per-field validation errors (empty map on success) too, giving an
  /// edited metric the same inline-422 handling as a created one instead of a
  /// lossy `bool`-to-map adapter.
  Future<Map<String, String>> update(
    String monitorId,
    String metricId,
    MetricForm form,
  ) async {
    try {
      final response = await Http.put(
        '/monitors/$monitorId/metrics/$metricId',
        data: _toWirePayload(form),
      );
      if (!response.successful) {
        Log.error(
          '[MonitorMetricsController.update] $monitorId/$metricId: '
          '${response.errorMessage}',
        );
        return _fieldErrorsOrToast(response);
      }

      await reload(monitorId);
      return const {};
    } catch (error) {
      Log.error(
        '[MonitorMetricsController.update] $monitorId/$metricId failed: $error',
      );
      _notifySaveFailed(null);
      return const {};
    }
  }

  /// Deletes the custom metric [metricId] on [monitorId] via `DELETE
  /// /monitors/:id/metrics/:metricId` and reloads the catalog on success.
  Future<bool> delete(String monitorId, String metricId) async {
    try {
      final response = await Http.delete(
        '/monitors/$monitorId/metrics/$metricId',
      );
      if (!response.successful) {
        Log.error(
          '[MonitorMetricsController.delete] $monitorId/$metricId: '
          '${response.errorMessage}',
        );
        _notifySaveFailed(response.errorMessage);
        return false;
      }

      await reload(monitorId);
      return true;
    } catch (error) {
      Log.error(
        '[MonitorMetricsController.delete] $monitorId/$metricId failed: $error',
      );
      _notifySaveFailed(null);
      return false;
    }
  }

  /// Persists a new display order for [monitorId]'s metrics via `PUT
  /// /monitors/:id/metrics/reorder` and reloads the catalog on success.
  ///
  /// [orderedIds] is the full set of this monitor's metric ids in their new
  /// display order; each entry's index becomes its `display_order`.
  Future<bool> reorder(String monitorId, List<String> orderedIds) async {
    final List<Map<String, dynamic>> order = [
      for (final (int index, String id) in orderedIds.indexed)
        {'id': id, 'display_order': index},
    ];

    try {
      final response = await Http.put(
        '/monitors/$monitorId/metrics/reorder',
        data: {'order': order},
      );
      if (!response.successful) {
        Log.error(
          '[MonitorMetricsController.reorder] $monitorId: ${response.errorMessage}',
        );
        _notifySaveFailed(response.errorMessage);
        return false;
      }

      await reload(monitorId);
      return true;
    } catch (error) {
      Log.error('[MonitorMetricsController.reorder] $monitorId failed: $error');
      _notifySaveFailed(null);
      return false;
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /// Maps [form] to the backend `StoreMonitorMetricRequest`/update payload
  /// shape, translating the form's short vocabulary into the backend's
  /// `MetricSource`/`MetricUnit`/`ThresholdDirection` enum values.
  Map<String, dynamic> _toWirePayload(MetricForm form) {
    return {
      'label': form.label,
      'key': form.key,
      'type': form.type,
      'source': _sourceToWireValue(form.source),
      'extraction_path': form.path.isEmpty ? null : form.path,
      'unit': _unitToWireValue(form.unit),
      'threshold_direction': _directionToWireValue(form.direction),
      'warn_bound': num.tryParse(form.warn),
      'critical_bound': num.tryParse(form.critical),
    };
  }

  /// Resolves a failed metric write [response] into either its per-field
  /// validation errors or a generic toast.
  ///
  /// Returns the field errors (single message per field, keyed by the wire
  /// field name) when the failed write carried the Laravel 422 shape via
  /// [MagicResponse.errors], so the caller hands them back to the form for
  /// inline display and keeps the sheet open. Returns an empty map for a
  /// non-field failure (a transport error / 500) after surfacing the generic
  /// save-failed toast, so the caller closes the sheet on the empty-map
  /// contract. Mirrors `monitor_controller.dart`'s `_fieldErrorsOrToast`,
  /// reading [MagicResponse.errors] instead of a model's `validationErrors`.
  Map<String, String> _fieldErrorsOrToast(MagicResponse response) {
    final Map<String, List<String>> errors = response.errors;
    if (errors.isNotEmpty) {
      return {
        for (final MapEntry<String, List<String>> entry in errors.entries)
          entry.key: entry.value.first,
      };
    }

    _notifySaveFailed(response.errorMessage);
    return const {};
  }

  /// Surfaces the shared "couldn't save" toast (reusing the monitors
  /// namespace's generic save-failure copy; a metrics-specific pair does not
  /// yet exist in `assets/lang/en.json`).
  void _notifySaveFailed(String? message) {
    Magic.error(
      trans('uptizm.monitors.toast_save_failed_title'),
      message ?? trans('uptizm.monitors.toast_save_failed_description'),
    );
  }
}
