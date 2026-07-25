import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

import '../enums/channel_type.dart' show ChannelType;

/// Resolves the wire `channel_type` string into a [ChannelType].
///
/// The backend-registered channel types are `slack`, `webhook`, `pagerduty`,
/// and `teams` (email/SMS are per-user preferences at
/// `/settings/notifications`). Each known value gets its own arm so a new
/// backend type never silently decodes as the wrong channel; an unrecognized
/// wire value falls back to [ChannelType.slack] rather than throwing out of a
/// decode path.
ChannelType _typeFromWire(String? wire) => switch (wire) {
  'slack' => ChannelType.slack,
  'webhook' => ChannelType.webhook,
  'pagerduty' => ChannelType.pagerduty,
  'teams' => ChannelType.teams,
  _ => ChannelType.slack,
};

/// A team notification channel, as persisted by the backend.
///
/// Decoded from a `NotificationChannelResource` payload (`GET
/// /notification-channels`, and the `data` object of a create/update/show).
/// Credentials are MASKED server-side (see the backend Resource): [hasCredentials]
/// and [detail] reflect only the presence booleans and non-secret hints
/// (`credentials.channel` for Slack, `credentials.url_host` for webhook), NEVER
/// the raw token/url/secret.
@immutable
class NotificationChannelRecord {
  /// The backend `notification_channels.id`.
  final String id;

  /// Which delivery channel this row configures.
  final ChannelType type;

  /// The channel's display name (mirrors `ChannelType.label` on write).
  final String name;

  /// Whether alerts are currently delivered here.
  final bool isEnabled;

  /// Minimum severity this channel delivers: `"all"` or `"critical"`.
  final String severity;

  /// Whether the channel already has its required credential on file
  /// (Slack: a bot token; webhook/Teams: an endpoint URL; PagerDuty: a routing
  /// key), derived from the masked `credentials` presence booleans.
  final bool hasCredentials;

  /// A non-secret hint of what the channel is pointed at (the Slack channel
  /// name, or the webhook/Teams URL host; PagerDuty carries none), or `null`
  /// when [hasCredentials] is false or the backend omitted the hint.
  final String? detail;

  /// Creates a [NotificationChannelRecord].
  const NotificationChannelRecord({
    required this.id,
    required this.type,
    required this.name,
    required this.isEnabled,
    required this.severity,
    required this.hasCredentials,
    required this.detail,
  });

  /// Decodes a [NotificationChannelRecord] from a `NotificationChannelResource`
  /// wire map.
  factory NotificationChannelRecord.fromMap(Map<String, dynamic> map) {
    final ChannelType type = _typeFromWire(map['channel_type'] as String?);
    final Map<String, dynamic> credentials =
        map['credentials'] is Map<String, dynamic>
        ? map['credentials'] as Map<String, dynamic>
        : const {};

    return NotificationChannelRecord(
      id: map['id']?.toString() ?? '',
      type: type,
      name: (map['name'] as String?) ?? '',
      isEnabled: (map['is_enabled'] as bool?) ?? true,
      severity: (map['severity'] as String?) ?? 'all',
      hasCredentials: switch (type) {
        ChannelType.slack => credentials['has_token'] == true,
        ChannelType.webhook => credentials['has_url'] == true,
        ChannelType.pagerduty => credentials['has_routing_key'] == true,
        ChannelType.teams => credentials['has_url'] == true,
      },
      detail: switch (type) {
        ChannelType.slack => credentials['channel'] as String?,
        ChannelType.webhook => credentials['url_host'] as String?,
        // PagerDuty exposes only a presence boolean, never a display hint.
        ChannelType.pagerduty => null,
        ChannelType.teams => credentials['url_host'] as String?,
      },
    );
  }
}

