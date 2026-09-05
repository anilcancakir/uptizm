import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_form_support.dart';
import 'monitor_metrics_support.dart';
import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/escalation_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/mocks/monitors.dart';
import '../../../app/models/escalation_policy.dart';
import '../../../app/support/submits_once.dart';
import '../../../ui/components/form_actions/index.dart';
import '../../../ui/components/switch_row/index.dart';
import '../../../ui/components/key_value_editor/key_value_editor.dart';
import '../../../ui/components/region_picker/region_picker.dart';

/// **The monitor configuration form (fields + submit row).**
///
/// A faithful Flutter port of the React `MonitorForm.tsx`. It renders the full
/// monitor definition surface inside a surface [Card], with an optional
/// [banner] slot above it (used by the AI-assisted flow to show its summary).
/// Simple by default; the "Advanced configuration" switch reveals HTTP method,
/// request headers, the credential block, request body, and timeout.
///
/// The form is self-contained state: every field round-trips through this
/// widget's [State] and the [onSubmit] / [onCancel] callbacks report the user's
/// intent. The check-interval [Select] gates options below the team's real
/// billing tier's fastest allowed interval, labelling each locked option with
/// the cheapest plan that unlocks it (via [EntitlementController]:
/// `minCheckIntervalSec` + `planNameUnlocking`). This mirrors the backend's own
/// interval-floor 422 so a locked interval is nudged here, not on save.
///
/// Field validation is the framework's, not this widget's. The rules live on
/// [MonitorController] (`_createRules` / `_updateRules`, mirrored off the
/// backend's own `FormRequest`s), a refusal is published in
/// `validationErrors`, and every `error:` slot below reads it back through
/// `getError`. Two checks stay here because magic ships no rule that can state
/// them: the type-dependent target shape (a regex on the backend) and the
/// credential block's cross-field shape.
///
/// No color is hardcoded: every tone flows through semantic alias keys, and no
/// footer button carries `w-full` (a full-width button inside a `flex-row`
/// forces unbounded width and aborts the row's layout).
///
/// ```dart
/// MonitorForm(
///   submitLabel: trans('uptizm.monitors.form_submit_create'),
///   onSubmit: (fields) => controller.create(fields),
///   onCancel: () => Navigator.of(context).pop(),
/// )
/// ```
class MonitorForm extends StatefulWidget {
  /// Initial monitor name. Defaults to empty (React `initialName = ""`).
  final String initialName;

  /// Initial monitor type token (`http` / `tcp`, the two protocols the backend
  /// supports). Defaults to `http`.
  final String initialType;

  /// Initial monitored URL or host. Defaults to empty (React `initialUrl = ""`).
  final String initialUrl;

  /// Initial check-interval token (`10s` / `30s` / `1m` / `3m` / `5m`). Defaults
  /// to `30s` (React `initialInterval = "30s"`).
  final String initialInterval;

  /// The monitor's real check interval in seconds, when editing.
  ///
  /// Takes precedence over [initialInterval]. The backend accepts any interval
  /// from 30s to 24h, so a monitor can legitimately hold a value no option
  /// covers (set through the API); rather than snapping it to the nearest option
  /// and rewriting it on the next save, the select grows a verbatim option for
  /// that exact value.
  final int? initialIntervalSec;

  /// Initial selected probe-region values. Defaults to a SINGLE region,
  /// `['eu-central']`.
  ///
  /// One region, not two, and Frankfurt rather than US East. The Free plan
  /// allows exactly one, so a two-region default was a selection the cheapest
  /// plan could not save (see [_defaultRegions]); and picking further regions on
  /// the operator's behalf spends real probe budget on a guess. EU Central is
  /// the closest region to where this product is operated from, so it is the
  /// least surprising single default. Paid plans add more from the picker.
  final List<String> initialRegions;

  /// Initial request headers. Defaults to EMPTY.
  ///
  /// The React original seeded a demo `Authorization: Bearer …` row, and that
  /// placeholder was being SAVED: every monitor created by hand sent a literal
  /// `Bearer …` (a real U+2026) to the target. On an endpoint that validates
  /// auth, that is a 401 and a healthy monitor reading as down; the ellipsis is
  /// also non-ASCII, which the fetch spec does not allow in a header value, so
  /// the edge worker logged a warning on every probe. A demo value belongs in
  /// the preview, not in the create default.
  final List<KeyValueRow> initialHeaders;

  /// The monitor's stored credential descriptor, as `MonitorResource` emits
  /// it, or `null` when the monitor sends no credential.
  ///
  /// A fail-closed allowlist on the backend (`MonitorResource::redactAuthConfig`)
  /// reduces it to `type`, `username` and `header`, so this map NEVER carries a
  /// secret and the form must not pretend it does: the secret input renders
  /// empty with a placeholder saying a credential is stored. Leaving it blank
  /// omits `auth_config` from an edit entirely, which is the only way a rename
  /// can leave a stored credential alone (see [_MonitorFormState.buildFields]).
  final Map<String, dynamic>? initialAuthConfig;

  /// A credential the operator TYPED but has not saved yet, secret included,
  /// or `null` when there is none.
  ///
  /// The AI setup step's own credential block composes one to probe a protected
  /// endpoint with, and the review form this input feeds is where that monitor
  /// is actually created; without carrying it the created monitor would hold no
  /// credential and its very first check would answer 401 on the endpoint the
  /// analysis just read successfully.
  ///
  /// Deliberately NOT [initialAuthConfig]: that one describes a credential the
  /// backend already holds and redacted, so its blank secret means "leave the
  /// stored one alone". This one is not stored anywhere yet, so its secret has
  /// to reach the create request. Seeded through
  /// [MonitorCredential.fromPendingMap], which is where the two meanings are
  /// named apart.
  final Map<String, dynamic>? initialPendingAuthConfig;

  /// Initial escalation-policy id, or `null` for "no policy pinned".
  ///
  /// Null is a real, meaningful state rather than a missing value: the backend's
  /// `EscalationDispatcher::resolvePolicy()` falls back to the team's default
  /// (earliest-created) policy when the monitor pins none, so there is nothing
  /// to invent a default for here.
  final String? initialPolicy;

  /// Initial uptime SLO target as a percentage string, or `''` for none.
  /// Defaults to `99.9` (React `initialSlo = "99.9"`).
  final String initialSlo;

  /// Initial HTTP method token (lowercase wire value, e.g. `get`).
  final String initialMethod;

  /// Initial request timeout in seconds, as the raw field string.
  final String initialTimeoutSec;

  /// Initial request body for the advanced section.
  final String initialBody;

  /// Initial AI-assist mode token (`off` / `suggest`).
  final String initialAiMode;

  /// Whether the monitor may publish its own incident status updates.
  final bool initialAiAutoUpdates;

