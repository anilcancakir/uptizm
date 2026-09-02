<?php

namespace App\Http\Requests;

use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Services\Monitoring\MetricExtractor;
use App\Services\Monitoring\ThresholdEvaluator;
use App\Support\Monitoring\ProbeHeaderAllowList;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ClosureValidationRule;
use Illuminate\Validation\Concerns\ValidatesAttributes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator as ValidatorInstance;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Validation rules for the POST /monitors/{monitor}/metrics endpoint, and the
 * ONE definition of what a metric definition may contain: {@see UpdateMonitorMetricRequest}
 * inherits {@see self::metricRules()} and only asks for the partial variant.
 *
 * That single definition is what makes the cross-field rules below possible at
 * all. The update path used to re-declare every rule inline in the controller
 * with `sometimes` on each field, so there was no shared place to put a rule
 * spanning two fields, and warn/critical ordering was therefore validated only
 * on the AI discovery path while a human could save an inverted pair.
 *
 * The `key` is unique per monitor so metric values remain joinable across time.
 * Team-scoping the monitor is still the controller's answer, but it is repeated
 * here as the FIRST gate for the reason {@see self::prepareForValidation()}
 * records: rules run before the controller method does.
 */
class StoreMonitorMetricRequest extends FormRequest
{
    /**
     * The three configurable string-value lists, in no particular order: the
     * overlap rule treats them symmetrically and {@see ThresholdEvaluator::bandString()}
     * owns the severity ordering.
     *
     * @var list<string>
     */
    public const array VALUE_LIST_FIELDS = [
        'ok_values',
        'warn_values',
        'critical_values',
    ];

    /**
     * Response header names a `header` metric may never extract, whatever a
     * value on one of them might be worth diagnostically.
     *
     * These are the four names {@see ProbeHeaderAllowList} permanently
     * excludes, refused here for the same reason it refuses them: once a probe
     * runs with the operator's own credential, `set-cookie` is an authenticated
     * session token, `authorization` echoes the credential itself, and the two
     * challenge headers can carry a realm or nonce tied to that session.
     *
     * A metric is not a one-off read. Its value is extracted and persisted on
     * EVERY check into `monitor_metric_values.string_value`, a plain text
     * column, and read back from there into the anomaly prompt. Nothing
     * downstream would stop that:
     * {@see MetricExtractor::extractHeader()} is handed the RAW header set by
     * the check job, not the allowlisted map, so a metric pointed at
     * `set-cookie` records a session cookie per check for as long as it exists.
     * This rule is what keeps such a metric from being written at all.
     *
     * @var list<string>
     */
    public const array DENIED_HEADER_NAMES = [
        'set-cookie',
        'authorization',
        'www-authenticate',
        'proxy-authenticate',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->current_team_id !== null;
    }

