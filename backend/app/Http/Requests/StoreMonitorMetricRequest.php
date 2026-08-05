<?php

namespace App\Http\Requests;

use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
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
     * @return array<string, mixed>
     */
    protected function metricRules(bool $partial): array
    {
        $optional = $partial ? ['sometimes'] : [];

        return [
            'group_name' => [
                ...$optional,
                'nullable',
                'string',
                'max:80',
            ],
            'label' => [
                ...$optional,
                'required',
                'string',
                'max:120',
            ],
            'key' => [
                ...$optional,
                'required',
                'string',
                'max:40',
                'regex:/^[a-z][a-z0-9_]*$/',
                $this->uniqueKeyRule(),
            ],
            'type' => [
                ...$optional,
                'required',
                Rule::enum(MetricType::class),
            ],
            'source' => [
                ...$optional,
                'nullable',
                Rule::enum(MetricSource::class),
            ],
            'extraction_path' => [
                ...$optional,
                'nullable',
                'string',
                'max:500',
            ],
            'unit' => [
                ...$optional,
                'nullable',
                Rule::enum(MetricUnit::class),
            ],
            'threshold_direction' => [
                ...$optional,
                'nullable',
                Rule::enum(ThresholdDirection::class),
            ],
            'warn_bound' => [
                ...$optional,
                'nullable',
                'numeric',
            ],
            'critical_bound' => [
                ...$optional,
                'nullable',
                'numeric',
            ],
            'display_order' => [
                ...$optional,
                'nullable',
                'integer',
                'min:0',
            ],
            ...self::stringBandRules(),
        ];
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
     * `min:1` per element is load-bearing rather than tidy. {@see ThresholdEvaluator::normalizeMatchValue()}
     * reduces an all-whitespace value to the empty string, and an empty
     * configured value would then match every extracted value that also
     * normalizes to empty. `distinct:ignore_case` catches the duplicate WITHIN
     * one list, which the cross-list overlap rule cannot see.
     *
     * @return array<string, mixed>
     */
    public static function stringBandRules(): array
    {
        $rules = [];

        foreach (self::VALUE_LIST_FIELDS as $field) {
            $rules[$field] = [
                'sometimes',
                'array',
                'max:50',
            ];
            $rules[$field.'.*'] = [
                'string',
                'min:1',
                'max:120',
                'distinct:ignore_case',
            ];
        }

        $rules['unmatched_band'] = [
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
            ThresholdDirection::HighBad => 'Critical must be above the warning bound when higher values are worse.',
            ThresholdDirection::LowBad => 'Critical must be below the warning bound when lower values are worse.',
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
