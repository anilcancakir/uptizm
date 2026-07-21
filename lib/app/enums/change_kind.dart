import 'package:magic/magic.dart';

/// Tag classifying a single changelog entry. Mirrors the `ChangeType` union
/// in the React `ChangelogSettingsPage` (`"New"` renamed to [added] to avoid
/// colliding with Dart's `new` keyword).
enum ChangeKind {
  /// A newly shipped capability.
  added,

  /// An enhancement to existing behavior.
  improved,

  /// A bug fix.
  fixed;

  /// Localized display label matching the React source's badge text.
  String get label => switch (this) {
    ChangeKind.added => trans('uptizm.enums.change_kind.added'),
    ChangeKind.improved => trans('uptizm.enums.change_kind.improved'),
    ChangeKind.fixed => trans('uptizm.enums.change_kind.fixed'),
  };
}
