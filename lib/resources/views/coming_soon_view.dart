import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../ui/layouts/page_container.dart';

/// Placeholder destination for routes whose full screen ships in a later
/// milestone (incidents, status pages, settings).
///
/// The app shell always shows the Incidents / Status / Settings nav entries
/// (the React design's 4-tab budget plus a Settings menu item), but those
/// screens are out of scope for the foundation vertical. Registering them to
/// this view keeps every nav target honest: a tap navigates to a clear
/// "coming soon" plate inside the shell instead of a silent no-op.
class ComingSoonView extends StatelessWidget {
  /// Creates a [ComingSoonView] for the named [feature].
  const ComingSoonView({super.key, required this.feature});

  /// Human-readable label of the deferred feature (e.g. "Incidents").
  final String feature;

  @override
  Widget build(BuildContext context) {
    return PageContainer(
      child: EmptyState(
        icon: Icons.construction_outlined,
        title: trans('uptizm.common.coming_soon_title'),
        description: feature,
      ),
    );
  }
}
