import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../models/user.dart';
import '../../resources/views/settings/profile_settings_view.dart';

/// Profile Controller
///
/// Handles user profile-related actions.
class ProfileController extends MagicController
    with MagicStateMixin<User>, ValidatesRequests {
  /// Singleton accessor.
  static ProfileController get instance =>
      Magic.findOrPut(ProfileController.new);

  /// Render profile settings view.
  Widget profile() => const ProfileSettingsView();

  /// Update profile (name, phone, preferences).
  Future<bool> doUpdateProfile({
    required String name,
    String? phone,
    String? timezone,
    String? language,
  }) async {
    setLoading();
    clearErrors();
    try {
      final response = await Http.put(
        '/user/profile',
        data: {
          'name': name,
          // ignore: use_null_aware_elements
          if (phone != null) 'phone': phone,
          // ignore: use_null_aware_elements
          if (timezone != null) 'timezone': timezone,
          // ignore: use_null_aware_elements
          if (language != null) 'language': language,
        },
      );
      if (response.successful) {
        await Auth.restore();
        setSuccess(User.current);
        Magic.success(trans('common.success'), trans('profile_settings.saved'));
        return true;
      } else {
        handleApiError(
          response,
          fallback: trans('profile_settings.save_failed'),
        );
        return false;
      }
    } catch (e, s) {
      Log.error('Update profile error: $e\n$s', e);
      setError(trans('common.unexpected_error'));
      return false;
    }
  }

  /// Update password.
  Future<bool> doUpdatePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  }) async {
    setLoading();
    clearErrors();
    try {
      final response = await Http.put(
        '/user/password',
        data: {
          'current_password': currentPassword,
          'password': password,
          'password_confirmation': passwordConfirmation,
        },
      );
      if (response.successful) {
        setSuccess(User.current);
        Magic.success(
          trans('common.success'),
          trans('profile_settings.password_updated'),
        );
        return true;
      } else {
        handleApiError(
          response,
          fallback: trans('profile_settings.save_failed'),
        );
        return false;
      }
    } catch (e, s) {
      Log.error('Update password error: $e\n$s', e);
      setError(trans('common.unexpected_error'));
      return false;
    }
  }

  /// Upload profile photo.
  Future<bool> doUploadPhoto(MagicFile photo) async {
    setLoading();
    try {
      final response = await Http.upload(
        '/user/profile-photo',
        data: {},
        files: {'photo': photo},
      );
      if (response.successful) {
        await Auth.restore();
        setSuccess(User.current);
        return true;
      } else {
        setError(trans('profile_settings.save_failed'));
        return false;
      }
    } catch (e, s) {
      Log.error('Upload photo error: $e\n$s', e);
      setError(trans('common.unexpected_error'));
      return false;
    }
  }

  /// Remove profile photo.
  Future<bool> doRemovePhoto() async {
    setLoading();
    try {
      final response = await Http.delete('/user/profile-photo');
      if (response.successful) {
        await Auth.restore();
        setSuccess(User.current);
        return true;
      }
      return false;
    } catch (e, s) {
      Log.error('Remove photo error: $e\n$s', e);
      return false;
    }
  }

  /// Delete account.
  Future<bool> doDeleteAccount({required String password}) async {
    Magic.loading(message: trans('profile_settings.deleting_account'));
    try {
      // Use POST with _method: DELETE for body data support
      final response = await Http.post(
        '/user',
        data: {'_method': 'DELETE', 'password': password},
      );
      Magic.closeLoading();
      if (response.successful) {
        await Auth.logout();
        MagicRoute.to('/auth/login');
        return true;
      } else {
        handleApiError(
          response,
          fallback: trans('profile_settings.save_failed'),
        );
        return false;
      }
    } catch (e, s) {
      Magic.closeLoading();
      Log.error('Delete account error: $e\n$s', e);
      return false;
    }
  }
}
