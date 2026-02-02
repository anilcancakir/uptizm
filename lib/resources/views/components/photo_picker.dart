import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Reusable photo picker component for avatar/profile images.
///
/// Displays a circular photo preview with pick/upload/remove buttons.
/// Used in profile settings and team settings.
class PhotoPicker extends StatelessWidget {
  /// The photo notifier from the parent (selected but not yet saved).
  final ValueNotifier<MagicFile?> photo;

  /// Current photo URL (from server).
  final String? currentPhotoUrl;

  /// Label text (e.g. "Profile Photo").
  final String label;

  /// Description text below the label.
  final String description;

  /// Text for the pick/change button.
  final String changeButtonText;

  /// Called when user taps "Change Photo" — defaults to Pick.image().
  final VoidCallback? onPick;

  /// Called when user taps "Save" after picking a new photo.
  /// If null, the save button is not shown (useful when save is handled by parent form).
  final VoidCallback? onUpload;

  /// Called when user taps "Remove".
  /// If null, the remove button is not shown.
  final VoidCallback? onRemove;

  /// Text for the remove button.
  final String? removeButtonText;

  /// Whether an upload/save operation is in progress.
  final bool isLoading;

  const PhotoPicker({
    super.key,
    required this.photo,
    required this.label,
    required this.description,
    required this.changeButtonText,
    this.currentPhotoUrl,
    this.onPick,
    this.onUpload,
    this.onRemove,
    this.removeButtonText,
    this.isLoading = false,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-row items-center gap-4',
      children: [
        // Photo preview
        ValueListenableBuilder<MagicFile?>(
          valueListenable: photo,
          builder: (context, file, _) {
            if (file != null) {
              return FutureBuilder<Uint8List?>(
                future: file.readAsBytes(),
                builder: (context, snapshot) {
                  if (snapshot.data != null) {
                    return WImage(
                      image: MemoryImage(snapshot.data!),
                      className: _imageClassName,
                    );
                  }
                  return WDiv(
                    className:
                        'w-16 h-16 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse',
                  );
                },
              );
            }

            final url = currentPhotoUrl ?? '';
            if (url.isEmpty) {
              return WDiv(
                className:
                    'w-16 h-16 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center',
                child: WIcon(
                  Icons.person_outline,
                  className: 'text-2xl text-gray-400 dark:text-gray-500',
                ),
              );
            }

            return WImage(
              src: url,
              className: _imageClassName,
            );
          },
        ),

        // Labels & buttons
        ValueListenableBuilder<MagicFile?>(
          valueListenable: photo,
          builder: (context, file, _) {
            return WDiv(
              className: 'flex flex-col items-start gap-1',
              children: [
                WText(
                  label,
                  className:
                      'text-sm font-medium text-gray-900 dark:text-white',
                ),
                WText(
                  description,
                  className: 'text-xs text-gray-500 dark:text-gray-400',
                ),
                WDiv(className: 'h-1'),
                WDiv(
                  className: 'flex flex-row gap-2',
                  children: [
                    // Pick button
                    WButton(
                      onTap: onPick ?? _defaultPick,
                      className: '''
                        px-3 py-1.5 rounded-lg
                        bg-white dark:bg-gray-800
                        border border-gray-300 dark:border-gray-600
                        hover:bg-gray-50 dark:hover:bg-gray-700
                        text-xs font-medium text-gray-700 dark:text-gray-200
                      ''',
                      child: WText(changeButtonText),
                    ),

                    // Upload/save button (only when a new photo is picked)
                    if (file != null && onUpload != null)
                      WButton(
                        onTap: onUpload,
                        isLoading: isLoading,
                        className: '''
                          px-3 py-1.5 rounded-lg
                          bg-primary hover:bg-green-600
                          text-white
                          text-xs font-medium
                        ''',
                        child: WText(trans('common.save')),
                      ),

                    // Remove button
                    if (file == null &&
                        onRemove != null &&
                        currentPhotoUrl != null &&
                        currentPhotoUrl!.isNotEmpty)
                      WButton(
                        onTap: onRemove,
                        className: '''
                          px-3 py-1.5 rounded-lg
                          bg-red-50 dark:bg-red-900/20
                          text-red-600 dark:text-red-400
                          hover:bg-red-100 dark:hover:bg-red-900/30
                          border border-red-200 dark:border-red-900/50
                          text-xs font-medium
                        ''',
                        child: WText(removeButtonText ?? trans('common.remove')),
                      ),
                  ],
                ),
              ],
            );
          },
        ),
      ],
    );
  }

  void _defaultPick() async {
    final file = await Pick.image(maxWidth: 512, maxHeight: 512);
    if (file != null) {
      photo.value = file;
    }
  }

  static const _imageClassName = '''
    w-16 h-16
    rounded-full
    border border-gray-300 dark:border-gray-600
    bg-gray-200 dark:bg-gray-700
    flex items-center justify-center
    overflow-hidden
  ''';
}
