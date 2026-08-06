import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/support/monitor_types.dart' show ProbeRegion;
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';
import 'package:uptizm/ui/components/key_value_editor/key_value_editor.dart';
import 'package:uptizm/ui/components/region_picker/region_picker.dart';

// ---------------------------------------------------------------------------
// Option-list constants (label / value pairs for dropdowns and segmented
// controls in the monitor create/edit form).
//
// Values match the TYPES / METHODS / INTERVALS / INTERVAL_SECONDS /
// SLO_TARGETS constants in the React MonitorForm.tsx source.
// ---------------------------------------------------------------------------

/// Monitor check types.
///
/// Used to populate the type segmented control on the monitor form. Scoped to
/// the two protocols the backend + regional checker support end to end: HTTP
/// (request at the URL) and TCP (socket connect to host:port). Ping / keyword /
/// SSL are on the product roadmap (see docs/uptizm-system/product.md) but are
/// NOT wired through the `MonitorType` enum or the worker, so offering them here
/// only produced a silent 422 on submit. Add a type back only once its whole
/// path (enum, request validation, worker probe) exists.
const List<MetricOption> kMonitorTypes = [
  MetricOption(label: 'HTTP', value: 'http'),
  MetricOption(label: 'TCP', value: 'tcp'),
];

/// HTTP request methods for the advanced section. Matches `METHODS` in
/// MonitorForm.tsx.
const List<MetricOption> kHttpMethods = [
  MetricOption(label: 'GET', value: 'get'),
  MetricOption(label: 'POST', value: 'post'),
  MetricOption(label: 'HEAD', value: 'head'),
];

/// Check interval options shown in the interval select. Matches `INTERVALS` in
/// MonitorForm.tsx.
///
/// A getter (not a `const`) so each display label resolves through [trans] at
/// the current locale; the [MetricOption.value] wire tokens stay fixed.
List<MetricOption> get kCheckIntervals => [
  MetricOption(label: trans('uptizm.monitors.interval_10s'), value: '10s'),
  MetricOption(label: trans('uptizm.monitors.interval_30s'), value: '30s'),
  MetricOption(label: trans('uptizm.monitors.interval_1m'), value: '1m'),
  // 3m is the Free tier's own interval floor and what every seeded monitor
  // uses, so leaving it out made a Free monitor's real interval unrepresentable
  // in its own edit form (and every cheaper option is locked for that tier).
  MetricOption(label: trans('uptizm.monitors.interval_3m'), value: '3m'),
  MetricOption(label: trans('uptizm.monitors.interval_5m'), value: '5m'),
];

/// Maps each interval token to its equivalent duration in seconds. Matches
/// `INTERVAL_SECONDS` in MonitorForm.tsx.
///
/// ```dart
/// final seconds = kIntervalSeconds['1m']; // 60
/// ```
const Map<String, int> kIntervalSeconds = {
  '10s': 10,
  '30s': 30,
  '1m': 60,
  '3m': 180,
  '5m': 300,
};

/// The interval token whose duration is exactly [seconds], or `null` when no
/// option matches.
///
/// Exact-match on purpose. The edit form uses this to show a monitor's real
/// interval, and snapping to the nearest option would quietly rewrite the
/// operator's configuration on the next save, which is the failure this helper
/// exists to prevent. A `null` answer means "this interval needs its own
/// option" and the caller renders it verbatim instead.
String? intervalTokenForSeconds(int seconds) {
  for (final MapEntry<String, int> entry in kIntervalSeconds.entries) {
    if (entry.value == seconds) {
      return entry.key;
    }
  }
  return null;
}

/// Projects a `request_headers` wire map into the editor's ordered row shape.
///
/// An empty map yields an empty list, never a placeholder row. The form's
/// create-time default used to carry an illustrative `Authorization: Bearer …`
/// row and that placeholder reached real probes; the default is empty now, and
/// this function stays explicit about it because a header the operator never
/// typed must never be sent to their endpoint.
List<KeyValueRow> keyValueRowsFromMap(Map<String, dynamic> headers) {
  return [
    for (final MapEntry<String, dynamic> entry in headers.entries)
      KeyValueRow(key: entry.key, value: entry.value?.toString() ?? ''),
  ];
}

