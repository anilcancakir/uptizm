import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_devtools/preview.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/_previews.g.dart';

import '../support/bundled_lang.dart';

/// **Every control the component surface publishes has a name, and one node.**
///
/// This walks the registered component previews and asserts two things about the
/// semantics tree each one produces: no `button` node reaches the platform
/// without an accessible name, and no `button` node sits inside another one at
/// identical global bounds.
///
/// Both failures are invisible to every other test in this suite, because a
/// widget test asserts on the widget it built and `tester.getSemantics` resolves
/// one widget's node. Neither can see a control that renders correctly and
/// announces nothing. Two had shipped: `WPopover` wraps its trigger in a
/// label-free `Semantics(button: true)`, which left the team switcher, the bell
/// and the account menu nameless, and `MSPageHeader` used `backLabel` only as a
/// presence flag, so the back control on every detail page was an unnamed
/// button.
///
/// A preview renders a component's whole variant matrix, so a control that is
/// nameless in any one variant fails here.
///
/// The catalogue's three SCREEN previews are excluded. They mount the real views,
/// whose controllers schedule refresh timers that a widget test cannot drain, so
/// they fail on `A Timer is still pending` regardless of their semantics. The
/// live `fluttersdk_dusk` walk in `docs/verification-loop.md` is what covers
/// whole screens; this test covers the component surface.
const Set<String> _screenPreviewSlugs = <String>{
  'dashboard_screen',
  'monitor_detail_screen',
  'monitors_list_screen',
};
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('magic_starter', () => MagicStarterManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  final Iterable<PreviewEntry> componentPreviews = previewEntries().where(
    (PreviewEntry e) => !_screenPreviewSlugs.contains(e.slug),
  );

  for (final PreviewEntry entry in componentPreviews) {
    testWidgets('${entry.label} publishes named, single button nodes', (
      WidgetTester tester,
    ) async {
      // The shipped catalogue, not a fixture: a raw i18n key would read as a
      // perfectly good accessible name and hide a missing translation.
      Translator.instance.setLoader(_BundledTurkishLoader());
      await Translator.instance.setLocale(const Locale('tr'));

      final SemanticsHandle handle = tester.ensureSemantics();
      // Tall, so a variant matrix lays out without clipping its lower rows out
      // of the tree; wide enough for the desktop branch of a responsive widget.
      tester.view.physicalSize = const Size(1400, 4000);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: SingleChildScrollView(
                child: Builder(builder: entry.builder),
              ),
            ),
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));

      final List<_Button> buttons = _buttons(tester);

      expect(
        buttons.where((_Button b) => b.name.trim().isEmpty),
        isEmpty,
        reason:
            '${entry.label} publishes a button with no accessible name. '
            'Give it one (`semanticLabel:` on the anchor, or a Semantics '
            'wrapper) so a screen reader can say what it opens.',
      );

      expect(
        _duplicatePairs(buttons),
        isEmpty,
        reason:
            '${entry.label} publishes two button nodes at the same bounds, '
            'so assistive technology reads the control twice.',
      );

      handle.dispose();
    });
  }
}

/// One button node's accessible name and bounds.
@immutable
class _Button {
  /// The merged accessible name, which is what a screen reader reads.
  final String name;

  /// The node's bounds, used to spot two nodes describing one control.
  final Rect rect;

  /// The node's depth in the semantics tree, reported to locate the node.
  final int depth;

  /// Whether the node exposes a tap action, which separates a control you can
  /// press but cannot identify from a role claim with nothing behind it.
  final bool tappable;

  /// The nearest labelled text seen on the way down to this node, so a failure
  /// says WHERE in the surface the nameless control sits.
  final String nearby;

  /// Whether the nearest enclosing button node covers the same global bounds,
  /// which means this control is announced twice.
  final bool duplicatesAncestor;

  const _Button({
    required this.name,
    required this.rect,
    required this.depth,
    required this.tappable,
    required this.nearby,
    this.duplicatesAncestor = false,
  });

  @override
  String toString() =>
      'button(name: "${name.replaceAll("\n", " / ")}", '
      'rect: ${rect.width.toStringAsFixed(0)}x${rect.height.toStringAsFixed(0)} '
      'at ${rect.left.toStringAsFixed(0)},${rect.top.toStringAsFixed(0)}, '
      'depth: $depth, tappable: $tappable, under: "$nearby")';
}

/// Every button node in the rendered tree, with its merged name and its bounds
/// in GLOBAL coordinates.
///
/// `SemanticsNode.rect` is expressed in the node's own space, so two unrelated
/// 20x20 icon buttons both read `20x20 at 0,0` and compare equal. The transform
/// to the root is what separates one control announced twice from two controls
/// that merely happen to be the same size.
List<_Button> _buttons(WidgetTester tester) {
  final List<_Button> found = <_Button>[];
  final SemanticsNode root = tester.getSemantics(find.byType(MaterialApp));

  // `SemanticsNode` exposes only `transform`, the parent-to-child transform, so
  // the accumulated matrix is carried down the walk rather than asked for.
  void walk(
    SemanticsNode node,
    int depth,
    Matrix4 parentToRoot,
    _Button? nearestButtonAncestor,
    String nearestLabel,
  ) {
    final Matrix4 nodeToRoot = node.transform == null
        ? parentToRoot
        : (parentToRoot.clone()..multiply(node.transform!));
    final SemanticsData data = node.getSemanticsData();
    _Button? ancestor = nearestButtonAncestor;

    // A node with `isMergedIntoParent` is folded into its parent's data and
    // never sent to assistive technology. Counting one reports a control that
    // does not exist, which is exactly how an earlier reading of this class of
    // bug claimed a double announcement that no screen reader ever made.
    if (!node.isMergedIntoParent && data.flagsCollection.isButton) {
      final Rect rect = MatrixUtils.transformRect(nodeToRoot, node.rect);
      final _Button button = _Button(
        name: data.label,
        rect: rect,
        depth: depth,
        tappable: data.hasAction(SemanticsAction.tap),
        nearby: nearestLabel,
        duplicatesAncestor: ancestor != null && ancestor.rect == rect,
      );
      found.add(button);
      ancestor = button;
    }

    final String label = data.label.trim();
    final String nextNearest = label.isEmpty
        ? nearestLabel
        : label.replaceAll('\n', ' / ');

    node.visitChildren((SemanticsNode child) {
      walk(child, depth + 1, nodeToRoot, ancestor, nextNearest);
      return true;
    });
  }

  walk(root, 0, Matrix4.identity(), null, '<root>');

  return found;
}

/// Button nodes that describe a control an ancestor button already described.
///
/// Only a button nested inside another button AT THE SAME GLOBAL BOUNDS counts.
/// A row that carries its own inner action button is a legitimate nesting and
/// has different bounds; two controls of equal size side by side are unrelated.
List<_Button> _duplicatePairs(List<_Button> buttons) =>
    buttons.where((_Button b) => b.duplicatesAncestor).toList();

/// Feeds [trans] the app's shipped Turkish catalogue, so a name assertion is
/// made against the word an operator actually hears.
class _BundledTurkishLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async =>
      readBundledLang('tr');
}
