import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import '../../../../app/enums/monitor_location.dart';

class LocationBadge extends StatelessWidget {
  final MonitorLocation location;

  const LocationBadge({super.key, required this.location});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className:
          'rounded-full px-2.5 py-1 border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800',
      children: [
        WDiv(
          className: 'flex items-center gap-1',
          children: [
            WIcon(
              Icons.public,
              className: 'text-gray-400 dark:text-gray-500 text-sm',
            ),
            WText(
              location.label,
              className: 'text-xs text-gray-700 dark:text-gray-300',
            ),
          ],
        ),
      ],
    );
  }
}
