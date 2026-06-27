import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart' show Icons, Material, MaterialType;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'assistant.recipe.dart';

/// The author of an assistant chat message.
enum AssistantRole {
  /// A message typed by the operator.
  user,

  /// A reply from Uptizm AI.
  assistant,
}

/// A single message in the assistant conversation.
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
const AssistantMessage _greeting = AssistantMessage(
  role: AssistantRole.assistant,
  text:
      "Hi, I'm Uptizm AI. I reason from your own checks, regions, response "
      'times, and custom metrics, and I can set things up for you. How can I '
      'help?',
);

/// The quick-prompt chips offered before the first user message.
const List<String> _quickPrompts = [
  'Which monitors are slow?',
  'Create a monitor',
  'Declare an incident',
  'New status page',
];

/// **The Floating Uptizm AI Assistant**
///
/// A floating circular `ai`-toned FAB that opens an assistant surface. Ported
/// from the design lab's `Assistant`; mounted once by the app shell (Step 9),
/// it owns its own open / message state here so it works as a standalone mock.
///
/// ### Behavior
///
/// - **`ai` tone:** the FAB and the surface chrome use the `ai` token family,
///   marking this as an AI surface (PORTING.md status vocabulary).
/// - **Press feedback, not hover:** the FAB is a [WButton] and every chip is a
///   [WAnchor], so they respond to press / ripple on mobile rather than the
///   desktop-only `hover:scale` of the web source (PORTING.md §7).
/// - **Glass + elevation:** the open surface composites over a [BackdropFilter]
///   blur with a high-opacity surface fallback (PORTING.md §4); the FAB carries
///   [Material] elevation rather than an arbitrary drop shadow (§6).
/// - **Grounded replies:** the mock reply attaches no live cards (those are the
///   shell's concern); it echoes a short on-brand acknowledgement so the chat
///   surface demonstrates the round-trip.
///
/// ### Example Usage:
///
/// ```dart
/// // Mounted once in the app shell's overlay stack:
/// const Assistant()
/// ```
class Assistant extends StatefulWidget {
  /// Creates the floating [Assistant].
  const Assistant({super.key});

  @override
  State<Assistant> createState() => _AssistantState();
}

class _AssistantState extends State<Assistant> {
  /// Whether the assistant surface is open.
  bool _open = false;

  /// The running conversation, seeded with the greeting.
  final List<AssistantMessage> _messages = [_greeting];

  /// The composer text controller.
  final TextEditingController _input = TextEditingController();

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  /// Whether the quick-prompt chips should still be shown (pre-first-reply).
  bool get _showChips => _messages.length <= 1;

  /// Appends a user message plus a canned assistant acknowledgement.
  void _send(String text) {
    final String trimmed = text.trim();
    if (trimmed.isEmpty) return;

    setState(() {
      _messages.add(AssistantMessage(role: AssistantRole.user, text: trimmed));
      _messages.add(_respond(trimmed));
      _input.clear();
    });
  }

  /// A minimal canned reply. The real assistant grounds answers in monitoring
  /// signals; the mock acknowledges and points back at the data.
  AssistantMessage _respond(String text) {
    return const AssistantMessage(
      role: AssistantRole.assistant,
      text:
          'I can answer from your monitoring data (checks, regions, response '
          'times, and custom metrics), or set things up for you. Try "which '
          'monitors are slow?" or "create a monitor".',
    );
  }

  @override
  Widget build(BuildContext context) {
    // The FAB and the open surface live in a Stack so the surface can overlay
    // the FAB; the shell mounts this whole widget in its own overlay slot.
    return Stack(
      children: [if (!_open) _buildFab(), if (_open) _buildSurface()],
    );
  }

  /// The collapsed FAB: a circular `ai`-toned button with press feedback and
  /// Material elevation (PORTING.md §6 / §7). The sparkle glyph reads at 24px,
  /// matching the design lab `size-6` icon.
  Widget _buildFab() {
    return Material(
      type: MaterialType.transparency,
      elevation: 6,
      child: WButton(
        onTap: () => setState(() => _open = true),
        semanticLabel: 'Open Uptizm AI',
        className: assistantFabRecipe(),
        child: WIcon(Icons.auto_awesome, className: 'text-[24px] text-on-ai'),
      ),
    );
  }

