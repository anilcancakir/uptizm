import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../../app/models/monitor_auth_config.dart';
import '../app_card.dart';
import '../auth_config_editor.dart';

/// Shared "Authentication" section wrapping AuthConfigEditor in AppCard.
class MonitorAuthSection extends StatelessWidget {
  final MonitorAuthConfig authConfig;
  final ValueChanged<MonitorAuthConfig> onChanged;

  const MonitorAuthSection({
    super.key,
    required this.authConfig,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      title: trans('monitor.authentication'),
      body: WDiv(
        className: 'flex flex-col gap-2',
        children: [
          WText(
            trans('monitor.authentication_hint'),
            className: 'text-xs text-gray-600 dark:text-gray-400 mb-2',
          ),
          AuthConfigEditor(
            value: authConfig,
            onChanged: onChanged,
          ),
        ],
      ),
    );
  }
}
