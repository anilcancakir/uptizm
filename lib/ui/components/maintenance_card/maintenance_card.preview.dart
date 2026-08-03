import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'maintenance_card.dart';

/// Static variant-matrix preview for [MaintenanceCard].
///
/// Renders all three phases, because the phase is the only axis the card has and
/// it drives both the stripe and the badge: an upcoming window that holds alerts,
/// one running right now, and a finished one that must read as spent rather than
/// live. The last row drops [MaintenanceCard.onCancel] to show the read-only
/// shape a surface without a cancel action gets.
///
/// One public preview class per file is the discovery contract
/// `previews:refresh` enforces.
class MaintenanceCardPreview extends StatelessWidget {
  /// Creates the MaintenanceCard preview.
  const MaintenanceCardPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6 max-w-3xl',
      children: [
        MaintenanceCard(
          title: 'Database upgrade',
          phase: MaintenancePhase.upcoming,
          phaseLabel: 'Scheduled',
          components: const ['Checkout', 'API'],
          range: '08-03 17:30 → 08-03 18:30',
          suppressesAlerts: true,
          suppressLabel: 'Alerts held',
          onCancel: () {},
          cancelLabel: 'Cancel',
        ),
        MaintenanceCard(
          title: 'Cache rebuild, in progress',
          phase: MaintenancePhase.active,
          phaseLabel: 'In progress',
          components: const ['Website'],
          range: '08-03 14:00 → 08-03 16:00',
          onCancel: () {},
          cancelLabel: 'Cancel',
        ),
        const MaintenanceCard(
          title: 'Last week is over',
          phase: MaintenancePhase.finished,
          phaseLabel: 'Finished',
          components: ['Checkout'],
          range: '07-27 02:00 → 07-27 03:00',
        ),
      ],
    );
  }
}
