<?php

namespace App\Models;

use App\Enums\Plan;
use FlutterSdk\MagicStarter\Models\Team as MagicStarterTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
     * @var array<string, string>
     */
    protected $casts = [
        'plan' => Plan::class,
        'trial_ends_at' => 'datetime',
    ];

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
