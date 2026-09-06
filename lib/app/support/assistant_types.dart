import 'package:flutter/foundation.dart' show immutable;
import 'package:magic/magic.dart' show trans;

/// The author of an assistant chat message.
enum AssistantRole {
  /// A message typed by the operator.
  user,

  /// A reply from Uptizm AI.
  assistant,

  /// The product speaking, not the assistant.
  ///
  /// Used when the backend answered without a model behind it, which today
  /// means the team is over its daily AI allowance. That sentence used to
  /// arrive as an [assistant] message, so an operator read a canned line as
  /// something Uptizm AI had worked out for them; the backend marks it with a
  /// `degrade_reason` and this role is how the panel shows the difference.
  system,
}

/// A single message in the assistant conversation.
///
/// Lives in `support/` rather than beside the widget because the conversation
/// is owned by `AssistantController` now, and a controller importing a UI
/// component would invert the layering the same way `EscalationPolicy` once
/// imported `EscalationController`.
@immutable
class AssistantMessage {
  /// Who authored the message.
  final AssistantRole role;

  /// The message body.
  final String text;

  /// Creates an [AssistantMessage].
  const AssistantMessage({required this.role, required this.text});
}

/// The opening greeting shown when the assistant surface first opens.
///
/// A getter (not a `const`) so the copy resolves through [trans] at the current
/// locale.
AssistantMessage get assistantGreeting => AssistantMessage(
  role: AssistantRole.assistant,
  text: trans('uptizm.assistant.greeting'),
);
