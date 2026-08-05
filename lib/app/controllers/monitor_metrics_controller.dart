import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../resources/views/monitors/monitor_metrics_support.dart';

// ---------------------------------------------------------------------------
// Wire <-> form vocabulary maps.
//
// The Flutter metric form (`monitor_metrics_support.dart`'s `kMetricSources`/
// `kMetricUnits`) uses a shorter vocabulary (`'json'`, `'%'`, `'bytes'`, ...)
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
/// Every entry is a 1:1 pairing, which is what makes the round trip lossless.
/// A `req_s` (requests/sec) option used to sit here mapping to `custom`, and
/// because the reverse lookup returns the FIRST key with a matching value, a
/// metric saved as Custom decoded back as "req/s". The backend enum has no
/// throughput unit at all, so the option was unrepresentable in both directions
/// and was removed rather than left to corrupt the one that does work.
///
/// All sixteen `MetricUnit` cases (`backend/app/Enums/MetricUnit.php:21-38`)
/// are paired here, not just the six the form originally shipped with. Before
/// this map was completed, a metric saved as, say, `megabyte` (a value AI
/// discovery can propose) fell through `_unitFromWire`'s `'custom'` fallback
/// on decode, then got written back as literal `custom` on the next save: the
/// same lossy round trip `req_s` caused, in the opposite direction.
const Map<String, String> _unitToWire = {
  'bytes_auto': 'bytes_auto',
  'bytes': 'byte',
  'kb': 'kilobyte',
  'mb': 'megabyte',
  'gb': 'gigabyte',
  'tb': 'terabyte',
  'duration_auto': 'duration_auto',
  'ms': 'millisecond',
  's': 'second',
  'min': 'minute',
  'h': 'hour',
  '%': 'percent',
  'ratio': 'ratio',
  'count': 'count',
  'count_short': 'count_short',
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

  /// The latest reading for a `status`-typed metric, or null when it has never
  /// recorded one.
  ///
  /// Carried because the list used to render the LITERAL word "operational" for
  /// every status metric and "ok" for every string metric, regardless of what
  /// was extracted: a metric reading `down` displayed as operational.
  final String? latestStatus;

  /// The latest reading for a `string`-typed metric, or null when it has never
  /// recorded one.
  final String? latestString;

  /// The band the backend froze on the latest reading (`ok` / `warn` /
  /// `critical`), or null when the metric carries no thresholds.
  final String? latestBand;

  const MonitorMetricRecord({
    required this.id,
    required this.form,
    this.latestStatus,
    this.latestString,
    this.latestBand,
  });

  /// Builds a [MonitorMetricRecord] from a `MonitorMetricResource` payload
  /// (backend `api/v1` snake_case keys; see
  /// `backend/app/Http/Resources/MonitorMetricResource.php`).
  ///
  /// The optional nested `latest` block (`{numeric_value, ...}`) carries the
  /// most recent extracted reading, and stays NULL when the metric has never
  /// recorded one.
  ///
  /// Null is load-bearing: this used to default to `0`, so a rule that extracted
  /// nothing (a wrong path, an absent header) displayed `0` on the tab. For a
  /// latency or error-count metric `0` reads as perfect health, which is the
  /// opposite of "this rule is not working".
  factory MonitorMetricRecord.fromMap(Map<String, dynamic> map) {
    final Object? latest = map['latest'];
    final num? latestValue = latest is Map
        ? latest['numeric_value'] as num?
        : null;

    final Map<String, dynamic>? latestMap = latest is Map
        ? Map<String, dynamic>.from(latest)
        : null;

    return MonitorMetricRecord(
      id: map['id']?.toString() ?? '',
      latestStatus: latestMap?['status_value'] as String?,
      latestString: latestMap?['string_value'] as String?,
      latestBand: latestMap?['band'] as String?,
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
        okValues: _stringListFromWire(map['ok_values']),
        warnValues: _stringListFromWire(map['warn_values']),
        criticalValues: _stringListFromWire(map['critical_values']),
        unmatchedBand: (map['unmatched_band'] as String?) ?? '',
        value: latestValue,
      ),
    );
  }
}

