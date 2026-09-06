import 'dart:async' show unawaited;
import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart' show Icons, Material, MaterialType;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/assistant_controller.dart';
import '../../../app/support/assistant_types.dart';

// Re-exported so the barrel's public surface is unchanged: the two types moved
// to `support/` because the controller owns the conversation now, and a
// controller importing a UI component would invert the layering.
export '../../../app/support/assistant_types.dart'
    show AssistantMessage, AssistantRole;
import 'assistant.recipe.dart';

/// The quick-prompt chips offered before the first user message.
///
/// A getter (not a `const`) so each chip resolves through [trans] at the
/// current locale.
List<String> get _quickPrompts => [
  trans('uptizm.assistant.prompt_slow_monitors'),
  trans('uptizm.assistant.prompt_create_monitor'),
  trans('uptizm.assistant.prompt_declare_incident'),
  trans('uptizm.assistant.prompt_new_status_page'),
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
/// - **Grounded replies:** [_send] asks the live assistant via
///   [AssistantController] (`POST /assistant`); the reply attaches no live
///   cards (those are the shell's concern), just the grounded answer text.
///
/// ### Example Usage:
///
/// ```dart
/// // Mounted once in the app shell's overlay stack:
/// const Assistant()
/// ```
class Assistant extends StatefulWidget {
  /// When `true`, render the chat surface statically (no floating FAB, no
  /// backdrop), sized like the design lab's `panelEmbedded` slot. The preview
  /// catalog uses this so the open conversation is the resting affordance.
  final bool embedded;

  /// Seeds the conversation. When `null`, the surface opens with the single
  /// [_greeting]. The preview passes the full design-lab seed so the embedded
  /// surface shows a representative exchange.
  final List<AssistantMessage>? initialMessages;

  /// Creates the [Assistant]. Floating by default; pass `embedded: true` for
  /// the static chat surface.
  const Assistant({super.key, this.embedded = false, this.initialMessages});

  @override
  State<Assistant> createState() => _AssistantState();
}

class _AssistantState extends State<Assistant> {
  /// The AI mark: the FAB, the panel header, and an assistant message's avatar
  /// all carry it, which is why it is one constant rather than three literals.
  static const IconData _aiIcon = Icons.auto_awesome;

  /// Dismiss affordance on the panel header.
  static const IconData _closeIcon = Icons.close;

  /// Submit affordance on the composer.
  static const IconData _sendIcon = Icons.send;

  /// Whether the floating surface is open. Always shown in embedded mode.
  bool _open = false;

  /// The composer text controller. The only state that stays local: it is a
  /// typing buffer, not part of the conversation.
  final TextEditingController _input = TextEditingController();

  /// The controller owning the conversation, so it survives the shell swap at
  /// the `lg` breakpoint that used to discard this State mid-triage.
  AssistantController get _controller => AssistantController.instance;

  /// The conversation, read from the controller.
  List<AssistantMessage> get _messages => _controller.messages;

  @override
  void initState() {
    super.initState();
    _controller.addListener(_onControllerChanged);
    // A preview seeds its own script; the app seeds the greeting once.
    final List<AssistantMessage>? seed = widget.initialMessages;
    if (seed != null) {
      _controller.seedConversation(seed);
    } else {
      _controller.ensureGreeted();
    }
  }

  @override
  void dispose() {
    _controller.removeListener(_onControllerChanged);
    _input.dispose();
    super.dispose();
  }

  /// Rebuilds when the conversation or the in-flight flag moves.
  void _onControllerChanged() {
    if (mounted) setState(() {});
  }

  /// Whether the quick-prompt chips should still be shown (pre-first-reply).
  bool get _showChips => _messages.length <= 1;

  /// Hands [text] to the controller, which appends it, guards against a second
  /// question while one is in flight, and appends the reply.
  void _send(String text) {
    if (_controller.isAsking) return;

    final String trimmed = text.trim();
    if (trimmed.isEmpty) return;

    _input.clear();
    unawaited(_controller.send(trimmed));
  }

  @override
  Widget build(BuildContext context) {
    // Embedded: render the chat surface statically, capped to the design lab's
    // `max-w-sm` (384px) and `h-[560px]` so the message list scrolls inside it.
    if (widget.embedded) {
      return ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 384),
        child: SizedBox(height: 560, child: _buildPanel(embedded: true)),
      );
    }

    // Floating: the FAB and the open surface live in a Stack so the surface can
    // overlay the FAB; the shell mounts this whole widget in its overlay slot.
    return Stack(
      children: [if (!_open) _buildFab(), if (_open) _buildSurface()],
    );
  }

  /// The styled surface panel (header, list, chips, input bar), shared by the
  /// floating overlay and the embedded preview. The `embedded` flag selects the
  /// solid-vs-glass surface fill via [assistantSurfaceRecipe].
  Widget _buildPanel({required bool embedded}) {
    return WDiv(
      className: assistantSurfaceRecipe(
        variants: {
          kAssistantSurfaceModeAxis: embedded ? 'embedded' : 'floating',
        },
      ),
      children: [
        _buildHeader(),
        _buildList(),
        if (_showChips) _buildChips(),
        _buildInputBar(),
      ],
    );
  }

  /// The collapsed FAB: a circular `ai`-toned button with press feedback and
  /// Material elevation (PORTING.md §6 / §7). The sparkle glyph reads at 24px,
  /// matching the design lab `size-6` icon.
  Widget _buildFab() {
    // Bottom-right within the fill-sized overlay, mirroring the open surface's
    // Align so the FAB and the panel it opens share the same anchor. SafeArea
    // keeps it clear of system insets; the shell mounts this over the content
    // region, so on mobile the FAB already sits above the bottom nav.
    return Align(
      alignment: Alignment.bottomRight,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: SafeArea(
          child: Material(
            type: MaterialType.transparency,
            elevation: 6,
            child: WButton(
              onTap: () => setState(() => _open = true),
              semanticLabel: trans('uptizm.assistant.open_label'),
              className: assistantFabRecipe(),
              child: WIcon(
                _aiIcon,
                className: 'text-[24px] text-on-ai',
              ),
            ),
          ),
        ),
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
            // Named, because it is a real control: the backdrop carries a
            // gesture, so it publishes a button node, and its child is a blur
            // with no text for `MergeSemantics` to absorb. Measured in Chrome
            // with the panel open: one 390x724 tappable node with no accessible
            // name, covering the whole screen. Flutter's own `ModalBarrier`
            // labels this node for the same reason, and the label it should
            // carry is the one the panel's own close button already uses.
            semanticLabel: trans('uptizm.assistant.close_label'),
            onTap: () => setState(() => _open = false),
            child: ClipRect(
              child: BackdropFilter(
                filter: ImageFilter.blur(sigmaX: 8, sigmaY: 8),
                child: WDiv(className: 'bg-surface-scrim'),
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
                child: _buildPanel(embedded: false),
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
          child: WIcon(_aiIcon, className: 'text-[20px] text-ai'),
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText('Uptizm AI', className: 'text-sm font-semibold text-fg'),
              WText(
                trans('uptizm.assistant.subtitle'),
                className: 'text-xs text-fg-muted',
              ),
            ],
          ),
        ),
        WAnchor(
          onTap: () => setState(() => _open = false),
          semanticLabel: trans('uptizm.assistant.close_label'),
          child: WDiv(
            className: '''
              flex items-center justify-center size-8 shrink-0
              rounded-md text-fg-muted
            ''',
            child: WIcon(_closeIcon, className: 'text-[18px] text-fg-muted'),
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
  /// Both roles are left-aligned (matching the design render): an assistant
  /// message leads with a small `ai`-toned avatar then its gray bubble; a user
  /// message is a teal bubble with no avatar, sitting at the leading edge. A
  /// Flutter [Row] drives the alignment and a [Flexible] lets the bubble hug
  /// its content and wrap (capped by the recipe `max-w-[85%]`) instead of
  /// overflowing; a single-child flex `WDiv` would not bound its child's width
  /// here. The bubble tone and geometry come from [assistantBubbleRecipe]; the
  /// inner [WText] inherits the bubble's foreground color through the Wind text
  /// cascade.
  Widget _buildBubble(AssistantMessage message) {
    final bool isUser = message.role == AssistantRole.user;

    final Widget bubble = Flexible(
      child: LayoutBuilder(
        builder: (context, constraints) {
          // Cap the bubble at 85% of the available row width (the design lab's
          // `max-w-[85%]`). Wind's percentage max-width does not bind inside a
          // Flutter flex child, so enforce it here; the bubble still hugs its
          // content below the cap and stays at the leading edge via Align.
          final double maxWidth = constraints.maxWidth.isFinite
              ? constraints.maxWidth * 0.85
              : double.infinity;
          return Align(
            alignment: Alignment.centerLeft,
            child: ConstrainedBox(
              constraints: BoxConstraints(maxWidth: maxWidth),
              child: WDiv(
                className: assistantBubbleRecipe(
                  variants: {kAssistantRoleAxis: message.role.name},
                ),
                child: WText(message.text),
              ),
            ),
          );
        },
      ),
    );

    // The avatar is the assistant's signature, so the system note must not
    // carry it: an Uptizm AI mark beside a sentence no model produced is the
    // same attribution problem the `system` role exists to fix.
    if (isUser || message.role == AssistantRole.system) {
      // Left-aligned, no avatar: the bubble hugs its content at the leading
      // edge (Row defaults to MainAxisAlignment.start).
      return Row(
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
          child: WIcon(_aiIcon, className: 'text-[16px] text-ai'),
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
            placeholder: trans('uptizm.assistant.composer_placeholder'),
            // Symmetric vertical padding (not a fixed h-11) so the text sits
            // vertically centered; a fixed height left the text pinned to the
            // top of the field.
            className: '''
              px-3 py-3 rounded-lg text-sm text-fg
              bg-surface-container-high border border-color-border
            ''',
            onSubmitted: _send,
          ),
        ),
        WButton(
          // Disabled while a question is in flight, so the controller's guard
          // is visible rather than only defensive: the panel used to show
          // nothing at all for the seconds a model round-trip takes, and each
          // extra tap spent another unit of the team's daily AI allowance.
          onTap: _controller.isAsking ? null : () => _send(_input.text),
          semanticLabel: trans('uptizm.assistant.send_label'),
          // Padding-based sizing (like the Button component): a WButton ignores
          // size-N and shrink-wraps to its icon, which rendered a tiny circle.
          // px-4 py-3 sizes it to a clear rounded-square ~matching the input
          // height, the design lab's paper-plane send button.
          className: '''
            flex items-center justify-center shrink-0 px-4 py-3
            rounded-lg bg-primary text-on-primary
          ''',
          child: WIcon(_sendIcon, className: 'text-[20px] text-on-primary'),
        ),
      ],
    );
  }
}