/// SLO target options for the uptime SLO select. Matches `SLO_TARGETS` in
/// MonitorForm.tsx. An empty [MetricOption.value] means no SLO target is set.
List<MetricOption> get kSloTargets => [
  MetricOption(label: trans('uptizm.monitors.slo_target_none'), value: ''),
  MetricOption(label: trans('uptizm.monitors.slo_target_999'), value: '99.9'),
  MetricOption(label: trans('uptizm.monitors.slo_target_9995'), value: '99.95'),
  MetricOption(label: trans('uptizm.monitors.slo_target_9999'), value: '99.99'),
];

/// AI-assist mode options for the monitor form's `ai_mode` control.
///
/// Only `off`/`suggest` ship; `auto` (fully autonomous incident creation) is
/// deferred, so it is deliberately absent from this list.
List<MetricOption> get kAiModes => [
  MetricOption(label: trans('uptizm.monitors.ai_mode_off'), value: 'off'),
  MetricOption(label: trans('uptizm.monitors.ai_mode_suggest'), value: 'suggest'),
];

// ---------------------------------------------------------------------------
// Monitor credentials (the `auth_config` map).
//
// The wire vocabulary is the backend `HttpAuthType` enum and the inner shape
// `ValidatesAuthConfig::authConfigRules()` validates. Everything below mirrors
// that contract client-side so an operator is told about a missing or oversized
// credential field here rather than by a 422 after the round trip.
// ---------------------------------------------------------------------------

/// `auth_config.type` for "send no credential" (backend `HttpAuthType::None`).
///
/// A real enum case rather than the absence of the field, which is what makes
/// "the operator switched this monitor to None" distinguishable from "the
/// operator did not touch the credential at all".
const String kAuthTypeNone = 'none';

/// `auth_config.type` for HTTP basic auth (username + password).
const String kAuthTypeBasic = 'basic';

/// `auth_config.type` for a bearer token.
const String kAuthTypeBearer = 'bearer';

/// `auth_config.type` for an API key sent in a named header.
const String kAuthTypeApiKey = 'api_key';

/// Length bound for `username`, `password` and the api_key HEADER NAME.
///
/// Mirrors `max:255` in `ValidatesAuthConfig::authConfigRules()`; these values
/// travel inside the HMAC-signed relay spec, so the bound is part of the
/// contract rather than a cosmetic limit.
const int kAuthShortFieldMax = 255;

/// Length bound for `token` and `key` (`max:2048` on the backend, because a
/// JWT is routinely over 1KB).
const int kAuthLongFieldMax = 2048;

/// Auth-scheme options for the credential segmented control.
///
/// Four options, not three: `none` is a real backend enum case, so picking it
/// is a deliberate "clear this monitor's credential" rather than a way of
/// leaving the field alone.
List<MetricOption> get kHttpAuthTypes => [
  MetricOption(label: trans('uptizm.monitors.form_auth_type_none'), value: kAuthTypeNone),
  MetricOption(label: trans('uptizm.monitors.form_auth_type_basic'), value: kAuthTypeBasic),
  MetricOption(label: trans('uptizm.monitors.form_auth_type_bearer'), value: kAuthTypeBearer),
  MetricOption(label: trans('uptizm.monitors.form_auth_type_api_key'), value: kAuthTypeApiKey),
];

/// The credential an operator composed in the form, in the shape the backend's
/// `auth_config` map expects.
///
/// Immutable, so a screen can compare the current value against the one it
/// seeded and answer the question the edit path turns on: did the operator
/// touch this credential at all?
///
/// Whether an instance carries a secret is decided by WHICH seed built it, and
/// never inferred from the secret being non-empty. [MonitorCredential.fromRedactedMap]
/// describes a STORED credential: `MonitorResource` emits only `type`,
/// `username` and `header` (a fail-closed allowlist), so [password] / [token] /
/// [key] stay empty until the operator retypes one.
/// [MonitorCredential.fromPendingMap] describes one the operator TYPED and has
/// not saved yet, so its secret is present and has to travel.
@immutable
class MonitorCredential {
  /// The auth scheme (`none` / `basic` / `bearer` / `api_key`).
  final String type;

  /// Basic-auth username. Not a secret: the backend echoes it back.
  final String username;