/// Decodes a wire `ok_values`/`warn_values`/`critical_values` list into a
/// [List<String>], defaulting to an empty list when the key is absent or not
/// a list. Each element is coerced through `toString()` so a malformed
/// non-string element in the payload cannot throw mid-decode.
List<String> _stringListFromWire(Object? raw) {
  if (raw is! List) return const [];
  return raw.map((Object? e) => e.toString()).toList();
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

  /// Monitor ids whose catalog read has completed at least once, successfully or
  /// not.
  ///
  /// Per monitor id rather than one controller-wide flag, because the cache is
  /// keyed that way: having resolved monitor A says nothing about monitor B.
  final Set<String> _resolved = <String>{};

  /// Whether the FIRST catalog read for [monitorId] is still in flight.
  ///
  /// Separates "we have not asked yet" from "this monitor has no custom
  /// metrics". The tab renders a skeleton while this is true instead of showing
  /// its "no custom metrics" empty state over a pending read.
  ///
  /// Only the first read counts: a later refetch keeps the rows on screen rather
  /// than flashing a skeleton over data already on display.
  bool isFirstLoad(String monitorId) => !_resolved.contains(monitorId);

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
    // Seeded state is a resolved state, so a bound view renders the rows rather
    // than a skeleton waiting for a fetch the test never makes.
    _resolved.add(monitorId);
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
    } finally {
      // Resolved either way. A failed read must still end the skeleton, because a
      // screen that skeletons forever is worse than an honest empty state, and
      // the early `return`s above (non-2xx, malformed payload) reach this too.
      final bool firstLoad = isFirstLoad(monitorId);
      _resolved.add(monitorId);
      if (firstLoad) refreshUI();
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
    // Back to "not asked yet" for every monitor: the incoming identity must get a
    // skeleton, not the previous tenant's conclusion that a monitor has no
    // custom metrics.
    _resolved.clear();
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

  /// Tests [form]'s extraction rule for real via `POST
  /// /monitors/:id/metrics/preview`, returning what the backend actually
  /// extracted.
  ///
  /// The backend applies the rule to the monitor's most recent check, the same
  /// payload the extraction pipeline itself ran on, so a rule that previews
  /// cleanly will extract on the next check. It persists nothing.
  ///
  /// Returns `null` only when the round trip itself failed (transport error or a
  /// non-2xx), after surfacing the shared save-failed toast; a rule that simply
  /// did not resolve comes back as a [MetricPreviewResult] carrying its `error`,
  /// because "your path matched nothing" is an answer, not a failure.
  Future<MetricPreviewResult?> preview(String monitorId, MetricForm form) async {
    try {
      final response = await Http.post(
        '/monitors/$monitorId/metrics/preview',
        data: <String, dynamic>{
          'source': _sourceToWireValue(form.source),
          'extraction_path': form.path.isEmpty ? null : form.path,
          'type': form.type,
          if (form.type == 'numeric') ...<String, dynamic>{
            'threshold_direction': _directionToWireValue(form.direction),
            'warn_bound': num.tryParse(form.warn),
            'critical_bound': num.tryParse(form.critical),
          },
          // Type-gated exactly like [_toWirePayload], and load-bearing rather
          // than symmetric: the backend bands a string draft through the same
          // `bandString()` the pipeline freezes a band with, and it can only do
          // that from the lists this request carries. Without this branch the
          // panel asked for a verdict the server had no configuration to give,
          // so a string metric's `band` came back null forever.
          if (form.type == 'string') ...<String, dynamic>{
            'ok_values': form.okValues,
            'warn_values': form.warnValues,
            'critical_values': form.criticalValues,
            'unmatched_band': form.unmatchedBand.isEmpty
                ? null
                : form.unmatchedBand,
          },
        },
      );
      if (!response.successful) {
        Log.error(
          '[MonitorMetricsController.preview] $monitorId: '
          '${response.errorMessage}',
        );
        _notifySaveFailed(response.errorMessage);
        return null;
      }

      final Object? data = response.data;
      if (data is! Map<String, dynamic>) {
        Log.error('[MonitorMetricsController.preview] $monitorId: bad shape');
        _notifySaveFailed(null);
        return null;
      }

      return MetricPreviewResult.fromMap(data);
    } catch (error) {
      Log.error('[MonitorMetricsController.preview] $monitorId failed: $error');
      _notifySaveFailed(null);
      return null;
    }
  }

  /// The extraction candidates the backend proved against [monitorId]'s newest
  /// archived response, via `GET /monitors/:id/content/candidates`.
  ///
  /// Every row's `path` was generated by the backend's own candidate extractor
  /// and round-tripped through the extraction layer before it was offered, which
  /// is what makes filling a form field from one safe: the operator is choosing
  /// among paths that are already known to resolve.
  ///
  /// Returns null only when the round trip itself failed (a transport error or a
  /// non-2xx). Unlike [preview] this surfaces NO toast for that: the metric
  /// form's candidate panel reports the failure inline in its own words, and the
  /// shared save-failed copy would be both wrong (nothing was being saved) and a
  /// second announcement of the same event. A monitor with nothing archived is
  /// not a failure either: it answers `has_sample: false` with an empty list, so
  /// the panel can say "nothing captured yet" instead of "load failed".
  Future<MetricCandidateSet?> candidates(String monitorId) async {
    try {
      final response = await Http.get(
        '/monitors/$monitorId/content/candidates',
      );
      if (!response.successful) {
        Log.error(
          '[MonitorMetricsController.candidates] $monitorId: '
          '${response.errorMessage}',
        );
        return null;
      }

      final Object? data = response.data;
      if (data is! Map<String, dynamic>) {
        Log.error(
          '[MonitorMetricsController.candidates] $monitorId: bad shape',
        );
        return null;
      }

      return MetricCandidateSet.fromMap(data);
    } catch (error) {
      Log.error(
        '[MonitorMetricsController.candidates] $monitorId failed: $error',
      );
      return null;
    }
  }

  /// Reads the metric's recorded history via `GET
  /// /monitors/:id/metrics/:metricId/series`, newest last.
  ///
  /// Returns an empty list when the metric has never recorded a value, which the
  /// detail sheet renders as "no readings yet" rather than inventing a series.
  Future<List<MetricSeriesPoint>> series(
    String monitorId,
    String metricId, {
    String range = '24h',
  }) async {
    try {
      final response = await Http.get(
        '/monitors/$monitorId/metrics/$metricId/series?range=$range',
      );
      if (!response.successful) {
        Log.error(
          '[MonitorMetricsController.series] $monitorId/$metricId: '
          '${response.errorMessage}',
        );
        return const [];
      }

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return const [];

      return raw
          .whereType<Map<String, dynamic>>()
          .map(MetricSeriesPoint.fromMap)
          .toList();
    } catch (error) {
      Log.error(
        '[MonitorMetricsController.series] $monitorId/$metricId failed: $error',
      );
      return const [];
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /// Maps [form] to the backend `StoreMonitorMetricRequest`/update payload
  /// shape, translating the form's short vocabulary into the backend's
  /// `MetricSource`/`MetricUnit`/`ThresholdDirection` enum values.
  ///
  /// The threshold fields (`threshold_direction`, `warn_bound`,
  /// `critical_bound`) and the string-band fields (`ok_values`,
  /// `warn_values`, `critical_values`, `unmatched_band`) are TYPE-GATED: only
  /// one group is sent, matching which fields the declared [form.type] can
  /// actually use. Before this gate, every metric of every type carried
  /// `threshold_direction: 'high_bad'` regardless of type, so a `string` or
  /// `status` metric's stored row could never be distinguished from one that
  /// genuinely wanted a numeric high-bad direction.
  Map<String, dynamic> _toWirePayload(MetricForm form) {
    final Map<String, dynamic> payload = {
      'label': form.label,
      'key': form.key,
      'type': form.type,
      'source': _sourceToWireValue(form.source),
      'extraction_path': form.path.isEmpty ? null : form.path,
      'unit': _unitToWireValue(form.unit),
    };

    if (form.type == 'numeric') {
      payload['threshold_direction'] = _directionToWireValue(form.direction);
      payload['warn_bound'] = num.tryParse(form.warn);
      payload['critical_bound'] = num.tryParse(form.critical);
    }

    if (form.type == 'string') {
      payload['ok_values'] = form.okValues;
      payload['warn_values'] = form.warnValues;
      payload['critical_values'] = form.criticalValues;
      payload['unmatched_band'] =
          form.unmatchedBand.isEmpty ? null : form.unmatchedBand;
    }

    return payload;
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
  ///
  /// A dot-notation array-element key (e.g. `ok_values.1`, from the backend's
  /// `field.*` list validation) is collapsed onto its owning field
  /// (`ok_values`): the form renders one chip-list field per list, not one
  /// field per element, so an element-level key has nowhere else to land.
  /// When more than one element of the same list fails, the FIRST message
  /// encountered wins, matching the single-message-per-field contract every
  /// other field already has.
  Map<String, String> _fieldErrorsOrToast(MagicResponse response) {
    final Map<String, List<String>> errors = response.errors;
    if (errors.isNotEmpty) {
      final Map<String, String> fieldErrors = {};
      for (final MapEntry<String, List<String>> entry in errors.entries) {
        final String field = entry.key.split('.').first;
        fieldErrors.putIfAbsent(field, () => entry.value.first);
      }
      return fieldErrors;
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

// ---------------------------------------------------------------------------
// MetricPreviewResult: what the backend really extracted for a draft rule.
// ---------------------------------------------------------------------------

/// The outcome of `POST /monitors/:id/metrics/preview`.
///
/// Every field is the backend's answer, never a locally computed stand-in. The
/// form's test panel previously simulated this whole shape: it resolved the path
/// against a hardcoded sample map, fell back to a constant value per unit, and
/// reported "found" unconditionally for any non-JSON source, so it could confirm
/// a rule that the real pipeline could never extract.
@immutable
class MetricPreviewResult {
  /// The extracted value as the backend stringified it, or null when the rule
  /// resolved nothing.
  final String? value;

  /// Whether the extracted value matches the metric's declared type. False for,
  /// say, a numeric metric pointed at a sentence.
  final bool typeValid;

  /// Why the rule resolved nothing, in the backend's own words, or null on a
  /// clean extraction.
  final String? error;

  /// The band the value would land in (`ok` / `warn` / `critical`), or null when
  /// the draft carries no thresholds to band against.
  final String? band;

  /// Whether there was any sample to test against at all. False means the
  /// monitor has never been checked, which is not an extraction failure.
  final bool hasSample;

  /// When the check used as the sample ran, so the panel can name its evidence
  /// instead of implying it just fetched the endpoint.
  final DateTime? sampleCheckedAt;

  /// The sample's HTTP status code, shown alongside the provenance line.
  final int? sampleStatusCode;

  const MetricPreviewResult({
    required this.value,
    required this.typeValid,
    required this.error,
    required this.band,
    required this.hasSample,
    required this.sampleCheckedAt,
    required this.sampleStatusCode,
  });

  /// Decodes the preview endpoint's flat JSON body.
  factory MetricPreviewResult.fromMap(Map<String, dynamic> map) {
    final Object? checkedAt = map['sample_checked_at'];

    return MetricPreviewResult(
      value: map['extracted_value']?.toString(),
      typeValid: map['type_valid'] == true,
      error: map['error'] as String?,
      band: map['band'] as String?,
      // Absent defaults to true so an older backend that predates the flag is
      // read as "a sample was used", matching its behaviour.
      hasSample: map['has_sample'] != false,
      sampleCheckedAt: checkedAt is String
          ? DateTime.tryParse(checkedAt)
          : null,
      sampleStatusCode: (map['sample_status_code'] as num?)?.toInt(),
    );
  }

  /// Whether the rule resolved a usable value of the declared type.
  bool get resolved => value != null && typeValid && error == null;
}

// ---------------------------------------------------------------------------
// MetricCandidate: one extraction rule the last response was proved to satisfy.
// ---------------------------------------------------------------------------

/// One row of `GET /monitors/:id/content/candidates`.
///
/// The backend walked the monitor's newest archived body, generated a candidate
/// per value it found, and kept only the ones whose path it could resolve again
/// through the real extraction layer. So a candidate is a proved rule, not a
/// guess, which is why the metric form may fill [MetricForm.source],
/// [MetricForm.path] and [MetricForm.type] straight from one.
///
/// [value] and [label] are ATTACKER-CONTROLLED substrings of a monitored
/// response, already truncated by the backend. They are inert text: render them
/// through a plain `WText` and nothing else. Routing either through a markup,
/// markdown or URL renderer is the one client change that would invalidate the
/// security argument recorded on the endpoint that serves them.
@immutable
class MetricCandidate {
  /// The backend's own handle for this candidate (`c1`, `c2`, ...).
  ///
  /// Refs are NOT contiguous: a candidate whose path exceeds the write path's
  /// length limit is dropped rather than renumbered, so the discovery path and
  /// this endpoint keep agreeing on which candidate a given ref names.
  final String ref;

  /// The extraction source, already translated into the FORM's short
  /// vocabulary (`json`, `header`, ...) rather than the backend enum value.
  final String source;

  /// The extraction path the backend proved resolves against that body.
  final String path;

  /// The sample value at [path], truncated by the backend. Inert text.
  final String value;

  /// A human hint for the value (a JSON key, an HTML label), when the backend
  /// found one short enough to be useful. Inert text.
  final String? label;

  /// The metric types this candidate's value is eligible for, in the backend's
  /// preference order. Shares the wire vocabulary with [MetricForm.type].
  final List<String> types;

  const MetricCandidate({
    required this.ref,
    required this.source,
    required this.path,
    required this.value,
    required this.label,
    required this.types,
  });

  /// Decodes one `MetricCandidate::toDigestRow()` row.
  ///
  /// `label` is optional in that projection (a null hint is omitted entirely),
  /// and every field is read defensively through `toString()` because these
  /// values originate in a monitored third-party response.
  factory MetricCandidate.fromMap(Map<String, dynamic> map) {
    final Object? types = map['types'];

    return MetricCandidate(
      ref: map['ref']?.toString() ?? '',
      source: _sourceFromWire(map['src'] as String?),
      path: map['path']?.toString() ?? '',
      value: map['value']?.toString() ?? '',
      label: map['label']?.toString(),
      types: types is List
          ? types.map((Object? e) => e.toString()).toList()
          : const [],
    );
  }
}

/// The candidate endpoint's envelope: the rows plus whether there was any
/// archived response to read at all.
///
/// [hasSample] separates "this monitor has never archived a body" from "the body
/// held nothing extractable". Both answer an empty [candidates] list, and an
/// operator needs to know which one they are looking at: the first asks them to
/// run a check, the second asks them to look at their endpoint.
@immutable
class MetricCandidateSet {
  /// The proved candidates, in the backend's ranking order.
  final List<MetricCandidate> candidates;

  /// Whether an archived response was read at all.
  final bool hasSample;

  const MetricCandidateSet({
    required this.candidates,
    required this.hasSample,
  });

  /// Decodes the `{data: [...], has_sample: bool}` envelope.
  factory MetricCandidateSet.fromMap(Map<String, dynamic> map) {
    final Object? rows = map['data'];

    return MetricCandidateSet(
      candidates: rows is List
          ? rows
                .whereType<Map<String, dynamic>>()
                .map(MetricCandidate.fromMap)
                .toList()
          : const [],
      // Absent reads as false rather than true: the flag ships with the only
      // endpoint that produces this shape, so a payload without it is malformed,
      // and claiming a sample existed would send the operator looking at their
      // endpoint for a body nothing ever recorded.
      hasSample: map['has_sample'] == true,
    );
  }
}

// ---------------------------------------------------------------------------
// MetricSeriesPoint: one recorded reading.
// ---------------------------------------------------------------------------

/// A single persisted reading from `GET /monitors/:id/metrics/:metricId/series`.
///
/// This replaces a locally generated sine wave: the detail sheet used to build 24
/// points as `base + sin(i / 3) * base * 0.18`, inject an anomaly at a fixed
/// index, and read its "latest value" off the last fake point, so it contradicted
/// the real reading the list showed.
@immutable
class MetricSeriesPoint {
  /// When the reading was recorded.
  final DateTime? recordedAt;

  /// The numeric reading, or null for a status/string metric.
  final num? numericValue;

  /// The status reading, for a `status`-typed metric.
  final String? statusValue;

  /// The string reading, for a `string`-typed metric.
  final String? stringValue;

  /// The band frozen at insert time (`ok` / `warn` / `critical`), or null when
  /// the metric carried no thresholds when this reading landed.
  final String? band;

  const MetricSeriesPoint({
    required this.recordedAt,
    required this.numericValue,
    required this.statusValue,
    required this.stringValue,
    required this.band,
  });

  /// Decodes one point from the series payload.
  factory MetricSeriesPoint.fromMap(Map<String, dynamic> map) {
    final Object? at = map['recorded_at'];

    return MetricSeriesPoint(
      recordedAt: at is String ? DateTime.tryParse(at) : null,
      numericValue: map['numeric_value'] as num?,
      statusValue: map['status_value'] as String?,
      stringValue: map['string_value'] as String?,
      band: map['band'] as String?,
    );
  }
}
