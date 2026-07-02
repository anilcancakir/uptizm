import 'package:magic/magic.dart';

import '../mocks/status_pages.dart';
import '../mocks/status_pages.dart' as status_pages_fixture;
import '../../resources/views/status/status_form_support.dart' show aiDraftFor;

/// Controller backing the four routed status-page views (list, editor,
/// preview, subscribers).
///
/// Mocks are synchronous, so this is a plain [MagicController] (no
/// [MagicStateMixin]/rxStatus): actions mutate in-memory state and call
/// [refreshUI]. It owns the durable subscriber roster and the status-page
/// business actions (save, create, AI draft, subscriber removal). Ephemeral
/// per-screen input (the editor draft plus its slug latch, the subscribers
/// search query) stays in the view state, not here.
class StatusPageController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static StatusPageController get instance =>
      Magic.findOrPut(StatusPageController.new);

  /// Mutable per-page subscriber working sets, keyed by [StatusPageConfig.id].
  ///
  /// Lazily seeded from the const fixtures on first access (the fixture map is
  /// never mutated in place); [removeSubscriber] edits the working copy so a
  /// removal survives across rebuilds within the controller's lifetime.
  final Map<String, List<Subscriber>> _subscribers = {};

  /// Every configured status page (fixture access).
  List<StatusPageConfig> get statusPages => status_pages_fixture.statusPages;

  /// Resolves a status page by [id]; `null` when none matches (fixture access).
  StatusPageConfig? configById(String? id) => findStatusPage(id);

  /// The working subscriber roster for the page with [id]; empty for a `null`
  /// id.
  ///
  /// Seeds the working copy from the fixtures on first access, then returns the
  /// same mutable list so [removeSubscriber] edits persist across rebuilds.
  List<Subscriber> subscribersFor(String? id) {
    if (id == null) return const [];
    return _subscribers.putIfAbsent(
      id,
      () => status_pages_fixture.subscribersFor(id).toList(),
    );
  }

  /// Composes an AI-drafted [StatusPageConfig] over the given [monitorIds].
  ///
  /// A pure fill: the view assigns the returned draft into its local compose
  /// state, so the draft and its slug latch stay ephemeral in the view.
  StatusPageConfig generateWithAi(List<String> monitorIds) =>
      aiDraftFor(monitorIds);

  /// Saves an existing status-page [draft] (edit mode): success toast, then
  /// return to the list. Mock: nothing persists.
  void save(StatusPageConfig draft) {
    Magic.success(trans('uptizm.status.editor_form_save'), draft.name);
    MagicRoute.to('/status');
  }

  /// Creates a new status page from [draft] (create mode): success toast, then
  /// return to the list. Mock: nothing persists.
  void create(StatusPageConfig draft) {
    Magic.success(trans('uptizm.status.editor_form_create_page'), draft.name);
    MagicRoute.to('/status');
  }

  /// Removes [subscriber] from the page [pageId] roster, surfaces a success
  /// toast, and rebuilds the bound view.
  void removeSubscriber(String pageId, Subscriber subscriber) {
    subscribersFor(pageId).remove(subscriber);
    Magic.success(
      trans('uptizm.status.subscribers_remove_confirm_title'),
      subscriber.email,
    );
    refreshUI();
  }
}
