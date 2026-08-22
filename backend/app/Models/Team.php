<?php

namespace App\Models;

use App\Enums\Plan;
use App\Services\Billing\PlanGate;
use App\Support\Services\SystemTeam;
use FlutterSdk\MagicStarter\Models\Team as MagicStarterTeam;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Billable;

class Team extends MagicStarterTeam implements HasLocalePreference
{
    use Billable;
    use HasFactory;

    /**
     * The language this team reads in.
     *
     * Its OWNER's language, and there is deliberately no `teams.locale` column:
     * the owner already chose one in the settings screen, and inventing a second
     * place to set the same thing earns a migration, a form field, and a way for
     * the two to disagree. When somebody actually asks for a per-team override,
     * that is the moment to add the column.
     *
     * It exists because some AI text has no request behind it to read a language
     * from. An autonomous status update is written by a queued job at 3am and an
     * incident analysis is composed on the queue as well, so
     * `SetApiLocale` cannot reach either: there is no
     * caller. The team is the nearest thing to an audience those two have.
     *
     * `?:` rather than `??` on the owner's value, matching the middleware:
     * `users.locale` is NOT NULL with an `'en'` default, so null never arrives
     * and an empty string is what does. `??` would pass that empty string on as
     * a language, and `PromptLanguage` would then be asked
     * to name nothing.
     */
    public function preferredLocale(): string
    {
        $owner = $this->owner?->locale;

        return (is_string($owner) ? $owner : '') ?: (string) config('app.locale');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'personal_team',
        'profile_photo_path',
        'plan',
        'plan_status',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'plan_provider',
        'plan_source_event_at',
        'plan_provider_status',
        'plan_product_id',
        'plan_current_period_end',
        'plan_renews',
        'plan_grace_period_ends_at',
        'plan_manage_url',
    ];

    /**
     * The attributes that should be cast.
     *
     * `is_system` marks the one internal team that owns the public service
     * catalog's monitors ({@see SystemTeam}). It is cast here and deliberately
     * ABSENT from {@see self::$fillable}: it buys unlimited plan caps in
     * {@see PlanGate::limits()}, so a mass-assigned `is_system` on any
     * team-create path would be a free upgrade. The resolver writes it with
     * `forceFill()` and is the only writer.
     *
     * The four `plan_*` provenance casts are the entitlement's ordering and
     * period discipline. `plan_source_event_at` is the source event's own
     * timestamp and is compared against an incoming event's, so it has to
     * arrive as a Carbon rather than a string; the same holds for the two
     * period boundaries. `plan_renews` is cast to `boolean` and left NULLABLE
     * on the column, because null means the rail has not told us whether this
     * subscription rolls over, which is a different claim from false.
     *
     * `plan_provider` and `plan_provider_status` are deliberately UNCAST. The
     * provider's neutral vocabulary lives in a PHP enum rather than in the
     * column, and the status column exists precisely to keep a rail's own word
     * verbatim, including words no neutral vocabulary has. Casting either one
     * would turn an unrecognised value into an exception on READ, at which
     * point a support column becomes an outage.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'plan' => Plan::class,
        'trial_ends_at' => 'datetime',
        'is_system' => 'boolean',
        'plan_source_event_at' => 'datetime',
        'plan_current_period_end' => 'datetime',
        'plan_grace_period_ends_at' => 'datetime',
        'plan_renews' => 'boolean',
    ];

    /**
     * Deletes the filesystem state the team's rows own but cannot clean up
     * themselves.
     *
     * `status_pages.team_id` is `cascadeOnDelete()`, so deleting a team removes
     * its status pages through a DATABASE cascade. A database cascade raises no
     * Eloquent event, so {@see StatusPage}'s own `deleted` hook never fires and
     * every rendered preview PNG would be orphaned on the private disk: the row
     * that named the file is gone, so nothing can ever find it again.
     *
     * Cleaning the files here rather than deleting the pages one by one keeps
     * the cascade doing the row work it already does correctly, and avoids a
     * second delete pass over what can be a large set.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $team): void {
            $paths = StatusPage::query()
                ->where('team_id', $team->getKey())
                ->whereNotNull('preview_image_path')
                ->pluck('preview_image_path');

            foreach ($paths as $path) {
                Storage::disk(StatusPage::PREVIEW_DISK)->delete($path);
            }
        });
    }

    /**
     * The single source-of-truth read for the team's billing entitlement.
     *
     * Backed by the `teams.plan` column; Cashier's `subscribed()` is one
     * feeder of that column, never the entitlement truth itself.
     */
    public function entitledPlan(): Plan
    {
        return $this->plan ?? Plan::Free;
    }
}
