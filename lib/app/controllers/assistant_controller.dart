import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../support/assistant_types.dart';


/// What one `POST /assistant` round-trip produced.
///
/// A record rather than a bare String because the answer alone lost a fact the
/// panel needs: the backend marks a reply it produced WITHOUT a model with a
/// `degrade_reason` (today, the team over its daily AI allowance), and without it
/// the panel drew that canned sentence as something Uptizm AI had worked out.
typedef AssistantReply = ({String answer, bool degraded});

/// Controller backing the floating Assistant widget's live Q&A round-trip.
///
/// Fires `POST /assistant` with the operator's question and returns the
/// grounded answer produced by the team-scoped assistant gateway
/// (`{data: {answer, confidence, stripped_citations}}`). Mirrors
/// `monitor_controller.dart:145-221`'s action pattern (no silent catch, log +
/// toast on failure), but the caller-facing contract here returns the
/// failure as `null` rather than degrading to a stale cache: a conversation
/// has no prior answer to fall back to.
class AssistantController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller on first access.
  static AssistantController get instance =>
      Magic.findOrPut(AssistantController.new);

  /// The running conversation.
  ///
  /// Held HERE and not in the panel's `State`. `AppLayout` mounts `Assistant`
  /// in two structurally different subtrees selected by `wScreenIs('lg')`, so
  /// dragging a browser window across 1024px (or rotating a large tablet) moved
  /// the widget to a new position in the element tree and Flutter discarded its
  /// State: an operator mid-triage lost the whole exchange, with nothing
  /// persisted and no way back. Resolved from the container, it outlives either
  /// shell.
  final List<AssistantMessage> _messages = <AssistantMessage>[];

  /// Whether a `POST /assistant` round-trip is in flight.
  ///
  /// The guard, not just a spinner input. A model round-trip takes seconds and
  /// the panel showed nothing at all, so the operator tapped send again or
  /// picked a second quick-prompt chip. Each tap is another request charged
  /// against the team's daily AI allowance, and replies appended in completion
  /// order rather than send order, so the answer to the first question could
  /// land under the second.
  bool _asking = false;

  /// The conversation so far, oldest first.
  List<AssistantMessage> get messages =>
      List<AssistantMessage>.unmodifiable(_messages);

  /// Whether a question is currently awaiting its answer.
  bool get isAsking => _asking;

  /// Seeds the greeting once, so a freshly opened panel is not blank.
  ///
  /// Idempotent: a second call on a conversation that already has messages does
  /// nothing, which is what lets both shells call it on mount.
  void ensureGreeted() {
    if (_messages.isNotEmpty) return;

    _messages.add(assistantGreeting);
    refreshUI();
  }

  /// Replaces the conversation with [seed].
  ///
  /// Not test-only: `Assistant.initialMessages` is part of the widget's public
  /// API and the preview catalog scripts a whole exchange through it, so this
  /// is the seam that serves both that and a widget test.
  void seedConversation(List<AssistantMessage> seed) {
    _messages
      ..clear()
      ..addAll(seed);
    _asking = false;
    refreshUI();
  }

  /// Appends [text] as the operator's message and asks for its answer.
  ///
  /// A no-op while another question is in flight, and while [text] is blank.
  Future<void> send(String text) async {
    final String trimmed = text.trim();
    if (trimmed.isEmpty || _asking) return;

    _messages.add(AssistantMessage(role: AssistantRole.user, text: trimmed));
    _asking = true;
    refreshUI();

    try {
      final AssistantReply? reply = await ask(trimmed);
      if (reply == null) return;

      _messages.add(
        AssistantMessage(
          // A sentence the backend produced without a model is the SYSTEM
          // speaking, not the assistant.
          role: reply.degraded
              ? AssistantRole.system
              : AssistantRole.assistant,
          text: reply.answer,
        ),
      );
    } finally {
      _asking = false;
      refreshUI();
    }
  }

  @override
  Future<void> resetForSession() async {
    // The assistant is grounded on ONE team's monitors and incidents, so the
    // exchange belongs to the identity that had it. There is nothing to
    // refetch: the next question starts the next conversation.
    _messages.clear();
    _asking = false;
    // Re-seeded here, not left to the widget: `ensureGreeted` runs from
    // `initState` and the shell does not remount on a team switch, so clearing
    // alone left the panel blank for the rest of the session.
    _messages.add(assistantGreeting);
    refreshUI();
  }

  /// Asks the live assistant [question] via `POST /assistant` and returns the
  /// grounded answer, or `null` on failure (network error, non-2xx, or a
  /// malformed payload). Logs and surfaces an error toast on every failure
  /// path so the caller never sees a silent swallow.
  Future<AssistantReply?> ask(String question) async {
    try {
      final response = await Http.post(
        '/assistant',
        data: {'question': question},
      );
      if (!response.successful) {
        Log.error('[AssistantController.ask] ${response.errorMessage}');
        // The assistant is an AI-tier feature: a plan wall gets the upgrade
        // action, not a "please try again" toast about a retry that cannot work.
        if (UpgradePrompt.showIfGated(response)) return null;

        _toastFailed(response.errorMessage);
        return null;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      final Object? answer = data is Map<String, dynamic>
          ? data['answer']
          : null;
      // Presence, not a specific value: `AiDegradeReason` is a closed set the
      // backend owns, and the panel only needs to know whether a model was
      // behind this sentence. Reading the case here would put the same
      // three-way switch in a second place for no gain.
      final bool degraded =
          data is Map<String, dynamic> && data['degrade_reason'] != null;
      if (answer is! String) {
        Log.error('[AssistantController.ask] malformed response payload');
        _toastFailed(null);
        return null;
      }

      return (answer: answer, degraded: degraded);
    } catch (error) {
      Log.error('[AssistantController.ask] failed: $error');
      _toastFailed(null);
      return null;
    }
  }

  /// Surfaces the assistant's failure toast.
  ///
  /// [detail] is the backend's own message when there is one; it arrives
  /// already localized from the API, so only the fallback copy goes through
  /// `trans()`. Same shape as `StatusPageController._toastError`.
  void _toastFailed(String? detail) {
    Magic.error(
      trans('uptizm.assistant.error_title'),
      detail ?? trans('uptizm.assistant.error_description'),
    );
  }
}