  /// Initial "alert when this monitor goes down" state.
  final bool initialAlertOnDown;

  /// Whether the probe follows a 3xx to its destination.
  ///
  /// A real setting rather than a request-shape default, so an edit sends the
  /// operator's own value: it has a control on this form, which is exactly the
  /// difference `buildFields()` documents between the two kinds of field.
  final bool initialFollowRedirects;

  /// Initial "alert when it recovers" state.
  final bool initialAlertOnRecover;

  /// Whether this form is editing an existing monitor rather than creating one.
  ///
  /// It changes what [_MonitorFormState.buildFields] posts. A create sends the
  /// full request shape, including sensible defaults for the settings this form
  /// exposes no control for. An edit sends ONLY the fields the form owns, so a
  /// monitor's unsurfaced configuration (`expected_status_code`, `tags`, the
  /// status-page and SSL flags) survives a save instead of being reset to a
  /// create-time default, and `auth_config` travels only when the operator
  /// changed the credential ([initialAuthConfig]). Every rule in
  /// `UpdateMonitorRequest` is `sometimes`, so a partial payload is the
  /// intended update shape.
  final bool isEdit;

  /// Open the advanced section on mount (the AI flow pre-fills advanced
  /// fields). Defaults to `false`.
  final bool startAdvanced;

  /// Optional content rendered above the form card (e.g. the AI summary
  /// banner). Maps to the React `banner` slot.
  final Widget? banner;

  /// Label for the primary submit button (e.g. "Create monitor").
  final String submitLabel;

  /// Called when the user taps the primary submit button (once the shape checks
  /// this form still owns pass), with the field map assembled by
  /// [_MonitorFormState.buildFields] (the backend request shape). The caller
  /// decides whether that fires a create or a save.
  ///
  /// Answers whether the monitor was WRITTEN, and nothing more: the per-field
  /// detail of a refusal lives on [MonitorController.validationErrors], which
  /// every field below reads through `getError`. So `false` means "stay here",
  /// and whether the operator has already been told why is answered by whether
  /// those errors are populated (see [MonitorController.create]).
  final Future<bool> Function(Map<String, dynamic> fields) onSubmit;

  /// Called when the user taps Cancel.
  final VoidCallback onCancel;

  /// Creates a [MonitorForm].
  const MonitorForm({
    super.key,
    this.initialName = '',
    this.initialType = 'http',
    this.initialUrl = '',
    this.initialInterval = '30s',
    this.initialIntervalSec,
    this.initialRegions = const ['eu-central'],
    this.initialHeaders = const <KeyValueRow>[],
    this.initialAuthConfig,
    this.initialPendingAuthConfig,
    this.initialPolicy,
    this.initialSlo = '99.9',
    this.initialMethod = 'get',
    this.initialTimeoutSec = '30',
    this.initialBody = '',
    this.initialAiMode = 'off',
    this.initialAiAutoUpdates = false,
    this.initialAlertOnDown = true,
    this.initialFollowRedirects = false,
    this.initialAlertOnRecover = true,
    this.isEdit = false,
    this.startAdvanced = false,
    this.banner,
    required this.submitLabel,
    required this.onSubmit,
    required this.onCancel,
  });

  @override
  State<MonitorForm> createState() => _MonitorFormState();
}

