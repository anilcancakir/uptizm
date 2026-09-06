// Type tests for the fields a model reads out of a NESTED wire map.
//
// A model's own attributes go through magic's `get<T>`, which coerces. A field
// read out of a sub-object or a pivot row does not, and `m['k'] as String?` is
// a hard cast: it throws when the backend sends another type. Every call site
// these serve sits in a getter that a `build` reads, so one wrong-typed field
// took a whole screen down instead of blanking a single line.
//
// This is the same failure class the model sweep removed from the attribute
// accessors. It lives here rather than in one of the models because three of
// them decode nested maps by hand: `incident.dart`, `status_page.dart` and
// `escalation_policy.dart`.

/// Reads [value] as a String, answering [fallback] when it is anything else.
String stringOr(Object? value, String fallback) =>
    value is String ? value : fallback;

/// Reads [value] as a String, answering null when it is anything else.
String? stringOrNull(Object? value) => value is String ? value : null;

/// Reads [value] as an int, answering [fallback] when it cannot be one.
///
/// A numeric string parses rather than degrading, because the fields this
/// serves are orders and durations: silently reading `"3"` as [fallback] would
/// sort a list wrongly or shorten a delay, which is a quieter failure than the
/// cast it replaces.
int intOr(Object? value, int fallback) {
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value) ?? fallback;
  return fallback;
}

/// Reads [value] as a record id, answering null when it is neither a string
/// nor a number.
///
/// A number STRINGIFIES rather than degrading to null, and that is the whole
/// point of having this separate from [stringOrNull]. The backend's primary
/// keys are uuid or bigint depending on `magic-starter.use_uuids`, so an int id
/// is a configuration away rather than a malformed payload. Reading one as null
/// would keep the screen up and then corrupt the save: an editor's save-diff
/// branches on a null id to mean "create this row", so an existing row would
/// come back duplicated.
String? idOrNull(Object? value) {
  if (value is String) return value;
  if (value is num) return value.toString();
  return null;
}
