import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../resources/views/dashboard/dashboard_view.dart';

/// Dashboard Controller
///
/// Handles dashboard data loading and view rendering.
class DashboardController extends MagicController
    with MagicStateMixin<Map<String, dynamic>> {
  /// Singleton accessor.
  static DashboardController get instance =>
      Magic.findOrPut(DashboardController.new);

  /// Render the dashboard view.
  Widget index() => const DashboardView();

  /// Load dashboard stats from API.
  ///
  /// Returns a map with keys: totalMonitors, systemsUp, activeIncidents, avgResponseTime
  Future<void> loadStats() async {
    setLoading();
    try {
      final response = await Http.get('/dashboard/stats');
      if (response.successful) {
        setSuccess(response.data['data'] as Map<String, dynamic>? ?? {});
      } else {
        setError('Failed to load dashboard stats');
      }
    } catch (e) {
      setError('An unexpected error occurred');
    }
  }
}
