import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/models/alert_rule.dart';
import '../components/alerts/alert_rule_form.dart';

class AlertRuleCreateView extends StatelessWidget {
  final ValueChanged<AlertRule> onSubmit;
  final String? monitorId; // Optional: if creating for specific monitor

  const AlertRuleCreateView({
    required this.onSubmit,
    this.monitorId,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
      scrollPrimary: true,
      children: [
        // Page Header
        MagicStarterPageHeader(
          title: 'Create Alert Rule',
          subtitle: monitorId != null ? 'For Monitor #$monitorId' : null,
          leading: WButton(
            onTap: () => MagicRoute.back(),
            child: WIcon(
              Icons.arrow_back,
              className: 'text-xl text-gray-600 dark:text-gray-400',
            ),
          ),
        ),

        // Form
        AlertRuleForm(onSubmit: onSubmit, monitorId: monitorId),
      ],
    );
  }
}
