import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:flutter/material.dart';

/// Application routes.
///
/// Routes call controller actions (Laravel-style).
void registerAppRoutes() {
  MagicRoute.page(
    '/',
    () => Scaffold(body: Center(child: WText('Dashboard'))),
  ).middleware(['auth']);
}
