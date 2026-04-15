import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/models/alert_rule.dart';
import '../components/alerts/alert_rule_form.dart';
import '../components/common/page_header.dart';

class AlertRuleEditView extends StatelessWidget {
  final AlertRule rule;
  final ValueChanged<AlertRule> onSubmit;

  const AlertRuleEditView({
    required this.rule,
    required this.onSubmit,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex-1 overflow-y-auto',
      scrollPrimary: true,
      child: WDiv(
        className: 'flex flex-col gap-6 p-4 pb-8',
        children: [
          // Page Header
          PageHeader(
            title: trans('alerts.edit_rule'),
            leading: WButton(
              onTap: () => MagicRoute.back(),
              child: WIcon(
                Icons.arrow_back,
                className: 'text-xl text-gray-600 dark:text-gray-400',
              ),
            ),
          ),

          // Form
          AlertRuleForm(initialRule: rule, onSubmit: onSubmit),
        ],
      ),
    );
  }
}