  /// Basic-auth password. Empty on a REDACTED seed until the operator types one.
  final String password;

  /// Bearer token. Empty on a REDACTED seed until the operator types one.
  final String token;

  /// API key value. Empty on a REDACTED seed until the operator types one.
  final String key;

  /// The header name an api_key travels in. Not a secret.
  final String header;

  /// Creates a [MonitorCredential]. Defaults to "no authentication".
  const MonitorCredential({
    this.type = kAuthTypeNone,
    this.username = '',
    this.password = '',
    this.token = '',
    this.key = '',
    this.header = '',
  });

  /// Seeds a credential from the REDACTED `auth_config` a monitor carries.
  ///
  /// Only the three non-secret descriptors survive `MonitorResource`, so this
  /// deliberately fills nothing else: rendering a masked placeholder in the
  /// secret field would be a value the form then submits as a literal.
  factory MonitorCredential.fromRedactedMap(Map<String, dynamic>? config) {
    if (config == null) return const MonitorCredential();

    return MonitorCredential(
      type: config['type'] as String? ?? kAuthTypeNone,
      username: config['username'] as String? ?? '',
      header: config['header'] as String? ?? '',
    );
  }

  /// Seeds a credential the operator TYPED in this session and has not saved
  /// yet, secret included.
  ///
  /// The deliberate counterpart to [fromRedactedMap], and a separate
  /// constructor rather than a smarter version of it, because the two seeds
  /// mean opposite things and the whole edit contract rests on telling them
  /// apart: a REDACTED seed describes a credential the backend holds and
  /// withheld the secret of, so leaving the secret blank must leave the stored
  /// one alone; a PENDING seed is a credential nothing has stored yet, so its
  /// secret has to travel with the create request or the monitor's first check
  /// answers 401.
  ///
  /// The distinction is named here rather than inferred from "the secret string
  /// happens to be non-empty": an operator who deliberately types an empty
  /// password is still composing a pending credential, and an inference would
  /// read that as a stored one.
  ///
  /// [config] is the same wire map [toWireMap] produces, so a credential can be
  /// handed from the screen that probed with it to the screen that saves it
  /// without a lossy detour: `null` (no credential) round-trips as
  /// [kAuthTypeNone].
  factory MonitorCredential.fromPendingMap(Map<String, dynamic>? config) {
    if (config == null) return const MonitorCredential();

    return MonitorCredential(
      type: config['type'] as String? ?? kAuthTypeNone,
      username: config['username'] as String? ?? '',
      password: config['password'] as String? ?? '',
      token: config['token'] as String? ?? '',
      key: config['key'] as String? ?? '',
      header: config['header'] as String? ?? '',
    );
  }

  /// Returns a copy with the given fields replaced.
  MonitorCredential copyWith({
    String? type,
    String? username,
    String? password,
    String? token,
    String? key,
    String? header,
  }) {
    return MonitorCredential(
      type: type ?? this.type,
      username: username ?? this.username,
      password: password ?? this.password,
      token: token ?? this.token,
      key: key ?? this.key,
      header: header ?? this.header,
    );
  }

  /// Whether this credential sends nothing.
  bool get isNone => type == kAuthTypeNone;

  /// The secret the ACTIVE scheme needs, or `''` when the scheme needs none.
  ///
  /// Empty on a REDACTED seed, which is the signal the edit path reads as
  /// "the operator did not retype this credential".
  String get secret => switch (type) {
    kAuthTypeBasic => password,
    kAuthTypeBearer => token,
    kAuthTypeApiKey => key,
    _ => '',
  };

  /// Projects this credential into the `auth_config` wire map, or `null` for
  /// [kAuthTypeNone] (which the backend stores as "no credential").
  ///
  /// Only the keys the active scheme uses are emitted, so switching from basic
  /// to bearer never smuggles the abandoned password onto the wire.
  ///
  /// A pasted [token] or [key] is trimmed, because trailing whitespace off a
  /// clipboard is a paste artifact and not part of the secret. A [password] is
  /// NOT: a space at either end of a password can be deliberate, and silently
  /// eating it would authenticate as something the operator did not type.
  Map<String, dynamic>? toWireMap() {
    return switch (type) {
      kAuthTypeBasic => {
        'type': kAuthTypeBasic,
        'username': username.trim(),
        'password': password,
      },
      kAuthTypeBearer => {
        'type': kAuthTypeBearer,
        'token': token.trim(),
      },
      kAuthTypeApiKey => {
        'type': kAuthTypeApiKey,
        'key': key.trim(),
        'header': header.trim(),
      },
      _ => null,
    };
  }