class _MonitorFormState extends State<MonitorForm>
    with SubmitsOnce<MonitorForm> {
  /// The controller that owns this form's validation errors.
  ///
  /// Resolved from the container rather than passed in, the same way
  /// [_escalation] below is: it is the one [MonitorController] the create and
  /// edit screens already back onto, so the rules it checked the payload
  /// against and the messages this form paints are the same object's state.
  /// Every error slot below reads it through `getError`, and the field stack is
  /// wrapped in a [ListenableBuilder] on it so a refusal repaints.
  final MonitorController _monitor = MonitorController.instance;

  /// Monitor name (React `name`).
  late String _name;

  /// Monitor type token (React `type`).
  late String _type;

  /// Monitored URL or host (React `url`).
  late String _url;

  /// Inline error for the target's SHAPE, or null when it is well formed.
  ///
  /// The one field-level check that did not move to the controller, because
  /// magic ships no regex rule and the backend's own check is one
  /// (`StoreMonitorRequest::targetRules()`): an HTTP monitor needs a full URL, a
  /// TCP monitor needs `host:port`. Set on submit by [_targetError], cleared
  /// when the target or the type changes, and rendered in preference to the
  /// controller's own `url` message because it is the more specific of the two.
  String? _urlError;

  /// Check-interval token (React `intervalValue`).
  late String _intervalValue;

  /// Selected probe-region values (React `regions`).
  late List<String> _regions;

  /// Whether the advanced section is expanded (React `advanced`).
  late bool _advanced;

  /// HTTP method token for the advanced section (React `method`).
  String _method = 'get';

  /// Request headers for the advanced section (React `headers`).
  late List<KeyValueRow> _headers;

  /// The credential the operator has composed in the advanced section.
  ///
  /// Seeded from [MonitorForm.initialAuthConfig], which carries no secret, so
  /// on an edit this starts equal to [_storedCredential] and stays that way
  /// until the operator touches the block.
  late MonitorCredential _credential;

  /// The credential as the backend described it, kept verbatim so
  /// [_credentialTouched] can answer "did the operator change anything here?".
  ///
  /// That question is the whole edit contract: a form that round-trips what it
  /// received would post `{type: basic, username: x}` with no password, which
  /// 422s, and one that helpfully filled a masked placeholder would post the
  /// placeholder as the new password.
  late MonitorCredential _storedCredential;

  /// Inline credential errors from the CLIENT-side shape check, keyed by the
  /// wire field name (`username`, `password`, `token`, `key`, `header`, or
  /// `type`).
  ///
  /// Local for the same reason [_urlError] is: the credential's rule is a
  /// cross-field one (which key is required depends on the selected scheme) and
  /// magic has no rule that says so. The backend's own `auth_config.*`
  /// rejections arrive on the controller and are merged in by
  /// [_credentialFieldErrors].
  Map<String, String> _credentialErrors = const <String, String>{};

  /// Request body for the advanced section (React `body`).
  String _body = '';

  /// Timeout in seconds, kept as a raw string (React `timeoutMs`).
  String _timeoutMs = '30';

  /// Alert when the monitor goes down (React `notifyDown`).
  bool _followRedirects = false;

  bool _notifyDown = true;

  /// Alert when the monitor recovers (React `notifyRecover`).
  bool _notifyRecover = true;

  /// Selected escalation-policy id, or `null` when no policy is pinned.
  String? _policy;

  /// Selected SLO target string (React `slo`).
  late String _slo;

  /// AI-assist mode token (`off` / `suggest`). Defaults to `off`; there is no
  /// React source counterpart, this is a new uptizm-only control.
  String _aiMode = 'off';

  /// Whether Uptizm may publish this monitor's incident updates on its own.
  bool _aiAutoUpdates = false;

  /// The probe regions projected into the [RegionPicker]'s [Region] shape,
  /// computed once from the static [allRegions] fixture.
  late final List<Region> _regionOptions = probeRegionsToRegions(allRegions);

  /// The team's real billing entitlement, driving the check-interval lock. The
  /// interval field is wrapped in a [ListenableBuilder] on this controller so
  /// the locked options re-resolve the moment the real plan lands.
  final EntitlementController _entitlement = EntitlementController.instance;

  /// The team's real escalation policies, backing the Escalation policy select.
  ///
  /// Resolved through the IoC container rather than constructed, so this form
  /// shares the one roster the escalation views already keep warm. The select is
  /// wrapped in a [ListenableBuilder] on this controller so the options appear
  /// as soon as the roster lands, and a team with no policies gets an honest
  /// hint instead of invented options.
  final EscalationController _escalation = Magic.findOrPut(
    EscalationController.new,
  );

  /// Select value standing for "pin nothing, follow the team default".
  ///
  /// [MSSelect] needs a non-null value per option, so the null pin travels as
  /// this sentinel and is mapped back to `null` in `onChange`. It cannot collide
  /// with a real id: policy ids are server-generated uuids.
  static const String _teamDefaultPolicyToken = '';

  /// Select value standing for an interval no preset option covers.
  static const String _customIntervalToken = 'custom';

  /// The monitor's real interval in seconds when it matches no preset option.
  ///
  /// Null whenever the interval is representable, which is every monitor created
  /// through this form; only an API-set interval lands here.
  int? _customIntervalSec;

  /// Whether the user has explicitly picked a check interval.
  ///
  /// Once true, [_onEntitlementChanged] stops re-seeding [_intervalValue] from
  /// the entitlement floor: a deliberate user pick always wins over a floor
  /// that resolves after the fact.
  bool _intervalTouchedByUser = false;

  /// Whether the user has explicitly changed the region selection.
  ///
  /// Same contract as [_intervalTouchedByUser]: once true,
  /// [_onEntitlementChanged] stops trimming [_regions] to the plan allowance,
  /// because a deliberate pick outranks a default.
  bool _regionsTouchedByUser = false;

  @override
  void initState() {
    super.initState();
    _name = widget.initialName;
    _type = widget.initialType;
    _url = widget.initialUrl;
    final int? intervalSec = widget.initialIntervalSec;
    final String? intervalToken = intervalSec == null
        ? null
        : intervalTokenForSeconds(intervalSec);
    if (intervalSec != null && intervalToken == null) {
      // Editing a monitor whose real interval matches no preset: keep it
      // verbatim, the entitlement floor never applies to an explicit stored
      // value (see [_defaultIntervalToken]'s docblock).
      _customIntervalSec = intervalSec;
      _intervalValue = _customIntervalToken;
    } else if (intervalSec != null) {
      // Editing a monitor whose real interval matches a preset exactly: show
      // that preset verbatim, even if the team's CURRENT plan would now lock
      // it, rather than silently snapping it toward today's floor.
      _intervalValue = intervalToken!;
    } else {
      _intervalValue = _defaultIntervalToken();
    }
    _regions = _defaultRegions();
    _advanced = widget.startAdvanced;
    _headers = List<KeyValueRow>.from(widget.initialHeaders);
    _storedCredential = MonitorCredential.fromRedactedMap(
      widget.initialAuthConfig,
    );
    // A pending credential (typed on the AI setup step, never saved) starts the
    // block already composed, secret included, while [_storedCredential] stays
    // the redacted description of what the backend holds. Keeping the two
    // separate is what lets [_credentialTouched] read this as a real change
    // rather than as "nothing happened here".
    _credential = widget.initialPendingAuthConfig == null
        ? _storedCredential
        : MonitorCredential.fromPendingMap(widget.initialPendingAuthConfig);
    _policy = widget.initialPolicy;
    _slo = widget.initialSlo;
    _method = widget.initialMethod;
    _timeoutMs = widget.initialTimeoutSec;
    _body = widget.initialBody;
    _aiMode = widget.initialAiMode;
    _aiAutoUpdates = widget.initialAiAutoUpdates;
    _followRedirects = widget.initialFollowRedirects;
    _notifyDown = widget.initialAlertOnDown;
    _notifyRecover = widget.initialAlertOnRecover;

    // Load the policy roster explicitly. magic only fires `onInit` for a view's
    // BACKING controller, and this form's backing controller is the monitor one,
    // so the escalation controller's own bootstrap never runs here and the select
    // would render its empty state forever.
    _escalation.reload();

    // The entitlement floor arrives asynchronously (`.instance` only kicks off
    // its own load, it does not await it), so the seeded default above reads a
    // pre-fetch permissive floor of 0 on the very first frame. Listen for the
    // real plan landing and re-seed once, the same way [_escalation.reload]
    // above self-triggers this form's OTHER secondary controller read.
    _entitlement.addListener(_onEntitlementChanged);
  }

  @override
  void dispose() {
    _entitlement.removeListener(_onEntitlementChanged);
    super.dispose();
  }

  /// Re-seeds [_intervalValue] once the real entitlement lands, unless the
  /// user already picked an interval or this form is editing a monitor's real
  /// stored value (never overridden by the plan floor, see [initState]).
  void _onEntitlementChanged() {
    final List<String> nextRegions = _defaultRegions();
    final bool regionsChanged =
        !widget.isEdit &&
        !_regionsTouchedByUser &&
        nextRegions.length != _regions.length;

    if (widget.initialIntervalSec != null || _intervalTouchedByUser) {
      if (regionsChanged) setState(() => _regions = nextRegions);

      return;
    }

    final String next = _defaultIntervalToken();
    if (next == _intervalValue && !regionsChanged) return;
    setState(() {
      _intervalValue = next;
      if (regionsChanged) _regions = nextRegions;
    });
  }

  /// Resolves the create-time region selection: [widget.initialRegions],
  /// truncated to the plan's allowance.
  ///
  /// The baseline default is two regions, which is more than Free allows, and
  /// the picker deliberately never locks an ALREADY SELECTED tile (that is what
  /// keeps a grandfathered monitor's stored regions from being silently
  /// dropped). Without this the create form would therefore open a Free
  /// operator on a selection their own plan refuses, and the first thing they
  /// would learn is a 422 on save, which is the exact failure the region gate
  /// exists to prevent.
  ///
  /// Never applies on an EDIT: a stored selection is the monitor's own
  /// configuration and outranks the plan, exactly as the server's delta rule
  /// allows.
  List<String> _defaultRegions() {
    final List<String> baseline = List<String>.from(widget.initialRegions);
    if (widget.isEdit) return baseline;

    final int? allowance = _entitlement.maxRegionsPerMonitor;
    if (allowance == null || baseline.length <= allowance) return baseline;

    return baseline.take(allowance).toList();
  }

  /// Resolves the create-time default interval token: [widget.initialInterval]
  /// as the baseline (the plain `'30s'` literal, or the AI review's own
  /// recommendation), raised to the entitlement's floor when the baseline
  /// would otherwise land the operator on a locked option.
  ///
  /// Only ever raises the baseline, never lowers it: a baseline that already
  /// clears the floor (e.g. an AI recommendation on a faster plan) is left
  /// alone. This never runs for an EDIT of a real stored interval ([initState]
  /// routes that value through [intervalTokenForSeconds] instead), so a
  /// monitor's own configuration is never touched by a plan change.
  String _defaultIntervalToken() {
    final int baselineSeconds = kIntervalSeconds[widget.initialInterval] ?? 0;
    final int floorSeconds = _entitlement.minCheckIntervalSec;
    if (floorSeconds <= baselineSeconds) return widget.initialInterval;

    return _ceilToOfferedToken(floorSeconds);
  }

  /// The fastest offered [kCheckIntervals] token whose duration is at least
  /// [seconds] (the plan floor), falling back to the slowest offered token
  /// when the floor exceeds every preset (there is no Enterprise-fast preset
  /// below the fastest option either, so [kCheckIntervals]'s own extremes
  /// bound the result on both sides).
  String _ceilToOfferedToken(int seconds) {
    for (final MetricOption option in kCheckIntervals) {
      if ((kIntervalSeconds[option.value] ?? 0) >= seconds) {
        return option.value;
      }
    }
    return kCheckIntervals.last.value;
  }

  /// Whether the monitor is an HTTP check (React `isHttp`). Gates the advanced
  /// method/headers/body fields.
  bool get _isHttp => _type == 'http';

  /// Whether the operator changed anything in the credential block.
  ///
  /// False means "leave the stored credential alone", which on an edit is
  /// expressed by omitting `auth_config` from the request entirely: the form
  /// holds no secret to resend and an explicit null would blank the stored one.
  bool get _credentialTouched => _credential != _storedCredential;

  /// Whether the request this form is about to build carries `auth_config`.
  ///
  /// Always on a create (the backend expects the full request shape and a
  /// monitor with no credential says so with an explicit null); on an edit only
  /// when the operator touched the block.
  bool get _sendsCredential => !widget.isEdit || _credentialTouched;

  /// Whether leaving the secret input blank keeps the credential the backend
  /// already holds: only while editing a monitor whose stored scheme is still
  /// the selected one. Switching the scheme retires the stored secret, so the
  /// field stops being optional and the placeholder stops claiming otherwise.
  bool get _keepsStoredSecret =>
      widget.isEdit &&
      !_storedCredential.isNone &&
      _credential.type == _storedCredential.type;

  // ---------------------------------------------------------------------------
  // Build.
  // ---------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6',
      children: [
        // 1. Optional banner above the card (AI summary slot).
        if (widget.banner != null) widget.banner!,

        // 2. The form card (surface variant) with the field stack.
        //
        //    Wrapped in a ListenableBuilder on the controller because that is
        //    where the field errors live now: `validate()` and a server 422
        //    both publish into `validationErrors` and notify, and without a
        //    listener the refusal would be recorded and never painted.
        MSCard(
          variant: CardVariant.surface,
          child: ListenableBuilder(
            listenable: _monitor,
            builder: (BuildContext context, Widget? _) => WDiv(
              className: 'flex flex-col gap-5',
              children: [
                _buildNameField(),
                _buildTypeField(),
                _buildUrlField(),
                _buildIntervalField(),
                _buildRegionsField(),
                _buildSloField(),
                _buildAiModeField(),
                _buildAiAutoUpdatesField(),
                _buildNotificationsSection(),
                _buildAdvancedToggle(),
                if (_advanced) ..._buildAdvancedSection(),
              ],
            ),
          ),
        ),

        // 3. Footer: Cancel + Submit, right-aligned. Shared with the status-page
        //    and escalation editors through FormActions, so a form's submit is
        //    in the same place on every screen.
        FormActions(
          submitLabel: widget.submitLabel,
          // `isSubmitting` is the guard, not just the spinner: the button drops
          // its tap while loading, so a double tap on Create cannot create two
          // monitors (each counting against the plan limit).
          isSubmitting: isSubmitting,
          onSubmit: () => submitOnce(_submitIfValid),
          cancelLabel: trans('uptizm.monitors.form_cancel'),
          onCancel: widget.onCancel,
        ),
      ],
    );
  }

  /// Builds the Name field.
  Widget _buildNameField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_field_name_label'),
      error: _monitor.getError('name'),
      child: MSInput(
        value: _name,
        onChanged: (value) {
          _monitor.clearFieldError('name');
          setState(() => _name = value);
        },
        placeholder: trans('uptizm.monitors.form_field_name_placeholder'),
      ),
    );
  }

  /// Builds the Monitor-type segmented control.
  ///
  /// [SegmentedControl] takes `options: List<String>` + `selectedIndex`, so the
  /// labels are projected from [kMonitorTypes] and the change handler maps the
  /// tapped index back to the option's machine value.
  Widget _buildTypeField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_type_label'),
      error: _monitor.getError('type'),
      child: MSSegmentedControl<String>(
        options: kMonitorTypes.map((o) => o.label).toList(),
        selectedIndex: _indexOfValue(kMonitorTypes, _type),
        onChanged: (index) => setState(() {
          _type = kMonitorTypes[index].value;
          _monitor.clearFieldError('type');
          // The target's valid shape depends on the type, so a pending error
          // no longer applies once the type changes. Both halves go: the shape
          // this form checked, and whatever the backend said about `url`.
          _urlError = null;
          _monitor.clearFieldError('url');
          // Only an HTTP probe carries a credential, so the block is hidden for
          // TCP. Reverting it to what the monitor already stores (nothing, on a
          // create) is what keeps a hidden field out of the payload: the form
          // would otherwise post a credential the operator can no longer see,
          // or on an edit read as "touched" and blank a stored one.
          if (!_isHttp) {
            _credential = _storedCredential;
            _credentialErrors = const <String, String>{};
          }
        }),
      ),
    );
  }

  /// Builds the URL field. The label, hint, and placeholder all switch on the
  /// HTTP/non-HTTP type: an HTTP monitor targets a full URL, a TCP monitor
  /// targets a `host:port` (the backend validates the field accordingly).
  Widget _buildUrlField() {
    return MSFormField(
      label: _isHttp
          ? trans('uptizm.monitors.form_url_label')
          : trans('uptizm.monitors.form_url_label_other'),
      hint: _isHttp
          ? trans('uptizm.monitors.form_url_hint_http')
          : trans('uptizm.monitors.form_url_hint_other'),
      // The shape check this form still owns outranks the controller's own
      // `url` message: both can be pending at once and the specific one (an
      // HTTP monitor needs a full URL) says more than "required".
      error: _urlError ?? _monitor.getError('url'),
      child: MSInput(
        value: _url,
        onChanged: (value) {
          _monitor.clearFieldError('url');
          setState(() {
            _url = value;
            _urlError = null;
          });
        },
        placeholder: _isHttp
            ? trans('uptizm.monitors.form_url_placeholder')
            : trans('uptizm.monitors.form_url_placeholder_other'),
      ),
    );
  }

  /// Builds the Check-interval select.
  ///
  /// Each option whose interval (in seconds) is faster than the current plan's
  /// fastest allowed interval is [SelectOption.disabled] and its label is
  /// suffixed with the cheapest plan that unlocks it (React lines 148-157).
  Widget _buildIntervalField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_interval_label'),
      error: _monitor.getError('check_interval_sec'),
      // Rebuild the options against the live entitlement: until the real plan
      // resolves the floor is 0 (nothing locked), then the sub-tier intervals
      // lock the instant the plan lands.
      child: ListenableBuilder(
        listenable: _entitlement,
        builder: (context, _) => MSSelect<String>(
          value: _intervalValue,
          options: [
            // A verbatim option for an interval no preset covers, so the form
            // states the monitor's real cadence and round-trips it untouched.
            if (_customIntervalSec != null)
              SelectOption<String>(
                value: _customIntervalToken,
                label: trans('uptizm.monitors.interval_custom', {
                  'seconds': '$_customIntervalSec',
                }),
              ),
            ...kCheckIntervals.map(_intervalOption),
          ],
          onChange: (value) {
            if (value != null) {
              _monitor.clearFieldError('check_interval_sec');
              setState(() {
                _intervalValue = value;
                _intervalTouchedByUser = true;
              });
            }
          },
        ),
      ),
    );
  }

  /// Projects a check-interval [MetricOption] into a [SelectOption], locking and
  /// relabelling it when its interval is faster than the plan allows.
  SelectOption<String> _intervalOption(MetricOption option) {
    final int seconds = kIntervalSeconds[option.value] ?? 0;
    final bool locked = seconds < _entitlement.minCheckIntervalSec;
    if (!locked) {
      return SelectOption<String>(value: option.value, label: option.label);
    }

    // The cheapest plan whose fastest interval reaches this option unlocks it.
    final String requiredPlan = _entitlement.planNameUnlocking(
      (limits) => limits.checkIntervalSec <= seconds,
    );
    return SelectOption<String>(
      value: option.value,
      label: '${option.label} · $requiredPlan',
      disabled: true,
    );
  }

  /// Builds the Regions multi-select grid.
  ///
  /// Gated the same way the check-interval field is: the picker's cap is
  /// resolved against the live entitlement in a [ListenableBuilder] so it
  /// re-locks the instant the real plan lands, rather than staying permissive
  /// forever (see the class docblock on [EntitlementController.instance]).
  Widget _buildRegionsField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_regions_label'),
      hint: trans('uptizm.monitors.form_regions_hint'),
      error: _monitor.getError('regions'),
      child: ListenableBuilder(
        listenable: _entitlement,
        builder: (context, _) => RegionPicker(
          regions: _regionOptions,
          value: _regions,
          onChanged: (next) {
            _monitor.clearFieldError('regions');
            setState(() {
              _regions = next;
              // A deliberate pick outranks the plan-derived default, so the
              // entitlement landing later must not overwrite it.
              _regionsTouchedByUser = true;
            });
          },
          maxSelected: _regionCap(),
          capNotice: _regionCapNotice(),
        ),
      ),
    );
  }

  /// The effective region-selection cap: the plan's allowance, or the
  /// monitor's own stored region count when that count already exceeds it.
  ///
  /// Mirrors the backend's delta-only gate (`StoreMonitorRequest`): it refuses
  /// only when the submitted count exceeds BOTH the allowance and the count
  /// already stored on the monitor, so a grandfathered monitor stays at its
  /// stored count (never below it) while a new pick beyond that is still
  /// refused. On a create there is nothing stored, so the allowance binds
  /// normally. Returns null (unlimited) when the plan has no region cap.
  int? _regionCap() {
    final int? allowance = _entitlement.maxRegionsPerMonitor;
    if (allowance == null) return null;

    final int stored = widget.isEdit ? widget.initialRegions.length : 0;
    return stored > allowance ? stored : allowance;
  }

  /// One line stating the region allowance and the cheapest plan that raises it.
  ///
  /// Replaces the per-tile " · `<Plan>`" suffix the picker used to render. That
  /// suffix was copied from the check-interval field, where it is right because a
  /// 30-second interval genuinely is gated. No REGION is gated: every plan can
  /// probe from every region, and the plan limits how many at once. Suffixing
  /// "EU West" with "Pro" therefore blamed the region and invited an upgrade for
  /// a reason that does not exist.
  ///
  /// Null when the plan has no cap, or when no cheaper-plan upgrade would raise
  /// it (nothing to nudge toward), so the grid then renders with no notice.
  String? _regionCapNotice() {
    final int? cap = _regionCap();
    if (cap == null) return null;

    final String upgrade = _entitlement.planNameUnlocking(
      (limits) => limits.regions == null || limits.regions! > cap,
    );

    // Counted copy takes the `_one` / `_other` key pair this app already uses
    // for `fleet_open_incidents`, rather than writing "region(s)": a derived
    // count beside a hand-typed noun is the half-derived claim that shipped
    // "from 2 region" on the marketing FAQ.
    final String suffix = cap == 1 ? '_one' : '_other';
    final String key = upgrade.isEmpty
        ? 'uptizm.monitors.form_regions_cap_notice$suffix'
        : 'uptizm.monitors.form_regions_cap_notice_upgrade$suffix';

    return trans(key, {
      'count': '$cap',
      'plan': _entitlement.planName,
      'upgrade': upgrade,
    });
  }

  /// Builds the Uptime SLO target select.
  Widget _buildSloField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_slo_label'),
      hint: trans('uptizm.monitors.form_slo_hint'),
      error: _monitor.getError('slo_target'),
      child: MSSelect<String>(
        value: _slo,
        options: kSloTargets
            .map((o) => SelectOption<String>(value: o.value, label: o.label))
            .toList(),
        onChange: (value) {
          if (value == null) return;

          _monitor.clearFieldError('slo_target');
          setState(() => _slo = value);
        },
      ),
    );
  }

  /// Builds the AI-assist mode segmented control.
  ///
  /// A ladder of consent. `Off` keeps the monitor fully manual. `Suggest` posts
  /// detected anomalies to the dashboard inbox for an operator to accept or
  /// dismiss, and creates nothing on its own. `Auto` opens the incident itself
  /// and publishes its opening and closing status updates without asking, which
  /// is the only place in the product where model output reaches a customer
  /// with no human in between.
  ///
  /// The hint says that in the operator's own words rather than leaving the
  /// third rung to be discovered: the difference between the second and the
  /// third is not "more AI", it is who is allowed to write on the public page.
  Widget _buildAiModeField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_ai_mode_label'),
      hint: trans('uptizm.monitors.form_ai_mode_hint'),
      child: MSSegmentedControl<String>(
        options: kAiModes.map((o) => o.label).toList(),
        selectedIndex: _indexOfValue(kAiModes, _aiMode),
        onChanged: (index) => setState(() => _aiMode = kAiModes[index].value),
      ),
    );
  }

  /// Builds the autonomous-updates switch.
  ///
  /// Its own control rather than a fourth rung on the AI-assist ladder, because
  /// it is a different consent and the useful combinations cross the two. The
  /// ladder above answers "may you decide there is an incident?"; this answers
  /// "may you speak to my customers about one?". Folding the second into the
  /// third rung of the first forced an operator who only wanted their outages
  /// narrated to also accept autonomous incident creation, and it withheld
  /// narration from the most common incident there is: the one a threshold
  /// opened.
  ///
  /// Off by default, and the hint says what turning it on gives away, because
  /// what it gives away is the ability to write on a page the operator's own
  /// customers read.
  ///
  /// The two hints open by drawing the line between them ("what Uptizm may
  /// decide" / "what Uptizm may say"), which is how the pair stopped
  /// contradicting each other: while publishing rode on `ai_mode = auto`, the
  /// ladder's hint claimed it, and after the split that claim outlived the
  /// behaviour and sat two lines above a switch that said otherwise.
  Widget _buildAiAutoUpdatesField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_ai_auto_updates_label'),
      hint: trans('uptizm.monitors.form_ai_auto_updates_hint'),
      child: SwitchRow(
        label: trans('uptizm.monitors.form_ai_auto_updates_switch'),
        value: _aiAutoUpdates,
        onChanged: (value) => setState(() => _aiAutoUpdates = value),
      ),
    );
  }

  /// Builds the Notifications block: header, the two alert switches, and the
  /// escalation-policy select.
  Widget _buildNotificationsSection() {
    return WDiv(
      className: 'flex flex-col gap-3 border-t border-color-border pt-5',
      children: [
        WDiv(
          className: 'flex flex-col gap-0.5',
          children: [
            WText(
              trans('uptizm.monitors.form_notifications_title'),
              className: 'text-sm font-medium text-fg',
            ),
            WText(
              trans('uptizm.monitors.form_notifications_hint'),
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),
        SwitchRow(
          label: trans('uptizm.monitors.form_alert_down'),
          value: _notifyDown,
          onChanged: (value) => setState(() => _notifyDown = value),
        ),
        SwitchRow(
          label: trans('uptizm.monitors.form_alert_recover'),
          value: _notifyRecover,
          onChanged: (value) => setState(() => _notifyRecover = value),
        ),
        _buildEscalationField(),
      ],
    );
  }

  /// Builds the Escalation policy field over the team's REAL policy roster.
  ///
  /// Previously this select was fed the `escalationPolicies` design-lab fixture,
  /// so it offered "Standard" / "Critical path" to teams that owned neither, and
  /// the pick was never posted. Both halves mattered: the backend's
  /// `EscalationDispatcher::resolvePolicy()` reads `monitors.escalation_policy_id`
  /// to choose the paging ladder, so a fabricated-then-dropped selection meant
  /// the operator configured one ladder and an outage paged another.
  ///
  /// A "Team default" sentinel maps to a null pin, which is the honest name for
  /// what the backend does without one (fall back to the earliest-created
  /// policy). With no policies at all there is nothing to choose, so the field
  /// degrades to that sentence instead of an empty dropdown.
  Widget _buildEscalationField() {
    return ListenableBuilder(
      listenable: _escalation,
      builder: (BuildContext context, Widget? _) {
        final List<EscalationPolicy> policies = _escalation.policies;

        if (policies.isEmpty) {
          return MSFormField(
            label: trans('uptizm.monitors.form_escalation_label'),
            hint: trans('uptizm.monitors.form_escalation_empty'),
            child: const SizedBox.shrink(),
          );
        }

        // Drop a pin the roster no longer contains (a deleted policy) rather
        // than rendering a selection that resolves to nothing.
        final bool pinIsLive = policies.any((p) => p.id == _policy);

        return MSFormField(
          label: trans('uptizm.monitors.form_escalation_label'),
          hint: trans('uptizm.monitors.form_escalation_hint'),
          child: MSSelect<String>(
            value: pinIsLive ? _policy : _teamDefaultPolicyToken,
            options: [
              SelectOption<String>(
                value: _teamDefaultPolicyToken,
                label: trans('uptizm.monitors.form_escalation_none'),
              ),
              for (final EscalationPolicy policy in policies)
                SelectOption<String>(
                  value: policy.id,
                  label: policy.name ?? '',
                ),
            ],
            onChange: (value) {
              if (value == null) return;

              setState(
                () => _policy = value == _teamDefaultPolicyToken ? null : value,
              );
            },
          ),
        );
      },
    );
  }

  /// Builds the Advanced-configuration toggle: the switch row plus its hint.
  Widget _buildAdvancedToggle() {
    return WDiv(
      className: 'flex flex-col gap-1.5 border-t border-color-border pt-5',
      children: [
        SwitchRow(
          label: trans('uptizm.monitors.form_advanced_label'),
          value: _advanced,
          onChanged: (value) => setState(() => _advanced = value),
        ),
        WText(
          trans('uptizm.monitors.form_advanced_hint'),
          className: 'text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// Builds the advanced section: HTTP method, request headers, request body,
  /// and timeout.
  ///
  /// Method and headers render for HTTP monitors only; the body renders only
  /// for HTTP POST/PUT (React lines 257-273). Timeout always renders.
  List<Widget> _buildAdvancedSection() {
    final bool showBody = _isHttp && _method == 'post';
    return [
      if (_isHttp)
        MSFormField(
          label: trans('uptizm.monitors.form_method_label'),
          error: _monitor.getError('method'),
          child: MSSegmentedControl<String>(
            options: kHttpMethods.map((o) => o.label).toList(),
            selectedIndex: _indexOfValue(kHttpMethods, _method),
            onChanged: (index) {
              _monitor.clearFieldError('method');
              setState(() => _method = kHttpMethods[index].value);
            },
          ),
        ),
      if (_isHttp)
        MSFormField(
          label: trans('uptizm.monitors.form_headers_label'),
          hint: trans('uptizm.monitors.form_headers_hint'),
          child: KeyValueEditor(
            value: _headers,
            onChanged: (next) => setState(() => _headers = next),
          ),
        ),
      // The credential block sits beside the headers because it shapes the
      // same outbound request; the worker turns it into an `Authorization` (or
      // custom) header at the edge. HTTP only: a TCP probe opens a socket and
      // has nothing to authenticate with.
      if (_isHttp)
        MonitorCredentialFields(
          value: _credential,
          hasStoredSecret: _keepsStoredSecret,
          errors: _credentialFieldErrors(),
          onChanged: (next) {
            _clearCredentialErrors();
            setState(() {
              _credential = next;
              _credentialErrors = const <String, String>{};
            });
          },
        ),
      if (showBody)
        MSFormField(
          label: trans('uptizm.monitors.form_body_label'),
          child: MSTextarea(
            value: _body,
            onChanged: (value) => setState(() => _body = value),
            placeholder: trans('uptizm.monitors.form_body_placeholder'),
          ),
        ),
      MSFormField(
        label: trans('uptizm.monitors.form_timeout_label'),
        hint: trans('uptizm.monitors.form_timeout_hint'),
        error: _monitor.getError('timeout_sec'),
        child: MSInput(
          value: _timeoutMs,
          onChanged: (value) {
            _monitor.clearFieldError('timeout_sec');
            setState(() => _timeoutMs = value);
          },
          type: InputType.number,
          className: 'max-w-32',
        ),
      ),
      // HTTP only: a TCP probe opens a socket and has no redirect to follow.
      //
      // Laid out like `_buildAdvancedToggle()` rather than wrapped in an
      // `MSFormField`: `_buildSwitchRow` renders its own label, so a form field
      // around it would print the same sentence twice.
      if (_isHttp)
        WDiv(
          className: 'flex flex-col gap-1.5',
          children: [
            SwitchRow(
              label: trans('uptizm.monitors.form_follow_redirects_label'),
              value: _followRedirects,
              onChanged: (value) => setState(() => _followRedirects = value),
            ),
            WText(
              trans('uptizm.monitors.form_follow_redirects_hint'),
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),
    ];
  }

  /// The wire field names this form renders an inline error slot for.
  ///
  /// Anything the controller flags OUTSIDE this set has nowhere to land, so it
  /// is toasted instead of being silently held. That is not a theoretical case:
  /// the backend's plan gates add a bare `plan` key with no field behind it
  /// (`StoreMonitorRequest::withValidator()`), and a Free team at its monitor
  /// limit would otherwise tap Create and see nothing happen at all.
  static const Set<String> _ownedFields = <String>{
    'name',
    'url',
    'target',
    'type',
    'method',
    'check_interval_sec',
    'timeout_sec',
    'timeout_ms',
    'regions',
    'slo_target',
  };

  /// The owned fields that live INSIDE the advanced section, and are therefore
  /// invisible while it is collapsed.
  static const Set<String> _advancedFields = <String>{
    'method',
    'timeout_sec',
    'timeout_ms',
  };

  /// Runs the two checks the controller's rule map cannot express, then hands
  /// the fields to [MonitorForm.onSubmit] and reacts to what it reports.
  ///
  /// Everything a magic [Rule] can state now lives on [MonitorController] and is
  /// read back through `getError`, so a rejection needs no routing here. What is
  /// left in this method is what magic has no rule for: the type-dependent
  /// TARGET SHAPE (a regex on the backend) and the credential block's
  /// cross-field shape. Both are answers the client already knows, so they still
  /// run first and still stop the request.
  ///
  /// After a refused write there are exactly two things left to do that the
  /// error slots cannot do themselves: OPEN the advanced section when the
  /// flagged field is inside it (an inline error nobody can see is not a
  /// message), and toast whatever this form owns no slot for.
  Future<void> _submitIfValid() async {
    if (!_checkTargetAndCredential()) return;

    final bool written = await widget.onSubmit(buildFields());
    if (!mounted || written) return;

    _revealRefusedFields();
  }

  /// Runs the two shape checks this form still owns, painting their slots, and
  /// returns whether the form may be submitted.
  ///
  /// Both slots are always written (a passing check clears its slot) so a
  /// previously shown error never lingers after a corrected resubmit.
  ///
  /// The credential is only checked when the request will actually carry it: an
  /// untouched edit omits `auth_config`, and demanding a password for a
  /// credential nobody is changing would make a rename impossible.
  bool _checkTargetAndCredential() {
    final String? targetError = _targetError();
    final Map<String, String> credentialErrors = _sendsCredential && _isHttp
        ? validateMonitorCredential(_credential)
        : const <String, String>{};

    setState(() {
      _urlError = targetError;
      _credentialErrors = credentialErrors;
      // The credential block lives in the advanced section, so open it rather
      // than blocking submit with an explanation nobody can see.
      if (credentialErrors.isNotEmpty) _advanced = true;
    });

    return targetError == null && credentialErrors.isEmpty;
  }

  /// Makes a refused write's detail reachable: expands the advanced section
  /// when a flagged field hides there, and toasts anything with no slot.
  ///
  /// Reads the controller rather than a returned map, because that is where the
  /// detail lives now. An EMPTY error map here means the failure was not a
  /// per-field one (a transport error or a 500), and the controller has already
  /// surfaced its own toast for it, so this deliberately says nothing.
  void _revealRefusedFields() {
    final Map<String, String> errors = _monitor.validationErrors;

    final bool hiddenInAdvanced = errors.keys.any(
      (String key) => _advancedFields.contains(key) || _isCredentialKey(key),
    );
    if (hiddenInAdvanced && !_advanced) {
      setState(() => _advanced = true);
    }

    final Iterable<MapEntry<String, String>> unmapped = errors.entries.where(
      (MapEntry<String, String> entry) =>
          !_ownedFields.contains(entry.key) && !_isCredentialKey(entry.key),
    );
    if (unmapped.isEmpty) return;

    Magic.error(
      trans('uptizm.monitors.toast_save_failed_title'),
      unmapped.first.value,
    );
  }

  /// Whether [key] addresses the credential map: the block itself, or one of
  /// the dotted inner keys Laravel reports (`auth_config.password`).
  bool _isCredentialKey(String key) =>
      key == 'auth_config' || key.startsWith('auth_config.');

  /// The credential block's inline errors: the client-side shape check merged
  /// with whatever the backend rejected under `auth_config`.
  ///
  /// The dotted inner keys Laravel reports are exactly the field names
  /// [MonitorCredentialFields] renders its slots by, so a rejection lands under
  /// the input it names instead of in a toast; a bare `auth_config` addresses
  /// the scheme picker itself. The local check wins a collision, for the same
  /// reason [_urlError] outranks the controller's `url` message.
  Map<String, String> _credentialFieldErrors() {
    final Map<String, String> merged = <String, String>{..._credentialErrors};

    for (final MapEntry<String, String> entry
        in _monitor.validationErrors.entries) {
      if (!_isCredentialKey(entry.key)) continue;

      merged.putIfAbsent(
        entry.key == 'auth_config'
            ? 'type'
            : entry.key.substring('auth_config.'.length),
        () => entry.value,
      );
    }

    return merged;
  }

  /// Drops every credential rejection the controller is holding, so editing the
  /// block clears the backend's word on it the way it clears this form's.
  void _clearCredentialErrors() {
    for (final String key in _monitor.validationErrors.keys.toList()) {
      if (_isCredentialKey(key)) _monitor.clearFieldError(key);
    }
  }

  /// Validates the target against the selected type, mirroring the backend
  /// rule: an HTTP monitor needs a full http(s) URL with a host, a TCP monitor
  /// needs `host:port`. Returns the error message, or null when the target is
  /// well formed.
  String? _targetError() {
    final String value = _url.trim();
    if (value.isEmpty) {
      return trans('uptizm.monitors.form_url_error_required');
    }

    if (_isHttp) {
      final Uri? uri = Uri.tryParse(value);
      final bool valid =
          uri != null &&
          (uri.scheme == 'http' || uri.scheme == 'https') &&
          uri.host.isNotEmpty;
      return valid ? null : trans('uptizm.monitors.form_url_error_http');
    }

    // TCP: host:port with a port in 1..65535.
    final RegExpMatch? match = RegExp(
      r'^[^\s/:]+:(\d{1,5})$',
    ).firstMatch(value);
    if (match == null) {
      return trans('uptizm.monitors.form_url_error_tcp');
    }
    final int port = int.parse(match.group(1)!);
    if (port < 1 || port > 65535) {
      return trans('uptizm.monitors.form_url_error_tcp');
    }
    return null;
  }

  // ---------------------------------------------------------------------------
  // Field collector.
  // ---------------------------------------------------------------------------

  /// Assembles the backend request field map from this state's fields.
  ///
  /// The keys match the snake_case wire shape `StoreMonitorRequest` /
  /// `UpdateMonitorRequest` validate on the backend: `check_interval_sec` is
  /// converted from the interval token via [kIntervalSeconds] and
  /// `timeout_sec` is parsed straight from [_timeoutMs] (the field is
  /// labelled "Timeout (seconds)"; despite its variable name it already holds
  /// seconds, not milliseconds).
  ///
  /// The settings this form exposes no control for (`expected_status_code`,
  /// `tags`, the status-page and SSL toggles) are sent as request-shape
  /// defaults on a CREATE only. On an edit they are omitted: posting a default
  /// for a field the operator cannot see would silently reset it, which is how
  /// a plain rename used to wipe a monitor's SSL settings
  /// ([MonitorForm.isEdit]).
  ///
  /// `auth_config` follows the same rule for a sharper reason. The form can
  /// never receive the stored secret (`MonitorResource` allowlists it away), so
  /// on an edit the key travels ONLY when the operator touched the credential
  /// block: an untouched save omits it and the backend leaves the credential
  /// alone, while an explicit null (which switching the scheme to `none`
  /// produces) is the deliberate way to clear it. A create always sends the
  /// key, null included, because there is nothing stored to preserve, and that
  /// is the path a credential carried in through
  /// [MonitorForm.initialPendingAuthConfig] travels on: it was never stored, so
  /// the create request is where its secret has to land.
  Map<String, dynamic> buildFields() {
    return {
      'name': _name,
      'url': _url,
      'type': _type,
      'method': _method,
      'request_headers': _headersToMap(_headers),
      'request_body': _body,
      if (_sendsCredential) 'auth_config': _credential.toWireMap(),
      if (!widget.isEdit) 'expected_status_code': null,
      'check_interval_sec': _intervalValue == _customIntervalToken
          ? _customIntervalSec!
          : kIntervalSeconds[_intervalValue] ?? 30,
      // Deliberately NOT `?? 30`. That fallback turned an unparseable field into
      // a silent 30: the backend accepted it, answered 200, and the operator who
      // had cleared the box (or typed "60 " with a trailing space) believed they
      // had set their own value while the monitor stayed at 30 with nothing
      // anywhere saying otherwise. A null instead reaches `Required()` in
      // `MonitorController._createRules` and comes straight back to this field.
      'timeout_sec': int.tryParse(_timeoutMs.trim()),
      'regions': _regions,
      if (!widget.isEdit) 'tags': const <String>[],
      'slo_target': _slo.isEmpty ? null : double.tryParse(_slo),
      'ai_mode': _aiMode,
      'ai_auto_updates': _aiAutoUpdates,
      if (!widget.isEdit) 'show_on_status_page': true,
      if (!widget.isEdit) 'only_show_if_degraded': false,
      'follow_redirects': _followRedirects,
      'alert_on_down': _notifyDown,
      'alert_on_recover': _notifyRecover,
      // Always sent, including as an explicit null: null is the operator's way
      // to UNPIN a policy, and an omitted key on an update would leave a stale
      // pin in place. The backend validates it against the team's own policies.
      'escalation_policy_id': _policy,
      if (!widget.isEdit) 'ssl_tracking': _url.startsWith('https://'),
      if (!widget.isEdit) 'ssl_alert_threshold_days': 14,
    };
  }

  /// Converts the ordered [KeyValueRow] list into a plain map, matching the
  /// `request_headers` wire shape. A row with a blank key (a trailing empty
  /// row left by the editor) is skipped rather than sent as `"": "value"`.
  Map<String, String> _headersToMap(List<KeyValueRow> rows) {
    final Map<String, String> map = {};
    for (final KeyValueRow row in rows) {
      if (row.key.isEmpty) continue;
      map[row.key] = row.value;
    }
    return map;
  }

  // ---------------------------------------------------------------------------
  // Small helpers.
  // ---------------------------------------------------------------------------

  /// Returns the zero-based index of [value] in [options], or 0 when absent.
  int _indexOfValue(List<MetricOption> options, String value) {
    final int index = options.indexWhere((o) => o.value == value);
    return index < 0 ? 0 : index;
  }
}
