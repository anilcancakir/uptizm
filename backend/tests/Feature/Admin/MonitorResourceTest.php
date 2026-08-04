<?php

namespace Tests\Feature\Admin;

use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Filament\Resources\Monitors\MonitorResource;
use App\Filament\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Filament\Resources\Monitors\Pages\ListMonitors;
use App\Jobs\PerformMonitorCheck;
use App\Jobs\ScheduleMonitorChecks;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Policies\MonitorPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff panel's Monitors resource, and the two casts nobody had verified
 * through a Filament form.
 *
 * WHY THIS FILE IS MOSTLY ABOUT `auth_config`
 *
 * `monitors.auth_config` is `encrypted:array` over a `text` column and holds a
 * live customer credential. The failure mode a form introduces is silent in both
 * directions: the panel can OVERWRITE the credential (a null, an empty array, a
 * double-encrypted blob) on a save that never touched the field, and it can
 * DISCLOSE the credential simply by loading the page, because Filament fills its
 * form from `attributesToArray()` and that applies the decrypting cast. Neither
 * behaviour is documented by Filament, so each is asserted here rather than
 * reasoned about, and each assertion carries a control that fails if the probe
 * itself has stopped working.
 *
 * THE CONTROLS, AND WHY EVERY ABSENCE HAS ONE
 *
 * Most claims below are absences: the ciphertext did not change, the secret is
 * not in the page, no component owns that state path. An absence asserted alone
 * proves nothing about the machinery that looked for it, so each is paired with
 * something that MUST be present: the rename that proves the save really ran,
 * the redacted summary that proves the page rendered, the `data.name` state path
 * that proves the schema walk found components at all.
 */
class MonitorResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The credential value that must never appear anywhere: not in the form
     * state, not in the rendered page, not in a rewritten column.
     */
    private const SECRET = 'bearer-token-3f9a2c-never-render-me';

    protected const STAFF_EMAIL = 'ops@uptizm.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('uptizm.staff_emails', [self::STAFF_EMAIL]);

        // Resolved by id and set explicitly: `Filament::getCurrentPanel()` is
        // populated by the panel's own middleware in a real request, and a
        // Livewire test never runs it.
        Filament::setCurrentPanel(User::STAFF_PANEL_ID);

        $this->actingAs($this->staffUser());
    }

    public function test_the_three_monitor_pages_are_registered_on_the_panel_host(): void
    {
        foreach (['index', 'create', 'edit'] as $page) {
            $name = "filament.admin.resources.monitors.{$page}";

            $this->assertTrue(Route::has($name), "Missing panel route [{$name}].");
            $this->assertSame(
                config('uptizm.admin_host'),
                Route::getRoutes()->getByName($name)->getDomain(),
                "Panel route [{$name}] is not constrained to the admin host.",
            );
        }
    }

    public function test_the_list_page_shows_monitors_from_every_team(): void
    {
        $mine = $this->makeMonitor($this->makeTeam('Team Alpha'), ['name' => 'Alpha API']);
        $theirs = $this->makeMonitor($this->makeTeam('Team Bravo'), ['name' => 'Bravo API']);

        Livewire::test(ListMonitors::class)
            ->assertCanSeeTableRecords([
                $mine,
                $theirs,
            ])
            // The team control narrows the view and is not a boundary: it is the
            // operator's choice, applied on top of a query that saw both rows.
            ->filterTable('team_id', $mine->team_id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_resource_permits_a_cross_team_edit_that_the_model_policy_refuses(): void
    {
        /*
         * `App\Policies\MonitorPolicy` says in its own docblock that it is "not
         * registered in a policy map", which is true of a manual map and
         * irrelevant to Laravel's convention discovery: it IS the policy for this
         * model, and its `update()` compares `current_team_id` to the monitor's
         * team because it was written for the customer API. Filament consults any
         * policy method that exists, so without the resource's explicit
         * exemption every edit here would 403.
         *
         * The Gate assertion is the control. If the policy is ever widened, the
         * `canEdit()` assertion below would start passing for the wrong reason,
         * and this line is what fails instead of going quiet.
         */
        $foreign = $this->makeMonitor($this->makeTeam('Someone Else'));
        $staff = User::query()->where('email', self::STAFF_EMAIL)->sole();

        $this->assertInstanceOf(MonitorPolicy::class, Gate::getPolicyFor(Monitor::class));
        $this->assertNotSame($staff->current_team_id, $foreign->team_id);
        $this->assertFalse(Gate::forUser($staff)->allows('update', $foreign));

        $this->assertTrue(MonitorResource::canEdit($foreign));

        $this->get(MonitorResource::getUrl('edit', ['record' => $foreign]))->assertOk();
    }

    public function test_a_stored_credential_survives_a_save_that_never_touched_it(): void
    {
        $monitor = $this->makeMonitor($this->makeTeam('Credentialed'), [
            'auth_config' => [
                'type' => 'bearer',
                'token' => self::SECRET,
            ],
        ]);

        // Control on the fixture itself: everything below compares CIPHERTEXT, so
        // a column that happened to hold plaintext JSON would make the
        // comparison meaningless.
        $rawBefore = $this->rawColumn($monitor, 'auth_config');
        $this->assertStringNotContainsString(self::SECRET, $rawBefore);
        $this->assertSame(
            ['type' => 'bearer', 'token' => self::SECRET],
            json_decode(Crypt::decryptString($rawBefore), true),
        );

        Livewire::test(EditMonitor::class, ['record' => $monitor->getKey()])
            ->fillForm(['name' => 'Renamed by staff'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $monitor->fresh();

        // Control: the save actually wrote something. Without this, a save that
        // silently aborted would satisfy every assertion that follows.
        $this->assertSame('Renamed by staff', $fresh->name);

        // The three failure modes the step exists to rule out, distinguished:
        // a null write, an empty-array write, and a double-encrypt. Comparing the
        // DECRYPTED array covers the first two; comparing the raw column
        // byte-for-byte covers the third and proves the attribute was never
        // written at all.
        $this->assertSame(['type' => 'bearer', 'token' => self::SECRET], $fresh->auth_config);
        $this->assertSame($rawBefore, $this->rawColumn($fresh, 'auth_config'));
    }

    public function test_an_untouched_jsonb_column_survives_a_round_trip_as_an_array(): void
    {
        /*
         * The load-then-save path runs every JSONB column through a Filament
         * state cast (KeyValue rows, a JSON code editor string, a multi-select
         * list) and back. The failure to rule out is a value returning as a
         * JSON-ENCODED STRING, which passes any "not empty" check and then
         * reaches the edge worker as a string where it expects a structure.
         * `tags` is in here deliberately: it is on NO form component, so it also
         * covers the "column the form never mentions" case.
         */
        $headers = ['X-Trace' => 'on'];
        $assertions = [
            [
                'field' => 'body',
                'operator' => 'contains',
                'value' => 'ok',
            ],
        ];
        $tags = ['prod', 'edge'];

        $monitor = $this->makeMonitor($this->makeTeam('Jsonb'), [
            'request_headers' => $headers,
            'assertion_rules' => $assertions,
            'regions' => [MonitorRegion::USEast->value],
            'tags' => $tags,
        ]);

        Livewire::test(EditMonitor::class, ['record' => $monitor->getKey()])
            ->fillForm(['name' => 'Untouched jsonb'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $monitor->fresh();

        $this->assertSame('Untouched jsonb', $fresh->name);
        // assertEquals, not assertSame: PostgreSQL's jsonb NORMALISES a JSON object and
        // does not preserve key order, while SQLite stores the text verbatim. PHP's ===
        // on arrays requires the same order and == does not, so assertSame here pins a
        // detail that is not a contract and reddens only on the engine production runs.
        $this->assertEquals($headers, $fresh->request_headers);
        $this->assertEquals($assertions, $fresh->assertion_rules);
        $this->assertSame([MonitorRegion::USEast->value], $fresh->regions);
        $this->assertSame($tags, $fresh->tags);

        $this->assertStoredAsJsonArray($fresh, [
            'request_headers',
            'assertion_rules',
            'regions',
            'tags',
        ]);
    }

    public function test_new_jsonb_values_entered_in_the_panel_persist_as_arrays(): void
    {
        $monitor = $this->makeMonitor($this->makeTeam('Jsonb Writes'), [
            'request_headers' => [],
            'assertion_rules' => null,
            'regions' => [MonitorRegion::USEast->value],
        ]);

        Livewire::test(EditMonitor::class, ['record' => $monitor->getKey()])
            ->fillForm([
                'request_headers' => [
                    'X-Api-Version' => '2',
                    'Accept' => 'application/json',
                ],
                // A CodeEditor's state is a raw string; the form's dehydrate
                // closure is what turns it back into an array.
                'assertion_rules' => json_encode([
                    [
                        'field' => 'status_code',
                        'operator' => 'eq',
                        'value' => 204,
                    ],
                ]),
                'regions' => [
                    MonitorRegion::EUWest->value,
                    MonitorRegion::AP->value,
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $monitor->fresh();

        // assertEquals, not assertSame: PostgreSQL's jsonb NORMALISES a JSON object and
        // does not preserve key order, while SQLite stores the text verbatim. PHP's ===
        // on arrays requires the same order and == does not, so assertSame here pins a
        // detail that is not a contract and reddens only on the engine production runs.
        $this->assertEquals(['X-Api-Version' => '2', 'Accept' => 'application/json'], $fresh->request_headers);
        $this->assertEquals(
            [['field' => 'status_code', 'operator' => 'eq', 'value' => 204]],
            $fresh->assertion_rules,
        );
        $this->assertSame([MonitorRegion::EUWest->value, MonitorRegion::AP->value], $fresh->regions);

        $this->assertStoredAsJsonArray($fresh, [
            'request_headers',
            'assertion_rules',
            'regions',
        ]);
    }

    public function test_assertion_rules_that_do_not_decode_to_an_array_are_refused(): void
    {
        /*
         * Both halves matter. Unparseable JSON is the obvious case; a bare `5` is
         * the quiet one, because it is VALID JSON, would decode to an int, and an
         * `array`-cast column would then store `5` and read it back as an int
         * that `RelayClient` forwards to the edge as a scalar. Neither may reach
         * the column, and the original value must still be there afterwards.
         */
        $original = [['field' => 'body', 'operator' => 'contains', 'value' => 'ok']];

        $monitor = $this->makeMonitor($this->makeTeam('Bad Json'), [
            'assertion_rules' => $original,
        ]);

        foreach (['{not json', '5'] as $payload) {
            Livewire::test(EditMonitor::class, ['record' => $monitor->getKey()])
                ->fillForm(['assertion_rules' => $payload])
                ->call('save')
                ->assertHasFormErrors(['assertion_rules']);

            $this->assertEquals($original, $monitor->fresh()->assertion_rules);
        }
    }

    public function test_the_decrypted_credential_never_reaches_the_form_state_or_the_rendered_page(): void
    {
        $monitor = $this->makeMonitor($this->makeTeam('Redaction'), [
            'auth_config' => [
                'type' => 'bearer',
                'token' => self::SECRET,
            ],
        ]);

        $component = Livewire::test(EditMonitor::class, ['record' => $monitor->getKey()]);

        // Control: the page rendered AND the redacted summary works, so the
        // absence below is not the absence of a page.
        $component->assertSee('bearer (credential stored)');
        $component->assertDontSee(self::SECRET);
        $this->assertArrayNotHasKey('auth_config', $component->get('data'));

        // The HTTP path is the one that matters: a public Livewire property is
        // serialised into the page's snapshot, so this is what a browser (and
        // anything reading the response) would receive.
        $this->get(MonitorResource::getUrl('edit', ['record' => $monitor]))
            ->assertOk()
            ->assertSee('bearer (credential stored)')
            ->assertDontSee(self::SECRET);
    }

    public function test_no_form_component_claims_the_auth_config_state_path(): void
    {
        /*
         * The guard for the next contributor. Giving `auth_config` a real field
         * re-opens both holes at once (the plaintext returns to the form state,
         * and a partial edit can overwrite the stored credential), and neither
         * shows up as a broken test elsewhere. `data.name` is the control: it
         * proves the schema walk found components rather than an empty list.
         */
        $monitor = $this->makeMonitor($this->makeTeam('Schema Walk'));

        $schema = Livewire::test(EditMonitor::class, ['record' => $monitor->getKey()])
            ->instance()
            ->getSchema('form');

        $statePaths = array_map(
            fn (object $component): ?string => method_exists($component, 'getStatePath')
                ? $component->getStatePath()
                : null,
            $schema->getFlatComponents(withActions: false, withHidden: true),
        );

        $this->assertContains('data.name', $statePaths);
        $this->assertNotContains('data.auth_config', $statePaths);
    }

    public function test_editing_the_check_interval_persists_and_governs_the_next_scheduler_pass(): void
    {
        $this->freezeSecond();
        Queue::fake();

        $monitor = $this->makeMonitor($this->makeTeam('Cadence'), [
            'check_interval_sec' => 60,
            'regions' => [MonitorRegion::USEast->value],
            'next_check_at' => now()->addHour(),
        ]);

        // Control: a monitor whose clock has not elapsed is NOT due, so the
        // positive case below is the scope answering rather than returning
        // everything.
        $this->assertFalse(Monitor::query()->due()->whereKey($monitor->getKey())->exists());

        // A value the client's CheckInterval presets do not contain, on purpose:
        // the panel must accept it and must not round it to a preset.
        Livewire::test(EditMonitor::class, ['record' => $monitor->getKey()])
            ->fillForm(['check_interval_sec' => 120])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(120, $monitor->fresh()->check_interval_sec);

        $monitor->fresh()->forceFill(['next_check_at' => now()->subMinute()])->save();
        $this->assertTrue(Monitor::query()->due()->whereKey($monitor->getKey())->exists());

        (new ScheduleMonitorChecks)->handle();

        // The panel-written cadence is what the scheduler advanced the clock by.
        $this->assertTrue(now()->addSeconds(120)->equalTo($monitor->fresh()->next_check_at));
        Queue::assertPushed(
            PerformMonitorCheck::class,
            fn (PerformMonitorCheck $job): bool => $job->monitor->is($monitor),
        );
    }

    public function test_the_create_page_arms_the_new_monitor_for_the_scheduler(): void
    {
        $this->freezeSecond();

        $team = $this->makeTeam('Fresh');

        Livewire::test(CreateMonitor::class)
            ->fillForm([
                'team_id' => $team->getKey(),
                'name' => 'Created from the panel',
                'type' => MonitorType::Http->value,
                'url' => 'https://example.com/health',
                'method' => 'get',
                'check_interval_sec' => 300,
                'timeout_sec' => 30,
                'regions' => [MonitorRegion::EUWest->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $monitor = Monitor::query()->sole();

        $this->assertSame($team->getKey(), $monitor->team_id);
        $this->assertSame(300, $monitor->check_interval_sec);
        // A monitor with no `next_check_at` is never picked up and never explains
        // why; see CreateMonitor's docblock.
        $this->assertNotNull($monitor->next_check_at);
        $this->assertTrue(Monitor::query()->due()->whereKey($monitor->getKey())->exists());
        // Nothing invented a credential on the way in.
        $this->assertNull($monitor->auth_config);
    }

    /**
     * Read a column straight from the database, bypassing every cast.
     */
    protected function rawColumn(Monitor $monitor, string $column): string
    {
        return (string) DB::table('monitors')
            ->where('id', $monitor->getKey())
            ->value($column);
    }

    /**
     * Assert each column holds a JSON ARRAY at rest rather than a JSON-encoded
     * string, which is the corruption a Filament state cast can introduce
     * without any read-side symptom until the edge worker receives the spec.
     *
     * @param  list<string>  $columns
     */
    protected function assertStoredAsJsonArray(Monitor $monitor, array $columns): void
    {
        foreach ($columns as $column) {
            $raw = $this->rawColumn($monitor, $column);

            $this->assertIsArray(
                json_decode($raw, true),
                "`{$column}` does not hold a JSON array at rest: {$raw}",
            );
            $this->assertStringStartsNotWith(
                '"',
                $raw,
                "`{$column}` was stored as a JSON-encoded string: {$raw}",
            );
        }
    }

    /**
     * A staff user who satisfies the panel gate, owning a team of their own so
     * the cross-team case has something to differ from.
     */
    protected function staffUser(): User
    {
        $user = User::factory()->create([
            'email' => self::STAFF_EMAIL,
            'email_verified_at' => now(),
        ]);

        $team = $this->makeTeam('Staff Own Team', $user);

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'current_team_id' => $team->getKey(),
        ])->save();

        return $user;
    }

    /**
     * A team with its own owner, since `teams.user_id` is a NOT NULL FK.
     */
    protected function makeTeam(string $name, ?User $owner = null): Team
    {
        $owner ??= User::query()->create([
            'name' => $name.' Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $owner->getKey(),
            'name' => $name,
        ]);
    }

    /**
     * A monitor in the shape the API creates them.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(Team $team, array $overrides = []): Monitor
    {
        return Monitor::query()->create(array_merge([
            'team_id' => $team->getKey(),
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'regions' => [MonitorRegion::USEast->value],
            'last_status' => MonitorStatus::Up,
            'next_check_at' => now(),
        ], $overrides));
    }
}
