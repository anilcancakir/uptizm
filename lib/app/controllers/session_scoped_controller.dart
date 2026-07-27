/// Contract for a controller whose cached state belongs to exactly ONE
/// authenticated session: a user together with their currently active team.
///
/// magic resolves controllers as Type-keyed singletons and runs `onInit` once
/// per instance lifetime (`magic_view.dart` only calls it while
/// `!controller.initialized`). A logout followed by a login, or a team switch,
/// therefore never re-runs the initial fetch: the previous session's rows stay
/// on screen until a full page reload. On a team-scoped product that is not
/// just staleness, it shows one tenant's data to another.
///
/// Every controller that caches team-scoped data implements this, and
/// `AppServiceProvider` calls [resetForSession] on all of the ones that are
/// currently registered whenever the authenticated identity changes.
///
/// [resetForSession] must CLEAR before it refetches. The ordinary `reload()`
/// paths are deliberately non-destructive (a transport failure keeps the
/// last-known-good rows so a blip does not blank a dashboard), and that is the
/// wrong behaviour across an identity change: a failed refetch must leave the
/// screen empty, never populated with the previous session's data.
abstract interface class SessionScopedController {
  /// Drops every cached row for the previous session, publishes the cleared
  /// state, then refetches for the identity that is now authenticated.
  ///
  /// Called on login, logout, and team switch. Implementations must not throw:
  /// the refetch leg logs and degrades like any other reload.
  Future<void> resetForSession();
}
