import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/models/alert_rule.dart';
import '../components/app_page_header.dart';
import '../components/app_list.dart';
import '../components/alerts/alert_rule_list_item.dart';

class AlertRulesIndexView extends StatelessWidget {
  final List<AlertRule>? initialRules;
  final bool isLoading;
  final VoidCallback? onAddRule;
  final ValueChanged<AlertRule>? onEditRule;
  final ValueChanged<AlertRule>? onDeleteRule;

  const AlertRulesIndexView({
    this.initialRules,
    this.isLoading = false,
    this.onAddRule,
    this.onEditRule,
    this.onDeleteRule,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final rules = initialRules ?? [];

    return WDiv(
      className: 'overflow-y-auto flex flex-col',
      scrollPrimary: true,
      children: [
        // Page Header
        AppPageHeader(
          title: trans('alerts.alert_rules'),
          subtitle: trans('alerts.rules_subtitle'),
          actions: onAddRule != null
              ? [
                  WButton(
                    onTap: onAddRule!,
                    className: '''
                      px-4 py-2 rounded-lg
                      bg-primary hover:bg-green-600
                      text-white font-medium text-sm
                    ''',
                    child: WDiv(
                      className: 'flex flex-row items-center sm:gap-2',
                      children: [
                        WIcon(Icons.add, className: 'text-lg text-white'),
                        WText(
                          trans('alerts.add_rule'),
                          className: 'hidden sm:block',
                        ),
                      ],
                    ),
                  ),
                ]
              : null,
        ),

        // Content
        WDiv(
          className: 'p-4 lg:p-6',
          children: [
            AppList<AlertRule>(
              items: rules,
              isLoading: isLoading,
              emptyIcon: Icons.notifications_off_outlined,
              emptyText: trans('alerts.no_rules'),
              itemBuilder: (context, rule, index) => AlertRuleListItem(
                rule: rule,
                onEdit: onEditRule != null ? () => onEditRule!(rule) : null,
                onDelete: onDeleteRule != null
                    ? () => onDeleteRule!(rule)
                    : null,
              ),
            ),
          ],
        ),
      ],
    );
  }
}
