<?php

namespace App\Models;

use App\Enums\Plan;
use App\Services\Billing\PlanGate;
use App\Support\Services\SystemTeam;
use FlutterSdk\MagicStarter\Models\Team as MagicStarterTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Billable;

class Team extends MagicStarterTeam
{
    use Billable;
    use HasFactory;

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
     * @var array<string, string>
     */
    protected $casts = [
        'plan' => Plan::class,
        'trial_ends_at' => 'datetime',
        'is_system' => 'boolean',
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
