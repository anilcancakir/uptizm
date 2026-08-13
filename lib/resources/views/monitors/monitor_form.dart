import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_form_support.dart';
import 'monitor_metrics_support.dart';
import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/escalation_controller.dart';
import '../../../app/mocks/monitors.dart';
import '../../../app/models/escalation_policy.dart';
import '../../../app/support/submits_once.dart';
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

  /// Called when the user taps the primary submit button (once the client-side
  /// required checks pass), with the field map assembled by
  /// [_MonitorFormState.buildFields] (the backend request shape). The caller
  /// decides whether that fires a create or a save.
  ///
  /// Returns the backend field errors keyed by the wire field name the form
  /// posted (`name`, `url`, `regions`, `check_interval_sec`, `method`,
  /// `timeout_sec`, ...), single message per field, so a server 422 renders
  /// inline under the matching field. An empty map means success (the caller
  /// has already navigated away). Any returned key the form does not own is
  /// surfaced as a generic failure toast.
  final Future<Map<String, String>> Function(Map<String, dynamic> fields)
  onSubmit;

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
  /// Monitor name (React `name`).
  late String _name;

  /// Inline validation error for the Name field, or null when it is valid.
  ///
  /// Set on submit when the required Name is blank (a check the client can make
  /// before any round trip), and by a server 422 that rejects `name`. Cleared
  /// when the user edits the field.
  String? _nameError;

  /// Monitor type token (React `type`).
  late String _type;

  /// Monitored URL or host (React `url`).
  late String _url;

  /// Inline validation error for the target field, or null when it is valid.
  ///
  /// Set on submit by [_targetError] so a malformed target surfaces under the
  /// field immediately (an HTTP monitor needs a full URL, a TCP monitor needs
  /// `host:port`), instead of only bouncing back as a generic save-failed toast
  /// after the round trip. Cleared when the target or the type changes.
  String? _urlError;

  /// Check-interval token (React `intervalValue`).
  late String _intervalValue;

  /// Inline validation error for the check-interval field, set only by a server
  /// 422 that rejects `check_interval_sec` (the client cannot pre-check it).
  /// Cleared when the user picks another interval.
  String? _intervalError;

  /// Selected probe-region values (React `regions`).
  late List<String> _regions;

  /// Inline validation error for the Regions field, set only by a server 422
  /// that rejects `regions`. Cleared when the user changes the selection.
  String? _regionsError;

  /// Whether the advanced section is expanded (React `advanced`).
  late bool _advanced;

  /// HTTP method token for the advanced section (React `method`).
  String _method = 'get';

  /// Inline validation error for the HTTP method field, set only by a server
  /// 422 that rejects `method`. Cleared when the user picks another method.
  String? _methodError;

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

  /// Inline credential errors keyed by the wire field name (`username`,
  /// `password`, `token`, `key`, `header`, or `type`), from the client-side
  /// checks or from a server 422 on `auth_config.*`.
  Map<String, String> _credentialErrors = const <String, String>{};

  /// Request body for the advanced section (React `body`).
  String _body = '';

  /// Timeout in seconds, kept as a raw string (React `timeoutMs`).
  String _timeoutMs = '30';

  /// Inline validation error for the Timeout field, set only by a server 422
  /// that rejects `timeout_sec`. Cleared when the user edits the field.
  String? _timeoutError;

  /// Alert when the monitor goes down (React `notifyDown`).
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
        MSCard(
          variant: CardVariant.surface,
          child: WDiv(
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

        // 3. Footer: Cancel + Submit, right-aligned, auto-width.
        _buildFooter(),
      ],
    );
  }

  /// Builds the Name field.
  Widget _buildNameField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_field_name_label'),
      error: _nameError,
      child: MSInput(
        value: _name,
        onChanged: (value) => setState(() {
          _name = value;
          _nameError = null;
        }),
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
      child: MSSegmentedControl<String>(
        options: kMonitorTypes.map((o) => o.label).toList(),
        selectedIndex: _indexOfValue(kMonitorTypes, _type),
        onChanged: (index) => setState(() {
          _type = kMonitorTypes[index].value;
          // The target's valid shape depends on the type, so a pending error
          // no longer applies once the type changes.
          _urlError = null;
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
      error: _urlError,
      child: MSInput(
        value: _url,
        onChanged: (value) => setState(() {
          _url = value;
          _urlError = null;
        }),
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
      error: _intervalError,
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
              setState(() {
                _intervalValue = value;
                _intervalError = null;
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
      error: _regionsError,
      child: ListenableBuilder(
        listenable: _entitlement,
        builder: (context, _) => RegionPicker(
          regions: _regionOptions,
          value: _regions,
          onChanged: (next) => setState(() {
            _regions = next;
            _regionsError = null;
            // A deliberate pick outranks the plan-derived default, so the
            // entitlement landing later must not overwrite it.
            _regionsTouchedByUser = true;
          }),
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
      child: MSSelect<String>(
        value: _slo,
        options: kSloTargets
            .map((o) => SelectOption<String>(value: o.value, label: o.label))
            .toList(),
        onChange: (value) {
          if (value != null) setState(() => _slo = value);
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
  Widget _buildAiAutoUpdatesField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_ai_auto_updates_label'),
      hint: '${trans('uptizm.monitors.form_ai_auto_updates_hint')} '
          '${trans('uptizm.monitors.form_ai_auto_updates_hint_short')}',
      child: _buildSwitchRow(
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
        _buildSwitchRow(
          label: trans('uptizm.monitors.form_alert_down'),
          value: _notifyDown,
          onChanged: (value) => setState(() => _notifyDown = value),
        ),
        _buildSwitchRow(
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
        _buildSwitchRow(
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
          error: _methodError,
          child: MSSegmentedControl<String>(
            options: kHttpMethods.map((o) => o.label).toList(),
            selectedIndex: _indexOfValue(kHttpMethods, _method),
            onChanged: (index) => setState(() {
              _method = kHttpMethods[index].value;
              _methodError = null;
            }),
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
          errors: _credentialErrors,
          onChanged: (next) => setState(() {
            _credential = next;
            _credentialErrors = const <String, String>{};
          }),
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
        error: _timeoutError,
        child: MSInput(
          value: _timeoutMs,
          onChanged: (value) => setState(() {
            _timeoutMs = value;
            _timeoutError = null;
          }),
          type: InputType.number,
          className: 'max-w-32',
        ),
      ),
    ];
  }

  /// Builds the footer: Cancel + Submit, right-aligned.
  ///
  /// A Wind `flex flex-row justify-end gap-3` row of AUTO-width buttons. The
  /// buttons carry no `w-full` (Wind `width: double.infinity`): a full-width
  /// button inside a `flex-row` forces an unbounded width and aborts the row's
  /// layout ("RenderBox was not laid out"). Auto-width buttons fit at any width
  /// without that hazard.
  Widget _buildFooter() {
    return WDiv(
      className: 'flex flex-row justify-end gap-3',
      children: [
        MSButton(
          intent: ButtonIntent.secondary,
          onPressed: widget.onCancel,
          child: WText(trans('uptizm.monitors.form_cancel')),
        ),
        // `isLoading` is the guard, not just the spinner: WButton drops its
        // onTap while loading, so a double tap on Create cannot create two
        // monitors (each counting against the plan limit).
        MSButton(
          isLoading: isSubmitting,
          onPressed: () => submitOnce(_submitIfValid),
          child: WText(widget.submitLabel),
        ),
      ],
    );
  }

  /// Validates every client-side required field, then hands the fields to
  /// [onSubmit] and routes any server 422 back into the inline error slots.
  ///
  /// The client checks the required Name and the target shape up front so those
  /// rejections surface inline WITHOUT a round trip. Only when they pass does it
  /// await [onSubmit]; a non-empty result (a server 422) is a field-error map
  /// keyed by the posted wire field names, which [_applyServerErrors] paints
  /// under the matching fields. A returned key the form owns no slot for is a
  /// global error, surfaced as the generic save-failed toast.
  Future<void> _submitIfValid() async {
    if (!_validateClientSide()) return;

    final Map<String, String> serverErrors = await widget.onSubmit(
      buildFields(),
    );
    if (!mounted || serverErrors.isEmpty) return;

    final Map<String, String> unmapped = _applyServerErrors(serverErrors);
    if (unmapped.isNotEmpty) {
      Magic.error(
        trans('uptizm.monitors.toast_save_failed_title'),
        unmapped.values.first,
      );
    }
  }

  /// Runs every client-side required check, painting each field's inline error
  /// slot, and returns whether the form may be submitted.
  ///
  /// Currently the required Name, the target shape (via [_targetError]) and the
  /// credential block; all three are checks the client can make before any
  /// round trip. Every slot is always written (a passing check clears its slot)
  /// so a previously shown error never lingers after a corrected resubmit.
  ///
  /// The credential is only checked when the request will actually carry it: an
  /// untouched edit omits `auth_config`, and demanding a password for a
  /// credential nobody is changing would make a rename impossible.
  bool _validateClientSide() {
    final String? nameError = _name.trim().isEmpty
        ? trans('uptizm.monitors.form_name_error_required')
        : null;
    final String? targetError = _targetError();
    final Map<String, String> credentialErrors = _sendsCredential && _isHttp
        ? validateMonitorCredential(_credential)
        : const <String, String>{};

    setState(() {
      _nameError = nameError;
      _urlError = targetError;
      _credentialErrors = credentialErrors;
      // A credential error lives in the advanced section, so open it rather
      // than blocking submit with an explanation nobody can see.
      if (credentialErrors.isNotEmpty) _advanced = true;
    });

    return nameError == null && targetError == null && credentialErrors.isEmpty;
  }

  /// Routes a backend 422 field-error map (keyed by the wire field names the
  /// form posts) into the inline error slots, returning the entries that map to
  /// no known field so the caller can surface them another way.
  ///
  /// When a rejected field lives in the advanced section (`method` / `timeout`),
  /// the section is expanded so the inline error is actually visible rather than
  /// hidden behind the collapsed toggle.
  Map<String, String> _applyServerErrors(Map<String, String> errors) {
    final Map<String, String> unmapped = {};
    final Map<String, String> credentialErrors = {};
    bool expandAdvanced = false;

    setState(() {
      for (final MapEntry<String, String> entry in errors.entries) {
        switch (entry.key) {
          // Laravel reports the credential map's inner shape with dotted keys
          // (`auth_config.password`), which are exactly the field names
          // [MonitorCredentialFields] renders its error slots by, so the
          // rejection lands under the field it names instead of a toast.
          case final String key
              when key == 'auth_config' || key.startsWith('auth_config.'):
            credentialErrors[key == 'auth_config'
                ? 'type'
                : key.substring('auth_config.'.length)] = entry.value;
            expandAdvanced = true;
          case 'name':
            _nameError = entry.value;
          case 'url':
          case 'target':
            _urlError = entry.value;
          case 'regions':
            _regionsError = entry.value;
          case 'check_interval_sec':
            _intervalError = entry.value;
          case 'method':
            _methodError = entry.value;
            expandAdvanced = true;
          case 'timeout_sec':
          case 'timeout_ms':
            _timeoutError = entry.value;
            expandAdvanced = true;
          default:
            unmapped[entry.key] = entry.value;
        }
      }
      _credentialErrors = credentialErrors;
      if (expandAdvanced) _advanced = true;
    });

    return unmapped;
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
      'timeout_sec': int.tryParse(_timeoutMs) ?? 30,
      'regions': _regions,
      if (!widget.isEdit) 'tags': const <String>[],
      'slo_target': _slo.isEmpty ? null : double.tryParse(_slo),
      'ai_mode': _aiMode,
      'ai_auto_updates': _aiAutoUpdates,
      if (!widget.isEdit) 'show_on_status_page': true,
      if (!widget.isEdit) 'only_show_if_degraded': false,
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

  /// Builds a labelled switch row: the [Switch] toggle followed by its text
  /// label. The Dart [Switch] is toggle-only, so the label is rendered beside
  /// it (the React `Switch` carried an inline `label` prop).
  Widget _buildSwitchRow({
    required String label,
    required bool value,
    required ValueChanged<bool> onChanged,
  }) {
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        MSSwitch(value: value, onChanged: onChanged, semanticLabel: label),
        WText(label, className: 'min-w-0 text-sm text-fg'),
      ],
    );
  }

  /// Returns the zero-based index of [value] in [options], or 0 when absent.
  int _indexOfValue(List<MetricOption> options, String value) {
    final int index = options.indexWhere((o) => o.value == value);
    return index < 0 ? 0 : index;
  }
}
