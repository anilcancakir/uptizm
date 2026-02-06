import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Guest Layout
///
/// Simple centered wrapper for authentication pages.
class GuestLayout extends StatelessWidget {
  final Widget child;

  const GuestLayout({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: wColor(
        context,
        'slate',
        shade: 50,
        darkColorName: 'slate',
        darkShade: 900,
      ),
      body: Center(
        child: SingleChildScrollView(
          primary: true,
          child: WDiv(className: 'p-2 lg:p-4', child: child),
        ),
      ),
    );
  }
}
