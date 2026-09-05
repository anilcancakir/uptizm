import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../enums/channel_type.dart' show ChannelType;
import '../support/field_errors.dart';

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
/// [create]/[update] do NOT validate client-side themselves: the caller
/// ([NotificationChannelsView]) runs `validate()` against its own
/// per-`ChannelType` [Rule] switch before either is ever invoked (the
/// backend's `required_if:channel_type,...` has no magic equivalent, so the
/// exhaustive switch stays where the type is already known, see the view's
/// class docblock). What [create]/[update] own is the SERVER half: on a
/// failed write, [_publishFieldErrors] namespaces the returned field errors
/// `<type>.<wire_key>` (e.g. `slack.credentials.token`,
/// `teams.credentials.url`) via [_namespace] and publishes them onto the
/// inherited [validationErrors], so a client rejection and a server 422 land
/// under the identical shape the view reads via [getError]. The namespace
/// exists because [NotificationChannelsView] holds one draft PER
/// [ChannelType] open at once, and `credentials.url` is the wire key for
/// both the webhook and Teams card. Every write action here (including
/// [delete] and [sendTest]) answers a bare `Future<bool>`: `true` means
/// written, `false` with a populated [validationErrors] means the server
/// named fields to correct, and `false` with an empty [validationErrors]
/// means a non-field failure already raised its own toast. A failed
/// test-send (Slack `{ok:false}` / webhook non-2xx, surfaced by the backend
/// as a 502) is reported as a failure, never a false success.
class NotificationChannelController extends MagicController
    with ValidatesRequests
    implements SessionScopedController {
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

  /// Whether a [reload] has completed at least once, successfully or not.
  bool _resolvedOnce = false;

  /// Whether the FIRST roster read is still in flight.
  ///
  /// Separates "we have not asked yet" from "we asked and nothing is
  /// configured". [NotificationChannelsView] renders one row per channel type
  /// and decides between a Connect button and a live switch purely on whether
  /// the roster holds a record for that type, so before the first answer
  /// arrived every row claimed the integration was not set up; a team with
  /// Slack wired opened the screen on four Connect buttons and only flipped to
  /// its switches when the round trip landed. The view renders a skeleton while
  /// this is true instead.
  ///
  /// Only the FIRST read counts: a later refetch (every create/update/delete
  /// reloads) leaves this false so the configured rows stay on screen rather
  /// than flashing a skeleton over what the operator is already reading.
  bool get isFirstLoad => !_resolvedOnce;

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
    // Seeded state is a resolved state, so a bound view renders the rows rather
    // than a skeleton waiting for a fetch the test never makes.
    _resolvedOnce = true;
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
  ///
  /// Resolving flips [isFirstLoad] false however it turns out (a non-2xx and a
  /// thrown request are answers too), so the view swaps its skeleton for the
  /// real rows rather than skeletoning forever on a failed first read.
  Future<void> reload() async {
    final bool firstLoad = isFirstLoad;
    try {
      final response = await Http.get('/notification-channels');
      _resolvedOnce = true;
      if (!response.successful) {
        Log.error(
          '[NotificationChannelController.reload] ${response.errorMessage}',
        );
        // The cache stands, but a first read that failed still has to repaint:
        // the view is showing a skeleton and needs to hear that it is over.
        if (firstLoad) refreshUI();
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
      // A thrown request is an answered one as far as the screen is concerned.
      _resolvedOnce = true;
      if (firstLoad) refreshUI();
    }
  }

  /// Drops the previous session's channel roster and the push-provisioning
  /// flag, publishes the cleared state, then refetches for the identity that is
  /// now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): [reload] keeps
  /// both values on any failure, so across an identity change a failed refetch
  /// would otherwise show the previous team's Slack/webhook/PagerDuty/Teams
  /// wiring (with its credential hints) to another team. [_pushProvisioned]
  /// returns to its optimistic `true` default, the same "not yet answered"
  /// value it holds before the first fetch, so a cleared state never claims
  /// push is unconfigured.
  @override
  Future<void> resetForSession() async {
    _channels = [];
    _pushProvisioned = true;
    // Back to "not asked yet": the incoming identity must get a skeleton, not
    // the previous tenant's conclusion that nothing is wired up.
    _resolvedOnce = false;
    clearErrors();
    refreshUI();

    await reload();
  }

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Creates a team notification channel via `POST /notification-channels`
  /// and reloads the roster on success.
  ///
  /// [fields] is the raw create-form field map (`name`, `channel_type`,
  /// `credentials`, `is_enabled`, `severity`). The credential-shape client
  /// validation (required-on-first-connect, per-field length bounds) runs
  /// BEFORE this is called: [NotificationChannelsView]'s own `ChannelType`
  /// switch owns it, because the backend's per-type `required_if` has no
  /// magic [Rule] equivalent (see the view's class docblock). This method's
  /// only job on failure is to [_namespace] whatever the SERVER rejects, so
  /// a client- and a server-rejected field publish under the identical
  /// `<type>.<wire_key>` shape.
  ///
  /// Answers whether the channel was written. The per-field detail does not
  /// travel in the return value: it is published in [validationErrors] and
  /// the view reads it back through [getError]. So `false` with a populated
  /// [validationErrors] means "stay on the form and correct the flagged
  /// fields", and `false` with an EMPTY one means the generic error toast has
  /// already fired (a transport error / 500).
  Future<bool> create(Map<String, dynamic> fields) async {
    final ChannelType type = _typeFromWire(fields['channel_type'] as String?);

    try {
      final response = await Http.post(
        '/notification-channels',
        data: fields,
      );
      if (!response.successful) {
        Log.error(
          '[NotificationChannelController.create] ${response.errorMessage}',
        );
        return _publishFieldErrors(type, response);
      }

      await reload();
      _notifySuccess('uptizm.teams.channels_connect_button', fields);
      return true;
    } catch (error) {
      Log.error('[NotificationChannelController.create] failed: $error');
      _toastError(null);
      return false;
    }
  }

  /// Updates the team notification channel [id] via `PUT
  /// /notification-channels/:id` and reloads the roster on success.
  ///
  /// [fields] carries only the wire keys the caller intends to change (a
  /// partial `credentials` object REPLACES the whole stored blob
  /// server-side, so a caller editing credentials must send the full
  /// `credentials` shape; omitting the key entirely, as a severity/enabled-
  /// only toggle does, leaves the stored credentials untouched). Client
  /// validation happens the same way as [create]'s (the caller's job, see
  /// that docblock); this method only [_namespace]s a server rejection.
  /// Answers whether the channel was written, mirroring [create]'s contract.
  Future<bool> update(
    String id,
    Map<String, dynamic> fields,
  ) async {
    final ChannelType type = _typeFromWire(fields['channel_type'] as String?);

    try {
      final response = await Http.put(
        '/notification-channels/$id',
        data: fields,
      );
      if (!response.successful) {
        Log.error(
          '[NotificationChannelController.update] $id: ${response.errorMessage}',
        );
        return _publishFieldErrors(type, response);
      }

      await reload();
      _notifySuccess('uptizm.teams.channels_save_button', fields);
      return true;
    } catch (error) {
      Log.error('[NotificationChannelController.update] $id failed: $error');
      _toastError(null);
      return false;
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

  /// Publishes a failed write [response] as either per-field validation
  /// errors or a generic toast, and answers `false` either way.
  ///
  /// Namespaces the field errors under [type] (single message per field,
  /// keyed `<type>.<wire_key>`, e.g. `webhook.credentials.url`) via
  /// [_namespace] when the failed write carried the Laravel 422 shape via
  /// [MagicResponse.errors], and assigns them to the inherited
  /// [validationErrors] so the view's [getError] reads them. A failure
  /// carrying NO field errors is a transport error or a 500, so it gets the
  /// generic toast and leaves [validationErrors] empty, which is the signal
  /// [NotificationChannelsView] uses to tell "stay and correct" apart from
  /// "already told".
  bool _publishFieldErrors(
    ChannelType type,
    MagicResponse response,
  ) {
    final Map<String, String> fieldErrors = fieldErrorsFromResponse(response);
    if (fieldErrors.isNotEmpty) {
      validationErrors = _namespace(type, fieldErrors);
      refreshUI();
      return false;
    }

    _toastError(response.errorMessage);
    return false;
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

  // ---------------------------------------------------------------------------
  // Namespacing: this vertical's second forced exception. See
  // `notification_channels_view.dart`'s class docblock for the first (the
  // exhaustive `ChannelType` rule switch, which lives in the view since it is
  // the caller that already knows the type and holds no server round trip in
  // between).
  // ---------------------------------------------------------------------------

  /// Prefixes every key of [raw] with `<type>.`, so a client rejection (the
  /// view's own `validate()` call, before this method is ever reached) and a
  /// server 422 ([_resolveFieldErrors]) publish under the identical
  /// namespaced shape [NotificationChannelsView] reads via [getError].
  ///
  /// Needed because [NotificationChannelsView] holds one draft PER
  /// [ChannelType] open at once, and `credentials.url` is the wire key for
  /// BOTH the webhook and Teams card: an unnamespaced key could not say
  /// which card's error a failure was.
  Map<String, String> _namespace(ChannelType type, Map<String, String> raw) {
    final String prefix = type.name;

    return <String, String>{
      for (final MapEntry<String, String> entry in raw.entries)
        '$prefix.${entry.key}': entry.value,
    };
  }

  /// Records a client-side validation failure [message] directly under the
  /// already-namespaced [field] (e.g. `teams.credentials.url`), for a check
  /// no magic [Rule] can express.
  ///
  /// [NotificationChannelsView] is the one caller: magic ships no `Url`
  /// rule, and approximating one with [In] would refuse every valid value,
  /// so its webhook/Teams URL-shape check runs in the view and publishes
  /// here rather than through a [Rule]. Mirrors [clearFieldError]'s
  /// single-field granularity in reverse.
  void setFieldError(String field, String message) {
    validationErrors[field] = message;
    refreshUI();
  }
}
