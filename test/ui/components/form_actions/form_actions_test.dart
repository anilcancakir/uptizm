import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/components/form_actions/index.dart';

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

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  group('FormActions', () {
    testWidgets('renders the submit label and no Cancel by default', (
      tester,
    ) async {
      await tester.pumpWidget(
        wrap(FormActions(submitLabel: 'Save', onSubmit: () {})),
      );

      expect(find.text('Save'), findsOneWidget);
      expect(find.text('Cancel'), findsNothing);
    });

    testWidgets('renders Cancel when a cancelLabel is given', (tester) async {
      await tester.pumpWidget(
        wrap(
          FormActions(
            submitLabel: 'Save',
            onSubmit: () {},
            cancelLabel: 'Cancel',
            onCancel: () {},
          ),
        ),
      );

      expect(find.text('Cancel'), findsOneWidget);
    });

    testWidgets('drops the submit tap while a submit is in flight', (
      tester,
    ) async {
      // `isSubmitting` is the guard, not only the spinner: a double tap must
      // not create two of anything.
      int submits = 0;
      await tester.pumpWidget(
        wrap(
          FormActions(
            submitLabel: 'Save',
            isSubmitting: true,
            onSubmit: () => submits++,
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 200));

      await tester.tap(find.byType(MSButton).last, warnIfMissed: false);
      await tester.pump();

      expect(submits, 0);
    });

    testWidgets('the submit control keeps a name while it is submitting', (
      tester,
    ) async {
      // Wind's `WButton` REPLACES its child with the spinner while loading
      // (`isLoading ? _buildLoadingContent(styles) : child`), so there is no
      // text left for `MergeSemantics` to absorb. Measured without the explicit
      // `semanticLabel`: the control publishes NO button node at all while the
      // request is in flight, because loading also drops the gesture, so a
      // screen reader user cannot tell the submit control is still there.
      final SemanticsHandle handle = tester.ensureSemantics();

      await tester.pumpWidget(
        wrap(
          FormActions(
            submitLabel: 'Save',
            isSubmitting: true,
            onSubmit: () {},
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 200));

      final List<String> named = _buttonNames(tester);
      expect(named, contains('Save'));

      handle.dispose();
    });
  });
}

/// The accessible name of every button node the platform would receive.
///
/// A node carrying `isMergedIntoParent` is folded into its parent and never
/// sent to assistive technology, so counting one reports a control that does
/// not exist.
List<String> _buttonNames(WidgetTester tester) {
  final List<String> found = <String>[];

  void walk(SemanticsNode node) {
    final SemanticsData data = node.getSemanticsData();
    if (!node.isMergedIntoParent && data.flagsCollection.isButton) {
      found.add(data.label);
    }
    node.visitChildren((SemanticsNode child) {
      walk(child);
      return true;
    });
  }

  walk(tester.getSemantics(find.byType(MaterialApp)));

  return found;
}