/// Controller backing [NotificationChannelsView]'s live team channel CRUD
/// against the S9 `api/v1/notification-channels/*` endpoints.
///
/// Follows the raw-`Http` reload/action shape established by
/// `monitor_metrics_controller.dart` (there is no client ORM model for this
/// resource): [reload] fetches the roster through `GET /notification-channels`
/// and caches it in [_channels], degrading to the last-known-good cache on any
/// failure; [channels]/[channelOfType] answer synchronously from that cache.
/// That same index response also carries `meta.push_provisioned`, published as
/// [pushProvisioned], so a view surfaces the honest push heads-up off this one
/// request instead of fetching the flag itself.
/// [create]/[update] return the backend per-field validation errors (single
/// message per field, keyed by the wire field name, e.g. `credentials.token`)
/// so the view can render a server 422 inline, reading them from
/// [MagicResponse.errors] (mirroring `MonitorMetricsController`'s contract).
/// [delete] and [sendTest] are bool-checked write actions with an honest
/// toast: a failed test-send (Slack `{ok:false}` / webhook non-2xx, surfaced
/// by the backend as a 502) is reported as a failure, never a false success.
class NotificationChannelController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static NotificationChannelController get instance =>
      Magic.findOrPut(NotificationChannelController.new);

  /// In-memory cache of the team's notification channels, populated by
  /// [reload] and kept warm by the write actions below. Empty until the first
  /// successful fetch resolves.
  List<NotificationChannelRecord> _channels = [];

  /// The team's notification channels, sourced from `GET
  /// /notification-channels` via [reload].
  List<NotificationChannelRecord> get channels => _channels;

  /// Whether the backend reports its push integration as provisioned, read
  /// from the index's `meta.push_provisioned`. Optimistically `true` until the
  /// first index response resolves.
  bool _pushProvisioned = true;

  /// Whether the backend has its OneSignal `app_id` configured, per the last
  /// index response that actually carried `meta.push_provisioned`.
  ///
  /// `true` while the first fetch is still in flight and after any response
  /// that omitted the flag, so a consumer only ever renders the
  /// "push not configured" heads-up on a CONFIRMED `false`, never on a
  /// degraded payload. Push delivery itself is a per-user preference
  /// (`/settings/notifications`); this flag only says whether that channel can
  /// deliver at all.
  bool get pushProvisioned => _pushProvisioned;

  /// Resolves the cached channel of [type], or `null` when the team has not
  /// configured one yet.
  NotificationChannelRecord? channelOfType(ChannelType type) {
    for (final NotificationChannelRecord record in _channels) {
      if (record.type == type) return record;
    }
    return null;
  }

  /// Seeds the in-memory roster directly for a widget/controller test,
  /// bypassing the network. Notifies listeners so an already-mounted view
  /// rebuilds against the seeded roster.
  @visibleForTesting
  void seedForTest(List<NotificationChannelRecord> seed) {
    _channels = List<NotificationChannelRecord>.from(seed);
    refreshUI();
  }

  /// Bootstraps the roster the first time this controller backs a view.
  @override
  void onInit() {
    super.onInit();
    reload();
  }

  /// Non-destructive refresh of both the roster and the push-provisioning
  /// flag: fetches `GET /notification-channels` once and republishes whatever
  /// that single response actually carried (`data` for the roster,
  /// `meta.push_provisioned` for [pushProvisioned]). Preserves the previously
  /// loaded value of each on any failure (network error, non-2xx, or a
  /// malformed payload) so the view never flickers into an empty state, nor
  /// into a false "push not configured" claim, between reloads.
  Future<void> reload() async {
    try {
      final response = await Http.get('/notification-channels');
      if (!response.successful) {
        Log.error(
          '[NotificationChannelController.reload] ${response.errorMessage}',
        );
        return;
      }

      final Map<String, dynamic> body = response.data is Map<String, dynamic>
          ? response.data as Map<String, dynamic>
          : const {};

      // 1. Republish the push-provisioning flag, but only when the payload
      // truly carries it: a missing or malformed `meta` is a degradation, not
      // a statement that push is unconfigured.
      final Object? meta = body['meta'];
      if (meta is Map<String, dynamic> && meta['push_provisioned'] is bool) {
        _pushProvisioned = meta['push_provisioned'] as bool;
      }

      // 2. Republish the roster, keeping the last-known-good one when `data`
      // is absent or not a list.
      final Object? raw = body['data'];
      if (raw is List) {
        _channels = raw
            .whereType<Map<String, dynamic>>()
            .map(NotificationChannelRecord.fromMap)
            .toList();
      }

      refreshUI();
    } catch (error) {
      Log.error('[NotificationChannelController.reload] failed: $error');
    }
  }

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Creates a team notification channel via `POST /notification-channels`
  /// and reloads the roster on success.
  ///
  /// [fields] is the raw create-form field map (`name`, `channel_type`,
  /// `credentials`, `is_enabled`, `severity`). Returns the backend per-field
  /// validation errors (single message per field, keyed by the wire field
  /// name: `name`, `channel_type`, `credentials.token`, `credentials.url`,
  /// `credentials.secret`, `severity`) so the view can render a server 422
  /// inline; an empty map means success. A non-field failure (a transport
  /// error / 500) surfaces the generic error toast and returns an empty map.
  Future<Map<String, String>> create(Map<String, dynamic> fields) async {
    try {
      final response = await Http.post(
        '/notification-channels',
        data: fields,
      );
      if (!response.successful) {
        Log.error(
          '[NotificationChannelController.create] ${response.errorMessage}',
        );
        return _fieldErrorsOrToast(response);
      }

      await reload();
      _notifySuccess('uptizm.teams.channels_connect_button', fields);
      return const {};
    } catch (error) {
      Log.error('[NotificationChannelController.create] failed: $error');
      _toastError(null);
      return const {};
    }
  }

  /// Updates the team notification channel [id] via `PUT
  /// /notification-channels/:id` and reloads the roster on success.
  ///
  /// [fields] carries only the wire keys the caller intends to change (a
  /// partial `credentials` object REPLACES the whole stored blob
  /// server-side, so a caller editing credentials must send the full
  /// `credentials` shape; omitting the key entirely, as a severity/enabled-
  /// only toggle does, leaves the stored credentials untouched). Returns the
  /// backend per-field validation errors, mirroring [create]'s contract.
  Future<Map<String, String>> update(
    String id,
    Map<String, dynamic> fields,
  ) async {
    try {
      final response = await Http.put(
        '/notification-channels/$id',
        data: fields,
      );
      if (!response.successful) {
        Log.error(
          '[NotificationChannelController.update] $id: ${response.errorMessage}',
        );
        return _fieldErrorsOrToast(response);
      }

      await reload();
      _notifySuccess('uptizm.teams.channels_save_button', fields);
      return const {};
    } catch (error) {
      Log.error('[NotificationChannelController.update] $id failed: $error');
      _toastError(null);
      return const {};
    }
  }

  /// Deletes the team notification channel [id] via `DELETE
  /// /notification-channels/:id` and reloads the roster on success.
  ///
  /// Returns `true` on success, `false` on any failure (an error toast is
  /// surfaced in that case; no exception is ever thrown to the caller).
  Future<bool> delete(String id) async {
    try {
      final response = await Http.delete('/notification-channels/$id');
      if (!response.successful) {
        Log.error(
          '[NotificationChannelController.delete] $id: ${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return false;
      }

      await reload();
      return true;
    } catch (error) {
      Log.error('[NotificationChannelController.delete] $id failed: $error');
      _toastError(null);
      return false;
    }
  }

  /// Sends a test alert through the channel [id] via `POST
  /// /notification-channels/:id/test`.
  ///
  /// The backend reports success as `200 {data:{delivered:true}}` and a
  /// downstream failure as `502 {data:{delivered:false}}`; both a non-2xx
  /// response and a `delivered != true` payload are treated as a failed
  /// test-send (an honest failure toast, never a false success claim).
  /// Returns `true` only when the send actually delivered.
  Future<bool> sendTest(String id) async {
    try {
      final response = await Http.post('/notification-channels/$id/test');
      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      final bool delivered =
          data is Map<String, dynamic> && data['delivered'] == true;

      if (!response.successful || !delivered) {
        Log.error(
          '[NotificationChannelController.sendTest] $id: '
          '${response.errorMessage ?? 'delivery failed'}',
        );
        _toastError(response.errorMessage);
        return false;
      }

      Magic.success(trans('uptizm.teams.channels_test_button'), '');
      return true;
    } catch (error) {
      Log.error('[NotificationChannelController.sendTest] $id failed: $error');
      _toastError(null);
      return false;
    }
  }

  // ---------------------------------------------------------------------------
  // Toast helpers
  // ---------------------------------------------------------------------------

  /// Surfaces a success toast for a create/update write, reusing the action's
  /// own button-label trans key as the title (mirrors `OnCallController`'s
  /// `Magic.success(trans(button_key), ...)` precedent) since the channels
  /// namespace has no dedicated connect/save success copy and the lang assets
  /// are out of this step's file scope.
  void _notifySuccess(String buttonKey, Map<String, dynamic> fields) {
    Magic.success(trans(buttonKey), (fields['name'] as String?) ?? '');
  }

  /// Resolves a failed write [response] into either its per-field validation
  /// errors or a generic toast.
  ///
  /// Returns the field errors (single message per field, keyed by the wire
  /// field name) when the failed write carried the Laravel 422 shape via
  /// [MagicResponse.errors]. Returns an empty map for a non-field failure (a
  /// transport error / 500) after surfacing the generic error toast.
  Map<String, String> _fieldErrorsOrToast(MagicResponse response) {
    final Map<String, List<String>> errors = response.errors;
    if (errors.isNotEmpty) {
      return {
        for (final MapEntry<String, List<String>> entry in errors.entries)
          entry.key: entry.value.first,
      };
    }

    _toastError(response.errorMessage);
    return const {};
  }

  /// Surfaces a generic write-failure toast, reusing the app-wide
  /// `common.error_occurred` copy (mirrors `IncidentController`'s
  /// precedent): the channels namespace has no dedicated error strings and
  /// the lang assets are out of this step's file scope.
  void _toastError(String? detail) {
    Magic.error(
      trans('common.error_occurred'),
      detail ?? trans('common.error_occurred'),
    );
  }
}
