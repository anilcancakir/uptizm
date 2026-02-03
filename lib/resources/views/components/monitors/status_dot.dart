import 'package:flutter/material.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';
import '../../../../app/enums/check_status.dart';

class StatusDot extends StatelessWidget {
  final CheckStatus? status;
  final double size;

  const StatusDot({super.key, required this.status, this.size = 12});

  @override
  Widget build(BuildContext context) {
    final colorClass = _getColorClass();

    return SizedBox(
      width: size,
      height: size,
      child: WDiv(
        className: '$colorClass rounded-full animate-pulse',
      ),
    );
  }

  String _getColorClass() {
    switch (status) {
      case CheckStatus.up:
        return 'bg-green-500';
      case CheckStatus.down:
        return 'bg-red-500';
      case CheckStatus.degraded:
        return 'bg-amber-500';
      case null:
        return 'bg-gray-400 dark:bg-gray-600';
    }
  }
}
