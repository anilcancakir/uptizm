import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../models/user.dart';

/// Listener for [AuthRestored] events.
///
/// Restores user's preferred locale after auth state is refreshed.
/// Layout rebuild is handled automatically by [Auth.stateNotifier].
class AuthRestoreListener extends MagicListener<AuthRestored> {
  @override
  Future<void> handle(AuthRestored event) async {
    // Restore user's preferred locale
    final user = Auth.user<User>();
    if (user?.language != null && user!.language!.isNotEmpty) {
      Lang.setLocale(Locale(user.language!));
    }
  }
}
