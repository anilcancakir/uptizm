import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/models/alert_rule.dart';
import '../components/alerts/alert_rule_form.dart';

class AlertRuleCreateView extends StatelessWidget {
  final ValueChanged<AlertRule> onSubmit;
  final int? monitorId; // Optional: if creating for specific monitor

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
        WDiv(
          className: 'flex flex-row items-center gap-3 mb-2',
          children: [
            WAnchor(
              onTap: () => MagicRoute.back(),
              child: WDiv(
                className:
                    'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 duration-150',
                children: [
                  WIcon(
                    Icons.arrow_back,
                    className: 'text-gray-600 dark:text-gray-400 text-xl',
                  ),
                ],
              ),
            ),
            WDiv(
              className: 'flex flex-col',
              children: [
                WText(
                  'Create Alert Rule',
                  className: 'text-2xl font-bold text-gray-900 dark:text-white',
                ),
                if (monitorId != null)
                  WText(
                    'For Monitor #$monitorId',
                    className: 'text-sm text-gray-600 dark:text-gray-400',
                  ),
              ],
            ),
          ],
        ),

        // Form
        AlertRuleForm(onSubmit: onSubmit, monitorId: monitorId),
      ],
    );
  }
}