    /**
     * Mask a cross-tenant monitor as 404 before a single rule runs.
     *
     * The controller masks it too (see MonitorMetricController::authorizeMonitor()),
     * but a FormRequest validates BEFORE the controller method is entered, so
     * that mask can no longer be the first gate: the `key` uniqueness rule would
     * probe another team's monitor and answer 422 where this API answers 404 for
     * everything it does not own. Presence-checked only; an unbound route
     * parameter or a user without a team is {@see self::authorize()}'s business.
     */
    protected function prepareForValidation(): void
    {
        $monitor = $this->route('monitor');
        $teamId = $this->user()?->current_team_id;

        if (! $monitor instanceof Monitor || $teamId === null) {
            return;
        }

        abort_unless($monitor->team_id === $teamId, HttpResponse::HTTP_NOT_FOUND);

        // The METRIC binding needs the same first-gate treatment, and for a
        // sharper reason than the monitor's. Nothing scopes `{metric}` to
        // `{monitor}`: the routes declare no `scopeBindings()` and the model
        // carries no global scope, so a foreign metric id under a monitor the
        // caller does own resolves to the foreign row. The controller's
        // `authorizeMetric()` used to catch that first; moving validation
        // ahead of it means the merged-state readers below would otherwise
        // read that row and answer 422-or-pass, which is a value-probing
        // oracle over another team's thresholds, and the overlap message
        // echoes their stored values verbatim.
        $metric = $this->route('metric');

        if ($metric instanceof MonitorMetric) {
            abort_unless($metric->monitor_id === $monitor->id, HttpResponse::HTTP_NOT_FOUND);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->metricRules(partial: false);
    }

    /**
     * Cross-field validation over MERGED state: the stored row's value wherever
     * the request omits a key, the request's value wherever it supplies one.
     *
     * Reading merged state rather than the payload is the whole point. The
     * update variant marks every field `sometimes`, so a PATCH carrying only
     * `ok_values` would otherwise never re-check the `critical_values` already
     * on the row, and the pair that collides at evaluation time is exactly the
     * pair no single payload contains. On create there is no stored row and
     * every merged read falls back to "absent", which is why the same three
     * rules run unchanged on both paths.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $routed = $this->route('metric');
            $stored = $routed instanceof MonitorMetric ? $routed : null;

            $this->validateBoundOrder($validator, $stored);
            $this->validateNoOverlappingValues($validator, $stored);
            $this->validateUnmatchedBandHasAList($validator, $stored);
        });
    }

    /**
     * Every field of a metric definition, in one place.
     *
     * `$partial` prepends `sometimes` to each field so a partial edit validates
     * only the keys it sends, while a field that is required on create stays
     * `sometimes|required` rather than becoming nullable: an edit may omit the
     * label, it may not blank it.
     *
     * Composes {@see self::metricFieldRules()} (the route-INDEPENDENT half,
     * shared with the bulk `POST /monitors` path) with this route's own `key`
     * uniqueness, which needs `$this->route('monitor')` and therefore cannot
     * live in the static method.
     *
     * @return array<string, mixed>
     */
    protected function metricRules(bool $partial): array
    {
        $optional = $partial ? ['sometimes'] : [];
        $rules = [];

        foreach (self::metricFieldRules() as $field => $fieldRules) {
            // The string-band rules already carry their own unconditional
            // `sometimes` (an omitted list leaves the stored default alone,
            // regardless of $partial), so they are taken as-is; every other
            // field gets the `sometimes` a partial edit needs.
            $rules[$field] = $fieldRules !== [] && $fieldRules[0] === 'sometimes'
                ? $fieldRules
                : [...$optional, ...$fieldRules];
        }

        $rules['key'] = [
            ...$optional,
            'required',
            'string',
            'max:40',
            'regex:/^[a-z][a-z0-9_]*$/',
            $this->uniqueKeyRule(),
        ];

        return $rules;
    }

    /**
     * The route-INDEPENDENT half of a metric definition's field rules.
     *
     * Shared by this route-bound request (via {@see self::metricRules()},
     * which composes its own `key` uniqueness on top) and by
     * {@see StoreMonitorRequest}'s bulk `metrics[]` field on `POST /monitors`,
     * which has no `{monitor}` route parameter to scope a uniqueness check
     * against. `$prefix` lets the bulk caller reach every field as
     * `metrics.*.<field>` without a second copy of the rule set; this method
     * contains no `$this` reference so a static call from another request
     * class cannot fatal.
     *
     * `key` is emitted here ONLY when a prefix is supplied: on the bulk path
     * there is no persisted monitor to scope `Rule::unique` against, so
     * `distinct` (uniqueness within the submitted array) is the strongest
     * check this method can express. With no prefix, `key` is omitted
     * entirely and {@see self::metricRules()} keeps composing its
     * route-bound {@see self::uniqueKeyRule()} exactly as before.
     *
     * @return array<string, mixed>
     */
    public static function metricFieldRules(string $prefix = ''): array
    {
        $rules = [
            "{$prefix}group_name" => [
                'nullable',
                'string',
                'max:80',
            ],
            "{$prefix}label" => [
                'required',
                'string',
                'max:120',
            ],
            "{$prefix}type" => [
                'required',
                Rule::enum(MetricType::class),
            ],
            "{$prefix}source" => [
                'nullable',
                Rule::enum(MetricSource::class),
            ],
            "{$prefix}extraction_path" => [
                'nullable',
                'string',
                'max:500',
                self::deniedHeaderNameRule(),
            ],
            "{$prefix}unit" => [
                'nullable',
                Rule::enum(MetricUnit::class),
            ],
            "{$prefix}threshold_direction" => [
                'nullable',
                Rule::enum(ThresholdDirection::class),
            ],
            "{$prefix}warn_bound" => [
                'nullable',
                'numeric',
            ],
            "{$prefix}critical_bound" => [
                'nullable',
                'numeric',
            ],
            "{$prefix}display_order" => [
                'nullable',
                'integer',
                'min:0',
            ],
            ...self::stringBandRules($prefix),
        ];

        if ($prefix !== '') {
            $rules["{$prefix}key"] = [
                'required',
                'string',
                'max:40',
                'regex:/^[a-z][a-z0-9_]*$/',
                'distinct',
            ];
        }

        return $rules;
    }

    /**
     * Refuse a credential-bearing header name as an extraction path, on a
     * `header` metric only.
     *
     * The discriminator is the SIBLING `source`, and how it is resolved is the
     * whole of this rule's correctness. It is derived from the CONCRETE
     * `$attribute` rather than read off the request, for two reasons that both
     * end in the rule silently doing nothing:
     *
     *   - `$this->input('source')` is unavailable here by design (this method
     *     is reachable statically from the bulk path, which has no bound
     *     instance of this request) and would read null on `POST /monitors`
     *     anyway, where the field is `metrics.3.source`. A denylist that
     *     no-ops on the bulk path is a denylist on the one path that did not
     *     exist before this plan.
     *   - `{$prefix}source` is `metrics.*.source` on that path, and a wildcard
     *     is not a readable key. Swapping the last segment of `$attribute`
     *     picks the right ROW as well as the right field.
     *
     * The fourth closure argument is Laravel's own:
     * {@see ClosureValidationRule::passes()} invokes the callback with the
     * validator after the failure callback, and the validator's data is the
     * only route to a sibling field from a rule that must stay static.
     */
    protected static function deniedHeaderNameRule(): Closure
    {
        return static function (
            string $attribute,
            mixed $value,
            callable $fail,
            ValidatorInstance $validator,
        ): void {
            if (! is_string($value)) {
                return;
            }

            $segments = explode('.', $attribute);
            array_pop($segments);
            $segments[] = 'source';

            $source = Arr::get($validator->getData(), implode('.', $segments));

            if (! is_string($source) || MetricSource::tryFrom($source) !== MetricSource::Header) {
                return;
            }

            if (! in_array(strtolower(trim($value)), self::DENIED_HEADER_NAMES, true)) {
                return;
            }

            $fail(
                'This response header carries credentials, so it cannot be recorded as a metric. Every '
                .'check would persist the value in cleartext.'
            );
        };
    }

    /**
     * The four string-band fields, shared by create, by update, AND by the
     * preview endpoint's own inline rule set (which validates a DRAFT rather
     * than a stored row, so it takes these field rules without the merged-state
     * hook above). Without them in the preview rule set the form's string
     * verdict would be unreachable, because `$validated` could not carry a list.
     *
     * `sometimes` in both directions: an omitted list leaves the column at its
     * `'[]'` schema default rather than being written as null.
     *
     * The closure per element is load-bearing rather than tidy. {@see ThresholdEvaluator::normalizeMatchValue()}
     * reduces an all-whitespace value to the empty string, and an empty
     * configured value would then match every extracted value that also
     * normalizes to empty, so a path resolving to `""` would band as whichever
     * list holds it. `min:1` alone does NOT close that: it counts characters, so
     * a lone U+00A0 is length 1 and passes, and Laravel's `TrimStrings` leaves
     * it there because PCRE's `\s` stays ASCII-only under `/u` without
     * `PCRE_UCP`. The check therefore asks the normalizer itself.
     * The duplicate WITHIN one list, which the cross-list overlap rule cannot
     * see, is caught by the closure on the LIST rather than by `distinct` on the
     * element. That is not a style choice. `distinct` resolves its comparison
     * set from the leading EXPLICIT path of the attribute
     * ({@see ValidatesAttributes::extractDistinctValues()}
     * takes everything before the first wildcard), so under the bulk prefix the
     * key is `metrics.*.ok_values.*` and the set is every `ok_values` element of
     * every metric. It compared ACROSS metrics. A health endpoint publishes
     * `status: ok` under each subsystem it checks, so one monitor carrying a
     * verdict metric per subsystem is the ordinary case and it was refused
     * whole, reporting `metrics.0.ok_values.0` for a list holding a single
     * value. Two subsystems reading the same healthy word is not a duplicate.
     *
     * The closure also compares through {@see ThresholdEvaluator::normalizeMatchValue()}
     * rather than folding case alone, for the reason
     * {@see self::validateNoOverlappingValues()} states about the other axis:
     * `ok` beside ` OK ` is two raw strings and one matched value, and a rule
     * that disagrees with the evaluator lets the collision through to run time.
     *
     * `$prefix` lets {@see self::metricFieldRules()} reach these as
     * `metrics.*.<field>` on the bulk create path; the default keeps every
     * existing caller (this class's own `metricRules()`, and
     * `MonitorMetricController`'s preview rule set) unchanged.
     *
     * @return array<string, mixed>
     */
    public static function stringBandRules(string $prefix = ''): array
    {
        $rules = [];

        foreach (self::VALUE_LIST_FIELDS as $field) {
            $rules["{$prefix}{$field}"] = [
                'sometimes',
                'array',
                'max:50',
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $seen = [];

                    foreach ($value as $entry) {
                        if (! is_string($entry)) {
                            continue;
                        }

                        $normalized = ThresholdEvaluator::normalizeMatchValue($entry);

                        if (isset($seen[$normalized])) {
                            $fail(
                                'A value is listed twice. Matching folds case and trims surrounding '
                                .'whitespace, so the second entry can never band anything the first '
                                .'does not already claim.'
                            );

                            return;
                        }

                        $seen[$normalized] = true;
                    }
                },
            ];
            $rules["{$prefix}{$field}.*"] = [
                'string',
                'min:1',
                'max:120',
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && ThresholdEvaluator::normalizeMatchValue($value) === '') {
                        $fail(
                            'A value cannot be blank. Matching trims surrounding whitespace, so this '
                            .'would match every empty reading.'
                        );
                    }
                },
            ];
        }

        $rules["{$prefix}unmatched_band"] = [
            'sometimes',
            'nullable',
            Rule::enum(MetricBand::class),
        ];

        return $rules;
    }

    /**
     * Uniqueness of `key` within the routed monitor, ignoring the routed metric
     * itself so an edit that re-sends its own key is not a conflict.
     */
    protected function uniqueKeyRule(): Unique
    {
        $monitor = $this->route('monitor');
        $rule = Rule::unique('monitor_metrics', 'key')
            ->where('monitor_id', $monitor instanceof Monitor ? $monitor->id : $monitor);

        $metric = $this->route('metric');
        if ($metric instanceof MonitorMetric) {
            $rule->ignore($metric->id);
        }

        return $rule;
    }

    /**
     * Warn and critical must be ordered the way the direction reads them.
     *
     * Delegated to {@see ThresholdDirection::validateBounds()} rather than
     * compared here, because the comparison INVERTS per direction: a rule that
     * only checked `warn < critical` would accept a low-is-bad metric warning
     * at 50 and paging at 90, which never reaches critical.
     */
    protected function validateBoundOrder(Validator $validator, ?MonitorMetric $stored): void
    {
        // Bounds only mean something for a numeric metric, and gating on the
        // MERGED type matters: a string metric can carry an inverted stored
        // pair, because the client sent `threshold_direction` for every type
        // before this change and nothing validated the ordering. Without this
        // gate, editing the label of such a metric fails on `critical_bound`,
        // and the form renders that error only inside its numeric block, so
        // the sheet stays open with nothing shown and the save never lands.
        if ($this->mergedType($stored) !== MetricType::Numeric) {
            return;
        }

        $direction = $this->mergedDirection($stored);
        $warn = $this->mergedBound('warn_bound', $stored);
        $critical = $this->mergedBound('critical_bound', $stored);

        if ($direction === null || $warn === null || $critical === null) {
            return;
        }

        if ($direction->validateBounds($warn, $critical)) {
            return;
        }

        // Reported on `critical_bound`: it is the bound the operator sets
        // second and the one that has to be the more extreme of the pair.
        $validator->errors()->add('critical_bound', match ($direction) {
            ThresholdDirection::HighBad => __('guards.threshold.critical_above_warning'),
            ThresholdDirection::LowBad => __('guards.threshold.critical_below_warning'),
        });
    }

    /**
     * No value may sit in two of the three lists once both sides are
     * normalized.
     *
     * Normalized, not raw: `ok_values: ['OK']` beside `warn_values: ['ok']`
     * compares as distinct raw strings and then collides at evaluation, where
     * {@see ThresholdEvaluator::bandString()} would silently resolve it to the
     * more severe band. Validating through the same normalizer the evaluator
     * uses is what keeps the two from disagreeing.
     */
    protected function validateNoOverlappingValues(Validator $validator, ?MonitorMetric $stored): void
    {
        /** @var array<string, list<array{field: string, index: int|string, raw: string, submitted: bool}>> $owners */
        $owners = [];

        foreach (self::VALUE_LIST_FIELDS as $field) {
            $submitted = $this->has($field);

            foreach ($this->mergedList($field, $stored) as $index => $raw) {
                $owners[ThresholdEvaluator::normalizeMatchValue($raw)][] = [
                    'field' => $field,
                    'index' => $index,
                    'raw' => $raw,
                    'submitted' => $submitted,
                ];
            }
        }

        $reported = [];

        foreach ($owners as $occurrences) {
            if (count(array_unique(array_column($occurrences, 'field'))) < 2) {
                continue;
            }

            foreach ($occurrences as $occurrence) {
                // Dot notation ONLY for a list the request itself carried: an
                // index into a stored list maps back to nothing on the client,
                // so that side is reported on the bare field key.
                $key = $occurrence['submitted']
                    ? $occurrence['field'].'.'.$occurrence['index']
                    : $occurrence['field'];

                if (isset($reported[$key])) {
                    continue;
                }
                $reported[$key] = true;

                $validator->errors()->add($key, sprintf(
                    '"%s" is configured in more than one band. Matching ignores case and surrounding '
                    .'whitespace, so a value may appear in one list only.',
                    $occurrence['raw'],
                ));
            }
        }
    }

    /**
     * An unmatched band needs something to be unmatched AGAINST.
     *
     * With all three lists empty it would band every sample the metric ever
     * extracts, which is a page per check interval rather than a configuration.
     */
    protected function validateUnmatchedBandHasAList(Validator $validator, ?MonitorMetric $stored): void
    {
        if ($this->mergedUnmatchedBand($stored) === null) {
            return;
        }

        foreach (self::VALUE_LIST_FIELDS as $field) {
            if ($this->mergedList($field, $stored) !== []) {
                return;
            }
        }

        $validator->errors()->add(
            'unmatched_band',
            'Add at least one healthy, warning or critical value before choosing a band for unmatched values.',
        );
    }

    /**
     * The three cross-field checks above, run for ONE `metrics.*` row on the
     * route-free bulk create path.
     *
     * {@see StoreMonitorRequest::withValidator()} calls this once per submitted
     * row, because none of {@see self::validateBoundOrder()},
     * {@see self::validateNoOverlappingValues()} and
     * {@see self::validateUnmatchedBandHasAList()} are reachable there: they are
     * instance methods reading `$this->route('metric')` and `$this->has()` off
     * THIS request, not the caller's.
     *
     * There is no stored-row merge here, unlike the route-bound checks: a
     * create has no existing metric to merge against, so every read is the
     * row's own submitted value or nothing. That is the simplification that
     * makes this cheap to keep in step with the three checks above.
     *
     * `$errorPrefix` has to reach `errors()->add()`, not only the field
     * reads: without it every row's overlap error would land on
     * `ok_values.0` regardless of which row actually collided, and the
     * per-call `$reported` dedupe below is naturally scoped to one row
     * because this method runs once per row.
     *
     * @param  array<string, mixed>  $row  The one `metrics.*` entry, keyed by
     *                                     column name.
     * @param  string  $errorPrefix  The dotted path to this row's fields on
     *                               the parent request, e.g. `metrics.1.`.
     */
    public static function validateMetricRowCrossFields(Validator $validator, array $row, string $errorPrefix): void
    {
        self::validateBulkBoundOrder($validator, $row, $errorPrefix);
        self::validateBulkNoOverlappingValues($validator, $row, $errorPrefix);
        self::validateBulkUnmatchedBandHasAList($validator, $row, $errorPrefix);
    }

    /**
     * Bulk-path analogue of {@see self::validateBoundOrder()}, reading the row
     * array instead of merged request/model state.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function validateBulkBoundOrder(Validator $validator, array $row, string $errorPrefix): void
    {
        if (self::bulkType($row) !== MetricType::Numeric) {
            return;
        }

        $direction = self::bulkDirection($row);
        $warn = self::bulkBound($row, 'warn_bound');
        $critical = self::bulkBound($row, 'critical_bound');

        if ($direction === null || $warn === null || $critical === null) {
            return;
        }

        if ($direction->validateBounds($warn, $critical)) {
            return;
        }

        $validator->errors()->add("{$errorPrefix}critical_bound", match ($direction) {
            ThresholdDirection::HighBad => __('guards.threshold.critical_above_warning'),
            ThresholdDirection::LowBad => __('guards.threshold.critical_below_warning'),
        });
    }

    /**
     * Bulk-path analogue of {@see self::validateNoOverlappingValues()}, over
     * the row's own three lists only: a bulk row has no stored counterpart to
     * merge, and the overlap this checks for is always within one metric.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function validateBulkNoOverlappingValues(Validator $validator, array $row, string $errorPrefix): void
    {
        /** @var array<string, list<array{field: string, index: int|string, raw: string}>> $owners */
        $owners = [];

        foreach (self::VALUE_LIST_FIELDS as $field) {
            foreach (self::bulkList($row, $field) as $index => $raw) {
                $owners[ThresholdEvaluator::normalizeMatchValue($raw)][] = [
                    'field' => $field,
                    'index' => $index,
                    'raw' => $raw,
                ];
            }
        }

        $reported = [];

        foreach ($owners as $occurrences) {
            if (count(array_unique(array_column($occurrences, 'field'))) < 2) {
                continue;
            }

            foreach ($occurrences as $occurrence) {
                $key = "{$errorPrefix}{$occurrence['field']}.{$occurrence['index']}";

                if (isset($reported[$key])) {
                    continue;
                }
                $reported[$key] = true;

                $validator->errors()->add($key, sprintf(
                    '"%s" is configured in more than one band. Matching ignores case and surrounding '
                    .'whitespace, so a value may appear in one list only.',
                    $occurrence['raw'],
                ));
            }
        }
    }

    /**
     * Bulk-path analogue of {@see self::validateUnmatchedBandHasAList()}.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function validateBulkUnmatchedBandHasAList(Validator $validator, array $row, string $errorPrefix): void
    {
        if (self::bulkUnmatchedBand($row) === null) {
            return;
        }

        foreach (self::VALUE_LIST_FIELDS as $field) {
            if (self::bulkList($row, $field) !== []) {
                return;
            }
        }

        $validator->errors()->add(
            "{$errorPrefix}unmatched_band",
            'Add at least one healthy, warning or critical value before choosing a band for unmatched values.',
        );
    }

    /**
     * Row-array `type`, or null when absent or not a valid case.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function bulkType(array $row): ?MetricType
    {
        $value = $row['type'] ?? null;

        return is_string($value) ? MetricType::tryFrom($value) : null;
    }

    /**
     * Row-array `threshold_direction`, or null when absent or not a valid case.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function bulkDirection(array $row): ?ThresholdDirection
    {
        $value = $row['threshold_direction'] ?? null;

        return is_string($value) ? ThresholdDirection::tryFrom($value) : null;
    }

    /**
     * Row-array `unmatched_band`, or null when absent or not a valid case.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function bulkUnmatchedBand(array $row): ?MetricBand
    {
        $value = $row['unmatched_band'] ?? null;

        return is_string($value) ? MetricBand::tryFrom($value) : null;
    }

    /**
     * Row-array numeric bound as a float, or null when absent or non-numeric.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function bulkBound(array $row, string $field): ?float
    {
        $value = $row[$field] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Row-array value list, keys preserved so an offending element keeps its
     * request index for the dot-notation error key. Non-string elements and a
     * non-array value are dropped rather than inspected, mirroring
     * {@see self::mergedList()}'s reasoning: both already failed a field
     * rule, and this hook runs anyway.
     *
     * @param  array<string, mixed>  $row
     * @return array<int|string, string>
     */
    protected static function bulkList(array $row, string $field): array
    {
        $value = $row[$field] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, static fn (mixed $item): bool => is_string($item));
    }

    /**
     * Merged `type`, or null when neither side carries a usable one.
     *
     * The cross-field rules branch on this rather than on what the request
     * happens to carry: a PATCH that only renames a metric says nothing about
     * its type, and the stored row is then the only place the answer lives.
     */
    protected function mergedType(?MonitorMetric $stored): ?MetricType
    {
        if (! $this->has('type')) {
            return $stored?->type;
        }

        $submitted = $this->input('type');

        return is_string($submitted) ? MetricType::tryFrom($submitted) : null;
    }

    /**
     * Merged `threshold_direction`, or null when neither side carries a usable
     * one. A submitted value that is not a valid case is null here and is
     * already reported by the field rule.
     */
    protected function mergedDirection(?MonitorMetric $stored): ?ThresholdDirection
    {
        if (! $this->has('threshold_direction')) {
            return $stored?->threshold_direction;
        }

        $submitted = $this->input('threshold_direction');

        return is_string($submitted) ? ThresholdDirection::tryFrom($submitted) : null;
    }

    /**
     * Merged `unmatched_band`, or null when neither side carries a usable one.
     */
    protected function mergedUnmatchedBand(?MonitorMetric $stored): ?MetricBand
    {
        if (! $this->has('unmatched_band')) {
            return $stored?->unmatched_band;
        }

        $submitted = $this->input('unmatched_band');

        return is_string($submitted) ? MetricBand::tryFrom($submitted) : null;
    }

    /**
     * Merged numeric bound as a float, or null when neither side carries one.
     */
    protected function mergedBound(string $field, ?MonitorMetric $stored): ?float
    {
        if (! $this->has($field)) {
            $value = $stored?->getAttribute($field);

            return $value === null ? null : (float) $value;
        }

        $submitted = $this->input($field);

        return is_numeric($submitted) ? (float) $submitted : null;
    }

    /**
     * Merged value list, keys preserved so an offending element keeps its
     * request index for the dot-notation error key.
     *
     * Non-string elements and a non-array payload are dropped rather than
     * inspected: both already failed a field rule, and this hook runs anyway,
     * so a scalar reaching `count()` here would answer 500 instead of 422.
     *
     * @return array<int|string, string>
     */
    protected function mergedList(string $field, ?MonitorMetric $stored): array
    {
        $source = $this->has($field) ? $this->input($field) : $stored?->getAttribute($field);

        if (! is_array($source)) {
            return [];
        }

        return array_filter($source, static fn (mixed $value): bool => is_string($value));
    }
}
