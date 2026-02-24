import 'package:magic/magic.dart';
import 'package:flutter/material.dart';
import 'package:magic_starter/magic_starter.dart';
import '../models/user.dart';

class AuthRestoreListener extends MagicListener<AuthRestored> {
  @override
  Future<void> handle(AuthRestored event) async {
    // Restore user's preferred locale
    final user = Auth.user<User>();
    if (user?.language != null && user!.language!.isNotEmpty) {
      Lang.setLocale(Locale(user.language!));
    }

    // Force AppLayout to rebuild/refresh state
    MagicStarterAppLayout.refreshNotifier.value++;
  }
}
