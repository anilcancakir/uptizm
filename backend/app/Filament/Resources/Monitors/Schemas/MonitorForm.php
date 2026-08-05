<?php

namespace App\Filament\Resources\Monitors\Schemas;

use App\Enums\CheckInterval;
use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Models\Monitor;
use App\Models\Team;
use App\Support\Monitoring\AssertionRuleSet;
use Closure;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The probe definition an operator may edit from the staff panel.
 *
 * THE FIELD SET IS DELIBERATELY NARROWER THAN THE TABLE
 *
 * Only the probe definition and the status-page presentation flags are here.
 * Three groups are absent on purpose:
 *
 * - The runtime state the check pipeline owns (`last_status`, `next_check_at`,
 *   `consecutive_fails`, the SSL columns). Hand-editing those desynchronises
 *   them from the check stream that produced them.
 * - `ai_mode`. `app/Jobs/SweepAiSuggestions.php:113-120` selects the fleet by
 *   `whereIn('ai_mode', ['suggest','auto'])` with NO team filter and then spends
 *   the owning team's AI budget, so an operator mis-click on a service monitor
 *   would put uptizm's own catalog onto the customer AI sweep. The service
 *   catalog pins it to `off`; this form does not offer a way to unpin it.
 * - `auth_config` as an editable value. See the read-only summary below and
 *   {@see EditMonitor}.
 *
 * VALIDATION MIRRORS THE API, NOT THE PLAN GATE
 *
 * The bounds below are `app/Http/Requests/StoreMonitorRequest.php`'s, which is
 * the authoritative rule set for these columns. The per-plan caps that request
 * also applies (`PlanGate::minCheckIntervalSec()`,
 * `maxRegionsPerMonitor()`) are NOT mirrored: a staff operator is not acting as
 * a tenant, and the system team is exempt from plan accounting anyway. The SSRF
 * host guard the API wires onto `url` is likewise absent, matching the house
 * rule against over-validating a trusted internal input; the operators who reach
 * this form are the config allowlist plus a confirmed second factor.
 */
class MonitorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ownership')
                    ->description('Which team this monitor belongs to. Fixed once created.')
                    ->schema([
                        /*
                         * `monitors.team_id` is a NOT NULL cascading FK, so the create
                         * page cannot function without it even though the step's field
                         * list is about the probe definition.
                         *
                         * HIDDEN on edit rather than disabled. A disabled field is not
                         * dehydrated, but it IS still validated
                         * (`isValidatedWhenNotDehydrated` defaults true), and
                         * `Schema::getState()` seeds its return value from the VALIDATED
                         * data before overlaying the dehydrated components
                         * (`vendor/filament/schemas/src/Concerns/HasState.php:452`), so a
                         * crafted Livewire payload could still move a monitor to another
                         * team through a control the operator sees greyed out. A hidden
                         * component is skipped by both, which is the only shape that
                         * actually holds. Re-parenting is not a supported operation
                         * anyway: `monitor_checks` carries its own `team_id`, so the
                         * history would stay behind on the old team.
                         */
                        Select::make('team_id')
                            ->label('Team')
                            ->options(fn (): array => Team::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->rules(['exists:teams,id'])
                            ->visibleOn('create'),
                        TextEntry::make('owning_team')
                            ->label('Team')
                            ->state(fn (?Monitor $record): string => $record?->team?->name ?? '-')
                            ->helperText('A monitor cannot be moved between teams; its check history is team-scoped too.')
                            ->visibleOn('edit'),
                    ]),
                Section::make('Probe definition')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(200),
                        Select::make('type')
                            ->label('Protocol')
                            ->options(fn (): array => array_combine(
                                array_column(MonitorType::cases(), 'value'),
                                array_map(
                                    fn (MonitorType $type): string => mb_strtoupper($type->value),
                                    MonitorType::cases(),
                                ),
                            ))
                            ->required(),
                        TextInput::make('url')
                            ->label('Target')
                            // The column holds two shapes depending on `type`, which is
                            // why there is no `url()` rule here: a TCP target is
                            // `host:port` and would fail one. See
                            // StoreMonitorRequest::targetRules().
                            ->helperText('HTTP monitors take a full URL. TCP monitors take host:port.')
                            ->required()
                            ->maxLength(2048),
                        Select::make('method')
                            ->options(fn (): array => array_combine(
                                array_column(HttpMethod::cases(), 'value'),
                                array_map(
                                    fn (HttpMethod $method): string => mb_strtoupper($method->value),
                                    HttpMethod::cases(),
                                ),
                            ))
                            ->helperText('Ignored by TCP monitors.')
                            ->required(),
                        /*
                         * A free integer and NOT a Select over CheckInterval, whose five
                         * presets are the client's create form, not the column's
                         * contract: the API validates 30..86400 and rows exist outside
                         * the presets. A Select would render a stored 120 as nothing
                         * selected and write the placeholder back over it on the next
                         * save. The presets are still named in the hint so the operator
                         * knows which values the customer UI can produce.
                         */
                        TextInput::make('check_interval_sec')
                            ->label('Check interval (seconds)')
                            ->helperText('Client presets: '.implode(', ', array_column(CheckInterval::cases(), 'value')).'. Any value from 30 to 86400 is accepted.')
                            ->required()
                            ->integer()
                            ->minValue(30)
                            ->maxValue(86400),
                        TextInput::make('timeout_sec')
                            ->label('Timeout (seconds)')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(120),
                        /*
                         * `expected_status_code` is NOT NULL DEFAULT 200, and Eloquent
                         * does not know about database defaults, so dehydrating a blank
                         * field writes a literal null and the insert fails. The API path
                         * hits the same wall and solves it the same way, by REMOVING the
                         * key rather than by coercing it
                         * (`StoreMonitorRequest::prepareForValidation()`): removing it
                         * lets the column default apply on create and leaves the stored
                         * value alone on edit, where "clear this" has no meaning for a
                         * NOT NULL column anyway.
                         */
                        TextInput::make('expected_status_code')
                            ->label('Expected status code')
                            ->integer()
                            ->minValue(100)
                            ->maxValue(599)
                            ->placeholder('200')
                            ->dehydrated(fn (mixed $state): bool => filled($state)),
                        Select::make('regions')
                            ->label('Probe regions')
                            ->multiple()
                            ->options(fn (): array => array_combine(
                                array_column(MonitorRegion::cases(), 'value'),
                                array_map(
                                    fn (MonitorRegion $region): string => $region->label(),
                                    MonitorRegion::cases(),
                                ),
                            ))
                            ->required(),
                        KeyValue::make('request_headers')
                            ->label('Request headers')
                            ->keyLabel('Header')
                            ->valueLabel('Value'),
                        /*
                         * `RelayClient.php:115` forwards this column verbatim, so the
                         * shape lives at the edge: the `AssertionTarget` /
                         * `AssertionOperator` unions in
                         * `backend/workers/regional-checker/src/regional-probe.ts`, whose
                         * PHP mirror is {@see AssertionRuleSet}. A Repeater over that
                         * shape is possible now and deliberately not built: the panel is
                         * a staff tool where a rule set is pasted whole, and the screen
                         * in the rule below is what makes a raw editor safe.
                         *
                         * The two state closures are the other load-bearing part. A
                         * CodeEditor's state is a STRING; handing that string to an
                         * `array`-cast column would store a JSON-encoded string that
                         * reads back as a string and breaks the spec sent to the edge. So
                         * the array is encoded on the way into the editor and decoded on
                         * the way back to the column. Pinned by the round-trip case in
                         * `tests/Feature/Admin/MonitorResourceTest.php`.
                         */
                        CodeEditor::make('assertion_rules')
                            ->label('Assertion rules (JSON)')
                            ->language(Language::Json)
                            ->helperText('A JSON array of rules, each with a target, an operator and a value (a header rule adds a name). Forwarded to the probe verbatim.')
                            ->formatStateUsing(fn (mixed $state): ?string => is_array($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : null)
                            ->dehydrateStateUsing(fn (mixed $state): ?array => blank($state)
                                ? null
                                : json_decode((string) $state, true))
                            ->rules([
                                static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $decoded = json_decode((string) $value, true);

                                    /*
                                     * A syntax error is reported apart from a shape
                                     * problem, and before it: "line 3 is missing a comma"
                                     * and "rule 3 names an unknown operator" are
                                     * different edits, and an operator handed the second
                                     * when the first is true looks in the wrong place.
                                     * `json_last_error()` rather than
                                     * JSON_THROW_ON_ERROR, so nothing here throws to
                                     * describe an input.
                                     */
                                    if (json_last_error() !== JSON_ERROR_NONE) {
                                        $fail('The :attribute is not valid JSON: '.json_last_error_msg().'.');

                                        return;
                                    }

                                    // One message per offending rule, each naming its own
                                    // index: this editor shows a JSON array, and an index
                                    // is the only thing an operator can navigate by.
                                    foreach (AssertionRuleSet::problems($decoded) as $problem) {
                                        $fail($problem);
                                    }
                                },
                            ]),
                    ]),
                Section::make('Credentials')
                    ->description('Read-only. Replace a credential from the product, not from here.')
                    ->schema([
                        /*
                         * A TextEntry and never a field: `Entry::isDehydrated()` returns
                         * false unconditionally
                         * (`vendor/filament/infolists/src/Components/Entry.php:360`), so
                         * this cannot write to the column no matter what a crafted
                         * payload puts in its state path.
                         *
                         * What it shows is the API's redaction contract, not a second
                         * one: `app/Http/Resources/MonitorResource.php:104-118` treats
                         * exactly `type`, `username` and `header` as non-secret and drops
                         * everything else, so "a credential is stored" is derived as
                         * "there is a key outside that allowlist" rather than from a
                         * hardcoded list of secret names. An auth flow that grows a new
                         * secret field therefore stays hidden by default here too.
                         */
                        TextEntry::make('auth_config_summary')
                            ->label('Authentication')
                            ->state(fn (?Monitor $record): string => static::summariseAuthConfig($record?->auth_config))
                            ->visibleOn('edit'),
                    ]),
                Section::make('Status-page presentation')
                    ->schema([
                        Toggle::make('show_on_status_page')
                            ->label('Show on status pages'),
                        Toggle::make('only_show_if_degraded')
                            ->label('Only show when degraded'),
                    ]),
            ]);
    }

    /**
     * Describe an `auth_config` without disclosing any part of the credential.
     *
     * @param  array<string, mixed>|null  $config
     */
    protected static function summariseAuthConfig(?array $config): string
    {
        if ($config === null || $config === []) {
            return 'None';
        }

        $type = is_string($config['type'] ?? null) ? $config['type'] : 'unknown';

        // The same three non-secret keys `MonitorResource::redactAuthConfig()`
        // allows through. Anything else is treated as credential material.
        $descriptors = array_intersect_key($config, array_flip([
            'username',
            'header',
        ]));

        $hasCredential = array_diff_key($config, array_flip([
            'type',
            'username',
            'header',
        ])) !== [];

        $summary = $type.' ('.($hasCredential ? 'credential stored' : 'no credential stored').')';

        foreach ($descriptors as $key => $value) {
            $summary .= sprintf(', %s: %s', $key, (string) $value);
        }

        return $summary;
    }
}
