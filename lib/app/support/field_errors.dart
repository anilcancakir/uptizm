// Shared field-error mapping, replacing seven private near-duplicates.
//
// `monitor_controller.dart`, `monitor_metrics_controller.dart` and five other
// controllers each carried their own private `_fieldErrorsOrToast`, one
// reading a `Model`'s `validationErrors` and one reading a `MagicResponse`'s
// `errors`. The two copies had already drifted:
// `monitor_metrics_controller.dart` collapsed a dot-notation key with
// `entry.key.split('.').first`, which reads the bulk metric endpoint's
// `metrics.0.ok_values.0` as `metrics` and loses the field entirely. This
// file is the one mapping both entry points now share; the toast/log
// fallback on an empty result stays with each caller, since every one of the
// seven names a different translation key.

import 'package:magic/magic.dart';

/// The per-field validation errors from a failed [Model.save], one first
/// message per form field.
///
/// Typed on [InteractsWithPersistence] rather than the bare [Model] it mixes
/// onto, because `validationErrors` is that mixin's member: every model this
/// helper is called with (`Monitor`, `Incident`, `StatusPage`, ...) already
/// carries it.
Map<String, String> fieldErrorsFromModel(InteractsWithPersistence model) {
  return _collapseErrors(model.validationErrors);
}

/// The per-field validation errors from a failed [MagicResponse] (typically
/// a 422 from `Http.store`/`Http.update`), one first message per form field.
Map<String, String> fieldErrorsFromResponse(MagicResponse response) {
  return _collapseErrors(response.errors);
}

/// Reduces a raw wire field-to-messages map to one first message per
/// collapsed form field (see [_collapseKey]). The FIRST message encountered
/// wins when two wire keys collapse onto the same field, e.g. two failing
/// elements of the same list, matching the single-message-per-field contract
/// every other field already has.
Map<String, String> _collapseErrors(Map<String, List<String>> errors) {
  final Map<String, String> fieldErrors = {};
  for (final MapEntry<String, List<String>> entry in errors.entries) {
    final String field = _collapseKey(entry.key);
    fieldErrors.putIfAbsent(field, () => entry.value.first);
  }
  return fieldErrors;
}

/// Collapses a wire validation key onto the form field it should report on.
///
/// 1. Drop a trailing element index (`ok_values.0` -> `ok_values`); a single
///    remaining segment is already the answer.
/// 2. What is left addresses a distinct SUB-KEY, not a list element, when its
///    second segment is not itself numeric: `credentials.token` stays whole
///    because the notification-channel form has a separate error slot per
///    sub-key (`credentials.url`, `credentials.secret`, ...), and collapsing
///    it to `credentials` would destroy every one of those slots but one.
/// 3. A second segment that IS numeric marks a collection wrapper
///    (`metrics.<row>.<field>...`, the bulk metric endpoint's row array), so
///    the key collapses onto its last remaining segment, the one field the
///    form actually renders (`ok_values`), rather than the wrapper name
///    (`metrics`) a plain `.split('.').first` would give.
String _collapseKey(String key) {
  final List<String> segments = key.split('.');

  int end = segments.length;
  while (end > 1 && _isNumericSegment(segments[end - 1])) {
    end--;
  }
  final List<String> trimmed = segments.sublist(0, end);

  if (trimmed.length == 1) {
    return trimmed.first;
  }

  if (_isNumericSegment(trimmed[1])) {
    return trimmed.last;
  }

  return trimmed.join('.');
}

/// Whether [segment] is a non-empty run of ASCII digits, i.e. a list index
/// rather than a field name.
bool _isNumericSegment(String segment) {
  return segment.isNotEmpty && int.tryParse(segment) != null;
}
