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

  /// Human-readable display label matching the React source's badge text.
  String get label => switch (this) {
    ChangeKind.added => 'New',
    ChangeKind.improved => 'Improved',
    ChangeKind.fixed => 'Fixed',
  };
}
