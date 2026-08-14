import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';


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
class AssistantController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static AssistantController get instance =>
      Magic.findOrPut(AssistantController.new);

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
