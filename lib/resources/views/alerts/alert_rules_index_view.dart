import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/models/alert_rule.dart';
import '../components/alerts/alert_rule_list_item.dart';
import '../components/common/page_header.dart';

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
      className: 'flex-1 overflow-y-auto',
      scrollPrimary: true,
      child: WDiv(
        className: 'flex flex-col gap-6 p-4 pb-8',
        children: [
          // Page Header
          PageHeader(
            title: trans('alerts.alert_rules'),
            trailing: onAddRule != null
                ? WButton(
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
                  )
                : null,
          ),

          // Content list (card container with loading/empty/items states)
          WDiv(
            className: '''
              bg-white dark:bg-gray-800
              border border-gray-100 dark:border-gray-700
              rounded-2xl overflow-hidden
            ''',
            child: _buildContent(rules),
          ),
        ],
      ),
    );
  }

  Widget _buildContent(List<AlertRule> rules) {
    if (isLoading) {
      return WDiv(
        className: 'py-12 flex items-center justify-center',
        child: const SizedBox(
          width: 24,
          height: 24,
          child: CircularProgressIndicator(),
        ),
      );
    }

    if (rules.isEmpty) {
      return WDiv(
        className: 'p-12 flex flex-col items-center justify-center w-full',
        children: [
          WIcon(
            Icons.notifications_off_outlined,
            className: 'text-4xl text-gray-400 dark:text-gray-600 mb-2',
          ),
          WText(
            trans('alerts.no_rules'),
            className: 'text-sm text-gray-600 dark:text-gray-400',
          ),
        ],
      );
    }

    return WDiv(
      className: 'flex flex-col',
      children: [
        for (int i = 0; i < rules.length; i++)
          WDiv(
            className: i < rules.length - 1
                ? 'border-b border-gray-100 dark:border-gray-700'
                : '',
            child: AlertRuleListItem(
              rule: rules[i],
              onEdit: onEditRule != null ? () => onEditRule!(rules[i]) : null,
              onDelete: onDeleteRule != null
                  ? () => onDeleteRule!(rules[i])
                  : null,
            ),
          ),
      ],
    );
  }
}