  @override
  bool operator ==(Object other) {
    return other is MonitorCredential &&
        other.type == type &&
        other.username == username &&
        other.password == password &&
        other.token == token &&
        other.key == key &&
        other.header == header;
  }

  @override
  int get hashCode => Object.hash(type, username, password, token, key, header);
}

/// The "this field is required" message for each credential field, keyed by the
/// wire field name so [validateMonitorCredential] needs no second switch on the
/// scheme (the wire map already decides which fields apply).
const Map<String, String> _authRequiredMessageKeys = {
  'username': 'uptizm.monitors.form_auth_error_username_required',
  'password': 'uptizm.monitors.form_auth_error_password_required',
  'token': 'uptizm.monitors.form_auth_error_token_required',
  'key': 'uptizm.monitors.form_auth_error_key_required',
  'header': 'uptizm.monitors.form_auth_error_header_required',
};

/// Validates [credential] against the same bounds the backend applies, keyed by
/// the WIRE field name (`username`, `password`, `token`, `key`, `header`) so a
/// server 422 on the same field lands in the same slot.
///
/// It validates the map that will actually be SENT rather than the state behind
/// it, so the client-side check cannot drift from
/// [MonitorCredential.toWireMap]: every key that map carries is required, which
/// is exactly what `required_if:auth_config.type,...` says on the backend.
///
/// Only ever called for a credential the form is about to send: an untouched
/// edit omits `auth_config` entirely, and there is nothing to validate about a
/// credential nobody submitted. A required secret is therefore a real
/// requirement rather than an unhelpful demand: the stored one cannot be shown
/// (see [MonitorCredential.fromRedactedMap]), so changing a credential means
/// typing it again.
Map<String, String> validateMonitorCredential(MonitorCredential credential) {
  final Map<String, dynamic>? wire = credential.toWireMap();
  if (wire == null) return const <String, String>{};

  final Map<String, String> errors = {};
  for (final MapEntry<String, dynamic> entry in wire.entries) {
    final String? messageKey = _authRequiredMessageKeys[entry.key];
    if (messageKey == null) continue;

    final String value = entry.value as String;
    final int max = entry.key == 'token' || entry.key == 'key'
        ? kAuthLongFieldMax
        : kAuthShortFieldMax;

    if (value.trim().isEmpty) {
      errors[entry.key] = trans(messageKey);
    } else if (value.length > max) {
      errors[entry.key] = trans('uptizm.monitors.form_auth_error_too_long', {
        'max': '$max',
      });
    }
  }

  return errors;
}

/// **The credential block: an auth-scheme picker plus the fields it needs.**
///
/// One widget rather than one per screen, because the same block belongs on
/// every surface that composes an `auth_config`: [MonitorForm] already serves
/// manual create, AI review and edit, and the AI setup step probes the same
/// protected endpoint.
///
/// The secret input is rendered EMPTY on an edit, with a placeholder saying a
/// credential is stored. That is the honest affordance: `MonitorResource`'s
/// allowlist never returns the stored secret, so a masked placeholder would be
/// a lie the form then submits as a literal password.
///
/// ```dart
/// MonitorCredentialFields(
///   value: credential,
///   hasStoredSecret: true,
///   onChanged: (next) => setState(() => credential = next),
/// )
/// ```
@immutable
class MonitorCredentialFields extends StatelessWidget {
  /// The credential currently composed (controlled by the caller).
  final MonitorCredential value;

  /// Called with the next credential whenever the operator edits a field.
  final ValueChanged<MonitorCredential> onChanged;

  /// Whether leaving the secret blank keeps a credential the backend already
  /// holds. True only when editing a monitor whose stored scheme is still the
  /// selected one; switching the scheme means the stored secret no longer
  /// applies and the field is required again.
  final bool hasStoredSecret;