  /// The opened assistant surface composited over a blurred backdrop.
  Widget _buildSurface() {
    return Stack(
      children: [
        // Tap-to-dismiss blurred backdrop (PORTING.md §4 glass).
        Positioned.fill(
          child: WAnchor(
            onTap: () => setState(() => _open = false),
            child: ClipRect(
              child: BackdropFilter(
                filter: ImageFilter.blur(sigmaX: 8, sigmaY: 8),
                child: WDiv(className: 'bg-surface/30'),
              ),
            ),
          ),
        ),

        // The surface panel itself, capped to a fraction of the viewport so
        // the message list scrolls inside it rather than overflowing.
        Align(
          alignment: Alignment.bottomRight,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: SafeArea(
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  maxHeight: MediaQuery.sizeOf(context).height * 0.7,
                ),
                child: WDiv(
                  className: assistantSurfaceRecipe(),
                  children: [
                    _buildHeader(),
                    _buildList(),
                    if (_showChips) _buildChips(),
                    _buildInputBar(),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// Surface header: `ai` glyph badge, title / subtitle, close button.
  Widget _buildHeader() {
    return WDiv(
      className: '''
        flex flex-row items-center gap-2.5 px-4 py-3
        border-b border-color-border
      ''',
      children: [
        WDiv(
          className: '''
            flex items-center justify-center size-8 shrink-0
            rounded-lg bg-ai-soft
          ''',
          child: WIcon(Icons.auto_awesome, className: 'text-[20px] text-ai'),
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText('Uptizm AI', className: 'text-sm font-semibold text-fg'),
              WText(
                'Ask, or tell me what to set up',
                className: 'text-xs text-fg-muted',
              ),
            ],
          ),
        ),
        WAnchor(
          onTap: () => setState(() => _open = false),
          semanticLabel: 'Close assistant',
          child: WDiv(
            className: '''
              flex items-center justify-center size-8 shrink-0
              rounded-md text-fg-muted
            ''',
            child: WIcon(Icons.close, className: 'text-[18px] text-fg-muted'),
          ),
        ),
      ],
    );
  }

  /// Scrollable message list: a [Flexible] + [SingleChildScrollView] so a long
  /// conversation scrolls inside the bounded surface (mirroring the web
  /// `overflow-y-auto`) while every bubble stays in the tree.
  Widget _buildList() {
    return Flexible(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: WDiv(
          className: 'flex flex-col gap-3',
          children: [for (final message in _messages) _buildBubble(message)],
        ),
      ),
    );
  }

  /// A single chat-message row, aligned and tinted by role.
  ///
  /// Mirrors the design lab `row`: an assistant message leads with a small
  /// `ai`-toned avatar then its bubble, left-aligned; a user message reverses
  /// (`flex-row-reverse`) so its bubble sits on the trailing edge with no
  /// avatar. A Flutter [Row] drives the alignment and a [Flexible] caps the
  /// bubble so a long reply wraps inside the surface instead of overflowing; a
  /// single-child flex `WDiv` would not bound its child's width here. The bubble
  /// tone, geometry, and `max-w-[85%]` come from [assistantBubbleRecipe]; the
  /// inner [WText] inherits the bubble's foreground color through the Wind text
  /// cascade.
  Widget _buildBubble(AssistantMessage message) {
    final bool isUser = message.role == AssistantRole.user;

    final Widget bubble = Flexible(
      child: WDiv(
        className: assistantBubbleRecipe(
          variants: {kAssistantRoleAxis: message.role.name},
        ),
        child: WText(message.text),
      ),
    );

    if (isUser) {
      return Row(
        mainAxisAlignment: MainAxisAlignment.end,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [bubble],
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        WDiv(
          className: '''
            flex items-center justify-center size-7 shrink-0
            rounded-full bg-ai-soft
          ''',
          child: WIcon(Icons.auto_awesome, className: 'text-[16px] text-ai'),
        ),
        const SizedBox(width: 8),
        bubble,
      ],
    );
  }

  /// Quick-prompt chips shown before the first reply; each is a [WAnchor] for
  /// press feedback.
  Widget _buildChips() {
    return WDiv(
      className: '''
        wrap gap-2 p-3
        border-t border-color-border
      ''',
      children: [
        for (final prompt in _quickPrompts)
          WAnchor(
            onTap: () => _send(prompt),
            semanticLabel: prompt,
            child: WDiv(
              className: '''
                rounded-full border border-color-border px-3 py-1.5
                text-xs text-fg
              ''',
              child: WText(prompt, className: 'text-xs'),
            ),
          ),
      ],
    );
  }

  /// The composer: a text input and a send button.
  Widget _buildInputBar() {
    return WDiv(
      className: '''
        flex flex-row items-center gap-2 p-3
        border-t border-color-border
      ''',
      children: [
        Expanded(
          child: WInput(
            controller: _input,
            placeholder: 'Message Uptizm AI…',
            className: '''
              h-11 px-3 rounded-lg text-sm text-fg
              bg-surface-container-high border border-color-border
            ''',
            onSubmitted: _send,
          ),
        ),
        WButton(
          onTap: () => _send(_input.text),
          semanticLabel: 'Send',
          className: '''
            flex items-center justify-center size-11 shrink-0
            rounded-lg bg-primary text-on-primary
          ''',
          child: WIcon(Icons.send, className: 'text-[18px] text-on-primary'),
        ),
      ],
    );
  }
}
