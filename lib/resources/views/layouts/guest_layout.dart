import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Guest Layout
///
/// Simple layout wrapper for authentication pages (login, register, forgot password).
/// Centered content with minimal styling.
class GuestLayout extends StatelessWidget {
  final Widget child;

  const GuestLayout({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: wColor(context, 'slate-900'), // bg-dark-navy
      body: Center(
        child: SingleChildScrollView(
          primary: true,
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              // Logo Area
              Text(
                trans('app.name'),
                style: TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.bold,
                  color: wColor(context, 'gray-50'), // text-white
                  letterSpacing: 2,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                trans('app.tagline'),
                style: TextStyle(
                  fontSize: 14,
                  color: wColor(context, 'gray-400'), // text-gray-400
                ),
              ),
              const SizedBox(height: 32),

              // Content Card
              child,
            ],
          ),
        ),
      ),
    );
  }
}