  /// Inline errors keyed by the wire field name (`username`, `password`,
  /// `token`, `key`, `header`, or `type` for a whole-map rejection).
  final Map<String, String> errors;

  /// Creates a [MonitorCredentialFields].
  const MonitorCredentialFields({
    super.key,
    required this.value,
    required this.onChanged,
    this.hasStoredSecret = false,
    this.errors = const <String, String>{},
  });

  @override
  Widget build(BuildContext context) {
    final List<MetricOption> options = kHttpAuthTypes;
    final int selected = options.indexWhere((o) => o.value == value.type);

    return WDiv(
      className: 'flex flex-col gap-5',
      children: [
        MSFormField(
          label: trans('uptizm.monitors.form_auth_label'),
          hint: trans('uptizm.monitors.form_auth_hint'),
          error: errors['type'],
          child: MSSegmentedControl<String>(
            options: options.map((o) => o.label).toList(),
            selectedIndex: selected < 0 ? 0 : selected,
            onChanged: (index) => onChanged(
              value.copyWith(type: options[index].value),
            ),
          ),
        ),
        if (value.type == kAuthTypeBasic)
          MSFormField(
            label: trans('uptizm.monitors.form_auth_username_label'),
            error: errors['username'],
            child: MSInput(
              value: value.username,
              onChanged: (next) => onChanged(value.copyWith(username: next)),
              placeholder: trans(
                'uptizm.monitors.form_auth_username_placeholder',
              ),
            ),
          ),
        if (value.type == kAuthTypeBasic)
          _buildSecretField(
            label: trans('uptizm.monitors.form_auth_password_label'),
            error: errors['password'],
            secret: value.password,
            onSecretChanged: (next) => onChanged(
              value.copyWith(password: next),
            ),
          ),
        if (value.type == kAuthTypeBearer)
          _buildSecretField(
            label: trans('uptizm.monitors.form_auth_token_label'),
            error: errors['token'],
            secret: value.token,
            onSecretChanged: (next) => onChanged(value.copyWith(token: next)),
          ),
        if (value.type == kAuthTypeApiKey)
          MSFormField(
            label: trans('uptizm.monitors.form_auth_header_label'),
            error: errors['header'],
            child: MSInput(
              value: value.header,
              onChanged: (next) => onChanged(value.copyWith(header: next)),
              placeholder: trans('uptizm.monitors.form_auth_header_placeholder'),
            ),
          ),
        if (value.type == kAuthTypeApiKey)
          _buildSecretField(
            label: trans('uptizm.monitors.form_auth_key_label'),
            error: errors['key'],
            secret: value.key,
            onSecretChanged: (next) => onChanged(value.copyWith(key: next)),
          ),
      ],
    );
  }

