<?php

namespace Tests\Feature\Admin;

use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Monitor;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Feature\Services\ServiceCatalogTest;
use Tests\TestCase;

/**
 * The Services Filament resource: the full List+Create+Edit shape over
 * {@see Service}.
 *
 * Two behaviours here are load-bearing rather than cosmetic, per this
 * step's own docblocks in {@see ServiceForm}
 * and {@see ServiceResource::assertPublishable()}.
 *
 * WHY THE PUBLISH GUARD IS TESTED VIA REFLECTION, NOT `fillForm()`
 *
 * Filament's own `disabled()` implementation (see
 * `vendor/filament/schemas/src/Components/Concerns/CanBeDisabled.php`'s
 * docblock) already excludes a genuinely disabled field's value from
 * dehydration, which means a `fillForm(['is_published' => true, ...])` test
 * against a form whose toggle is still disabled would never even reach
 * `$data['is_published']`: Filament's own protection would absorb it, and
 * the test would pass whether or not `ServiceResource::assertPublishable()`
 * existed at all. That would prove Filament's dehydration works, not that
 * THIS resource's own server-side guard does. `Service::canPublish()` also
 * cannot stand in for it here for the Create path in particular: relationships
 * are attached AFTER the model row is created (see
 * `vendor/filament/filament/src/Resources/Pages/CreateRecord.php:119-121`),
 * so no attached-monitor state exists yet to query.
 *
 * So these two tests invoke the resource's OWN
 * `mutateFormDataBeforeCreate()` / `mutateFormDataBeforeSave()` hook
 * directly via reflection, with a data array crafted by hand rather than
 * produced by the (disabled) form, exactly matching the plan's own wording:
 * "even when the request is crafted directly rather than through the
 * disabled control". This is the only way to prove the SERVER refuses the
 * write independently of whatever the client rendered.
 */
class ServiceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected const STAFF_EMAIL = 'ops@uptizm.com';

    public function test_the_create_form_renders_for_an_allowlisted_staff_user(): void
    {
        $this->actingAs($this->staffUser());

        Livewire::test(CreateService::class)->assertSuccessful();
    }

    public function test_the_list_page_renders_for_an_allowlisted_staff_user(): void
    {
        $this->actingAs($this->staffUser());

        Livewire::test(ListServices::class)->assertSuccessful();
    }

    public function test_the_status_source_select_offers_exactly_the_four_enum_cases(): void
    {
        $this->actingAs($this->staffUser());

        Livewire::test(CreateService::class)
            ->assertFormFieldExists(
                'status_source',
                fn (Select $field): bool => count($field->getOptions()) === 4
                    && array_keys($field->getOptions()) === array_map(
                        fn (ServiceStatusSource $case): string => $case->value,
                        ServiceStatusSource::cases(),
                    ),
            );
    }

    public function test_creating_a_service_with_a_loopback_status_source_url_fails_validation(): void
    {
        $this->actingAs($this->staffUser());

        Livewire::test(CreateService::class)
            ->fillForm([
                'slug' => 'loopback-service',
                'name' => 'Loopback Service',
                'category' => 'developer-tools',
                'status_source' => ServiceStatusSource::StatuspageV2->value,
                'status_source_url' => 'https://127.0.0.1/status',
                'display_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['status_source_url']);

        $this->assertDatabaseMissing('services', ['slug' => 'loopback-service']);
    }

    public function test_creating_with_is_published_true_and_unreviewed_terms_is_refused_server_side_even_when_crafted_directly(): void
    {
        // A monitor IS attached, so the ONLY unmet precondition is the terms
        // review. That isolation is the whole point: an earlier version of this
        // test left `$page->data` empty, which failed both preconditions at once,
        // and neutering the terms check in `assertPublishable()` still left it
        // green because the monitor branch threw instead. It asserted a refusal
        // without asserting WHICH refusal, so the criterion it exists to pin
        // (publishing with unreviewed terms is refused) was never covered.
        $this->assertRefusesPublish(new CreateService, 'mutateFormDataBeforeCreate', [
            'is_published' => true,
            'terms_reviewed_at' => null,
        ], withMonitor: true);
    }

    public function test_creating_with_is_published_true_and_reviewed_terms_but_no_monitor_is_still_refused(): void
    {
        // The mirror of the case above: terms reviewed, no monitor, so only the
        // monitor branch can refuse.
        $this->assertRefusesPublish(new CreateService, 'mutateFormDataBeforeCreate', [
            'is_published' => true,
            'terms_reviewed_at' => now(),
        ]);
    }

    public function test_editing_with_is_published_true_and_unreviewed_terms_is_refused_server_side_even_when_crafted_directly(): void
    {
        $this->assertRefusesPublish(new EditService, 'mutateFormDataBeforeSave', [
            'is_published' => true,
            'terms_reviewed_at' => null,
        ], withMonitor: true);
    }

    public function test_a_service_satisfying_both_publish_preconditions_is_not_refused(): void
    {
        $page = new CreateService;
        $page->data = [
            'monitors' => [
                $this->makeMonitor($this->makeTeam())->id,
            ],
        ];

        $method = new ReflectionMethod($page, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);

        $data = $method->invoke($page, [
            'is_published' => true,
            'terms_reviewed_at' => now(),
        ]);

        $this->assertTrue($data['is_published']);
    }

    /**
     * Invoke the given page's publish-guarding hook with hand-crafted data and
     * assert it refuses rather than silently persisting.
     *
     * `$withMonitor` decides which precondition is under test. Leave it false to
     * exercise the missing-monitor branch; set it true to attach one, so the
     * terms review is the only thing left unmet and the assertion can only be
     * satisfied by the terms branch. Without that control a caller asserting
     * "unreviewed terms are refused" is really only asserting "something
     * refused", and the terms check could be deleted with the suite still green.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertRefusesPublish(
        object $page,
        string $hookMethod,
        array $data,
        bool $withMonitor = false,
    ): void {
        if ($withMonitor) {
            $page->data = [
                'monitors' => [
                    $this->makeMonitor($this->makeTeam())->id,
                ],
            ];
        }

        $method = new ReflectionMethod($page, $hookMethod);
        $method->setAccessible(true);

        $this->expectException(ValidationException::class);

        $method->invoke($page, $data);
    }

    /**
     * A user satisfying every branch of the staff gate ({@see StaffGateTest}),
     * so `Livewire::test()` exercises the resource the way a real allowlisted
     * operator would.
     */
    protected function staffUser(): User
    {
        config()->set('uptizm.staff_emails', [self::STAFF_EMAIL]);

        $user = User::factory()->create([
            'email' => self::STAFF_EMAIL,
            'email_verified_at' => now(),
        ]);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user;
    }

    /**
     * Build a persisted team owned by a fresh factory user, mirroring
     * {@see ServiceCatalogTest}'s construction (neither
     * {@see Team} nor {@see Monitor} exposes a working `factory()`).
     */
    protected function makeTeam(): Team
    {
        return Team::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
    }

    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Health',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);
    }

    public function test_the_brand_colour_only_accepts_a_full_hex_literal(): void
    {
        /*
         * This value is interpolated into an inline `style="background-color: ..."` on a
         * PUBLIC page, which makes the field the one place operator input reaches CSS
         * unescaped by design. So the rule is a strict 7-character hex and the test
         * covers the shapes somebody would actually try: a shorthand, a CSS keyword, a
         * function, and a breakout attempt.
         *
         * Shorthand is refused rather than expanded so the stored string and the
         * rendered string are never two different things.
         */
        $staff = $this->staffUser();

        foreach (['#fff', 'red', 'rgb(1,2,3)', '#181717; background-image: url(https://evil.test/x)', '#12345g'] as $rejected) {
            Livewire::actingAs($staff)
                ->test(CreateService::class)
                ->fillForm([
                    'slug' => 'colour-'.md5($rejected),
                    'name' => 'Colour Probe',
                    'category' => 'cloud',
                    'status_source' => ServiceStatusSource::None->value,
                    'display_order' => 0,
                    'brand_color' => $rejected,
                ])
                ->call('create')
                ->assertHasFormErrors(['brand_color']);
        }

        // Control: a real one is accepted, so the rule is not simply refusing everything.
        Livewire::actingAs($staff)
            ->test(CreateService::class)
            ->fillForm([
                'slug' => 'colour-accepted',
                'name' => 'Colour Probe',
                'category' => 'cloud',
                'status_source' => ServiceStatusSource::None->value,
                'display_order' => 0,
                'brand_color' => '#181717',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('#181717', Service::query()->where('slug', 'colour-accepted')->value('brand_color'));
    }
}