  /// Builds one obscured secret field.
  ///
  /// The placeholder is the only thing that says a credential is stored; the
  /// value stays empty, so submitting an untouched form cannot post the
  /// placeholder as the new secret.
  Widget _buildSecretField({
    required String label,
    required String? error,
    required String secret,
    required ValueChanged<String> onSecretChanged,
  }) {
    return MSFormField(
      label: label,
      error: error,
      child: MSInput(
        value: secret,
        onChanged: onSecretChanged,
        type: InputType.password,
        placeholder: hasStoredSecret
            ? trans('uptizm.monitors.form_auth_secret_stored_placeholder')
            : null,
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// AI analyze-step strings and suggested metric seeds.
// Matches ANALYZE_STEPS and aiMetrics in MonitorCreatePage.tsx.
// ---------------------------------------------------------------------------

/// Ordered list of status strings shown while the AI probes the endpoint.
///
/// A getter (not a `const`) so each step resolves through [trans] at the
/// current locale. Matches `ANALYZE_STEPS` in MonitorCreatePage.tsx.
List<String> get kAnalyzeSteps => [
  trans('uptizm.monitors.create_ai_step_1'),
  trans('uptizm.monitors.create_ai_step_2'),
  trans('uptizm.monitors.create_ai_step_3'),
  trans('uptizm.monitors.create_ai_step_4'),
  trans('uptizm.monitors.create_ai_step_5'),
];

/// A single AI-suggested metric seed carrying label, key, type, unit, source,
/// path, raw warn/critical threshold strings, and a sample value.
///
/// Mirrors the backend `suggested_metrics` wire shape (see
/// `MetricDiscoveryService::toWireRows()`): every entry's `path` was generated
/// and proven evaluable by the backend, the model only selects among
/// candidates, it never authors a path. Threshold strings are kept raw so the
/// metric form can display them without a lossy parse/round-trip.
class AiMetricSeed {
  /// Human-readable label, e.g. `"p95 latency"`.
  final String label;

  /// Machine key used in API payloads, e.g. `"p95_ms"`.
  final String key;

  /// The metric's value type, e.g. `"numeric"`.
  final String type;

  /// Measurement unit string, e.g. `"ms"`.
  final String unit;

  /// Data source format, e.g. `"json"`.
  final String source;

  /// JSONPath or equivalent extraction path.
  final String path;

  /// Raw warning threshold string; empty when not applicable.
  final String warn;

  /// Raw critical threshold string; empty when not applicable.
  final String critical;

  /// The sample value the backend showed the model when it proposed this
  /// metric, e.g. `"120"`.
  final String sampleValue;

  /// Which side of a bound is bad (`high_bad` / `low_bad`), or `''` when the
  /// metric carries no numeric threshold.
  ///
  /// Travels under its column name and is load-bearing rather than decorative:
  /// `ThresholdEvaluator::numericBreach()` cannot band a reading without it, so
  /// a numeric metric created with `warn_bound` and no direction records every
  /// check and breaches on none of them, while the review screen says "warn at
  /// 400".
  final String thresholdDirection;

  /// Observed string values that read as healthy, e.g. `["ok"]`.
  ///
  /// The three band lists are the one part of the wire shape that already
  /// arrives under its COLUMN name, because there is no form vocabulary for a
  /// value list to translate out of. Empty on every numeric metric.
  final List<String> okValues;

  /// Observed string values that read as degraded.
  final List<String> warnValues;

  /// Observed string values that read as failing.
  final List<String> criticalValues;

  /// Creates an [AiMetricSeed].
  const AiMetricSeed({
    required this.label,
    required this.key,
    required this.type,
    required this.unit,
    required this.source,
    required this.path,
    required this.warn,
    required this.critical,
    required this.sampleValue,
    this.thresholdDirection = '',
    this.okValues = const [],
    this.warnValues = const [],
    this.criticalValues = const [],
  });

  /// This seed as one row of `POST /monitors`'s `metrics[]`.
  ///
  /// THREE vocabularies meet here and the rename is the whole reason this
  /// method exists rather than the caller spreading the seed. The analyze
  /// response speaks a WIRE shape (`path`, `warn`, `critical`), the metric form
  /// speaks a form shape, and the write endpoint speaks the COLUMN shape
  /// (`extraction_path`, `warn_bound`, `critical_bound`). Every one of those
  /// three columns is `nullable` in the backend rules, so sending the wire name
  /// is not a 422: it is a metric that extracts nothing, forever, silently.
  ///
  /// The two bounds are parsed to numbers or OMITTED. An empty `warn` must not
  /// become `0`, which would be a metric that warns on every reading, and must
  /// not become `""`, which fails the `numeric` rule.
  ///
  /// `source` is already backend vocabulary on the wire (`json_path`, not
  /// `json`), so it passes through untouched. Do NOT route a row through
  /// `monitor_metrics_controller.dart`'s form-vocabulary translator, which
  /// exists to convert the other direction and would map `json_path` to
  /// nothing.
  ///
  /// `display_order` and `unmatched_band` are deliberately absent: the server
  /// stamps the first from the array index and pins the second itself.
  Map<String, dynamic> toCreateRow() {
    final double? warnBound = double.tryParse(warn);
    final double? criticalBound = double.tryParse(critical);

    return {
      'key': key,
      'label': label,
      'type': type,
      if (source.isNotEmpty) 'source': source,
      if (path.isNotEmpty) 'extraction_path': path,
      if (unit.isNotEmpty) 'unit': unit,
      if (thresholdDirection.isNotEmpty)
        'threshold_direction': thresholdDirection,
      'warn_bound': ?warnBound,
      'critical_bound': ?criticalBound,
      if (okValues.isNotEmpty) 'ok_values': okValues,
      if (warnValues.isNotEmpty) 'warn_values': warnValues,
      if (criticalValues.isNotEmpty) 'critical_values': criticalValues,
    };
  }

  /// Decodes an [AiMetricSeed] from one entry of the backend's
  /// `suggested_metrics` array.
  ///
  /// Every field defaults to `''` rather than throwing on a missing or
  /// unexpected wire value, matching [MonitorAnalysis.fromMap]'s stale-client
  /// convention. `warn`/`critical` arrive as a nullable number on the wire but
  /// are kept as raw strings here (see the class docblock), so a numeric
  /// value is stringified and a `null` degrades to `''` rather than `"null"`.
  factory AiMetricSeed.fromMap(Map<String, dynamic> map) {
    return AiMetricSeed(
      label: map['label'] as String? ?? '',
      key: map['key'] as String? ?? '',
      type: map['type'] as String? ?? '',
      unit: map['unit'] as String? ?? '',
      source: map['source'] as String? ?? '',
      path: map['path'] as String? ?? '',
      warn: _wireThresholdToString(map['warn']),
      critical: _wireThresholdToString(map['critical']),
      sampleValue: map['sample_value'] as String? ?? '',
      thresholdDirection: map['threshold_direction'] as String? ?? '',
      okValues: _wireValueList(map['ok_values']),
      warnValues: _wireValueList(map['warn_values']),
      criticalValues: _wireValueList(map['critical_values']),
    );
  }
}

/// Reads one of the three string-band lists off the wire.
///
/// Absent on every numeric metric and on any backend older than the band
/// channel, so a missing key is an empty list rather than an error. Non-string
/// elements are dropped instead of stringified: the write endpoint validates
/// each item as a string, and a `42` silently becoming `"42"` here would
/// configure a band the operator never saw.
List<String> _wireValueList(Object? value) {
  if (value is! List) return const [];

  return value.whereType<String>().toList();
}

/// Stringifies a wire `warn`/`critical` threshold (a nullable number) into
/// the raw string [AiMetricSeed] keeps for display, defaulting a missing or
/// unexpected value to `''` rather than the literal `"null"`.
String _wireThresholdToString(Object? value) {
  if (value is num) return value.toString();
  if (value is String) return value;
  return '';
}

// ---------------------------------------------------------------------------
// Pure helper functions.
// ---------------------------------------------------------------------------

/// Derives a display name from [url] by stripping the scheme and any path,
/// leaving only the bare hostname.
///
/// Returns `"New monitor"` when [url] is blank or yields an empty host after
/// stripping.
///
/// ```dart
/// aiNameFromUrl('https://api.example.com/health') // 'api.example.com'
/// aiNameFromUrl('')                                // 'New monitor'
/// aiNameFromUrl('not-a-url')                       // 'not-a-url'
/// ```
///
/// Matches the `aiName` derivation in MonitorCreatePage.tsx:
/// ```
/// url.replace(/^https?:\/\//, "").replace(/\/.*$/, "")
/// ```
String aiNameFromUrl(String url) {
  if (url.isEmpty) return trans('uptizm.monitors.new_monitor');

  // 1. Strip leading scheme (http:// or https://).
  final String withoutScheme = url.replaceFirst(RegExp(r'^https?://'), '');

  // 2. Strip path (everything from the first `/` onward).
  final String host = withoutScheme.replaceFirst(RegExp(r'/.*$'), '');

  return host.isEmpty ? trans('uptizm.monitors.new_monitor') : host;
}

/// Maps [src] (a list of [ProbeRegion] mocks) to [Region] instances expected
/// by [RegionPicker].
///
/// The field mapping is direct: [ProbeRegion.label] -> [Region.label],
/// [ProbeRegion.value] -> [Region.value], [ProbeRegion.flag] -> [Region.flag].
///
/// ```dart
/// final regions = probeRegionsToRegions(allRegions);
/// RegionPicker(regions: regions, value: selected, onChanged: _onChanged);
/// ```
List<Region> probeRegionsToRegions(List<ProbeRegion> src) {
  return [
    for (final ProbeRegion r in src)
      Region(
        label: r.label,
        value: r.value,
        flag: r.flag,
      ),
  ];
}
