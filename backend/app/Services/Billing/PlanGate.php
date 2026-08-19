<?php

namespace App\Services\Billing;

use App\Enums\AiMode;
use App\Enums\GatedFeature;
use App\Enums\MonitorRegion;
use App\Exceptions\PlanUpgradeRequiredException;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Support\Services\SystemTeam;

/**
 * Resolves a team's plan caps and current usage, and answers the enforcement
 * questions the create/update paths guard against.
 *
 * Caps come from the static catalog in config/plans.php, keyed by the team's
 * entitled plan (see {@see Team::entitledPlan()}); a null cap means
 * "unlimited". This is the single place plan gating reads its limits, so the
 * billing usage report and the write-path guards never diverge.
 *
 * One reader is outside it and stays outside it: `BillingController::usage()`
 * repeats the catalog read inline to REPORT usage. It enforces nothing, and it
 * is reachable only for the acting user's own team, so the system-team
 * exemption in {@see self::limits()} deliberately does not reach it.
 */
class PlanGate
{
    /**
     * The cap map for the team's entitled plan (empty when the plan has no
     * catalog row).
     *
     * This is also the ONE exemption point for the system team
     * ({@see SystemTeam}), and deliberately the only one: every count-style
     * accessor in this class derives from this method
     * ({@see self::monitorLimit()}, {@see self::statusPageLimit()},
     * {@see self::responderLimit()}, {@see self::subscriberLimit()}, and
     * {@see self::aiAnalysisTrialLimit()}), so one branch here covers all of
     * them and no other method needs a system-team check.
     *
     * The exemption exists for the REVERSE direction of the obvious risk.
     * `monitorsUsed()` counts by `team_id`, so uptizm's own service monitors
     * were never in a customer's quota; what would have broken is a plan-gated
     * WRITE path (the Filament monitor form, the catalog seeder) evaluating the
     * system team against the Free tier's single monitor and refusing.
     *
     * @return array<string, mixed>
     */
    public function limits(Team $team): array
    {
        if ($team->is_system) {
            return $this->systemTeamLimits($team);
        }

        return $this->planLimits($team);
    }

    /**
     * The team's caps as the static catalog states them, with no exemption
     * applied.
     *
     * @return array<string, mixed>
     */
    protected function planLimits(Team $team): array
    {
        return collect(config('plans.tiers'))
            ->firstWhere('id', $team->entitledPlan()->value)['limits'] ?? [];
    }

    /**
     * The caps the system team is evaluated against.
     *
     * "Unlimited" is not one idea across this class, so each key is set to the
     * value that means "do not stand in the way" for its OWN direction, and
     * everything else is left at the plan's value rather than swept:
     *
     *  - the four counts take `null`, which is this class's own unlimited token;
     *  - `check_interval_sec` is a FLOOR, so it takes the lowest interval the
     *    platform runs at all, NOT a large number (a large number would be the
     *    slowest cadence, the exact opposite of an exemption);
     *  - `regions` is a CAP, so it takes every region the relay supports;
     *  - `white_label`, `private_pages` and `sso` are booleans about customer
     *    features this team has no surface for, so they keep the plan's value
     *    instead of being forced true;
     *  - `ai` stays at `off`, matching the `AiMode::Off` every service monitor
     *    is pinned to. `off` is not one of the catalog's ascending levels
     *    (inbox < analysis < auto < custom); {@see self::aiRank()} ranks it
     *    lowest through its default arm, which is the intent. Raising it would
     *    contradict the pin and put the fleet AI sweep back on uptizm's own
     *    monitors.
     *  - `ai_analysis_trials` is zeroed for the same reason, so the two
     *    throwing guards ({@see self::assertAiAnalysisAllowed()},
     *    {@see self::assertAiLevel()}) DENY rather than pass if a future path
     *    ever reaches them with this team. They are not supposed to be reached
     *    at all: the guard is `ai_mode` off on every service monitor, which
     *    `SystemTeamTest` pins against `SweepAiSuggestions`' own selection.
     *
     * @return array<string, mixed>
     */
    protected function systemTeamLimits(Team $team): array
    {
        return array_merge($this->planLimits($team), [
            'monitors' => null,
            'status_pages' => null,
            'responders' => null,
            'subscribers' => null,
            'check_interval_sec' => $this->platformMinCheckIntervalSec(),
            'regions' => count(MonitorRegion::cases()),
            'ai' => AiMode::Off->value,
            'ai_analysis_trials' => 0,
        ]);
    }

    /**
     * The fastest check cadence the platform runs at all: the lowest floor any
     * tier in the catalog grants.
     *
     * Derived rather than typed, so it cannot drift from the catalog the way a
     * second literal would. `null` when the catalog states no numeric floor,
     * which lets {@see self::minCheckIntervalSec()} apply its own documented
     * default instead of repeating the number here.
     */
    protected function platformMinCheckIntervalSec(): ?int
    {
        $floors = collect(config('plans.tiers'))
            ->pluck('limits.check_interval_sec')
            ->filter(static fn (mixed $seconds): bool => is_numeric($seconds))
            ->map(static fn (mixed $seconds): int => (int) $seconds)
            ->all();

        return $floors === [] ? null : min($floors);
    }

    /** Maximum monitors the plan allows, or null when unlimited. */
    public function monitorLimit(Team $team): ?int
    {
        $limit = $this->limits($team)['monitors'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /** Maximum status pages the plan allows, or null when unlimited. */
    public function statusPageLimit(Team $team): ?int
    {
        $limit = $this->limits($team)['status_pages'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /** Maximum team responders the plan allows, or null when unlimited. */
    public function responderLimit(Team $team): ?int
    {
        $limit = $this->limits($team)['responders'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /** Maximum subscribers per status page the plan allows, or null when unlimited. */
    public function subscriberLimit(Team $team): ?int
    {
        $limit = $this->limits($team)['subscribers'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * The fastest check interval (seconds) the plan allows. Defaults to the
     * most permissive floor (5s) when the plan carries no catalog cap.
     */
    public function minCheckIntervalSec(Team $team): int
    {
        return (int) ($this->limits($team)['check_interval_sec'] ?? 5);
    }

    /**
     * How many regions a single monitor may check from. Defaults to the most
     * permissive cap (every region) when the plan carries no catalog cap, so
     * an unrecognized or missing tier never regresses a monitor already
     * running on multiple regions.
     */
    public function maxRegionsPerMonitor(Team $team): int
    {
        return (int) ($this->limits($team)['regions'] ?? count(MonitorRegion::cases()));
    }

    /** Whether the plan allows white-labelling status pages. */
    public function allowsWhiteLabel(Team $team): bool
    {
        return (bool) ($this->limits($team)['white_label'] ?? false);
    }

    /** Whether the plan allows private (access-controlled) status pages. */
    public function allowsPrivatePages(Team $team): bool
    {
        return (bool) ($this->limits($team)['private_pages'] ?? false);
    }

    /** Whether the plan allows single sign-on. */
    public function allowsSso(Team $team): bool
    {
        return (bool) ($this->limits($team)['sso'] ?? false);
    }

    /**
     * The team's AI capability level, ascending: inbox < analysis < auto <
     * custom (matches config/plans.php). Defaults to the baseline `inbox`.
     */
    public function aiLevel(Team $team): string
    {
        return (string) ($this->limits($team)['ai'] ?? 'inbox');
    }

    /**
     * Whether the team's AI level meets or exceeds [$required] (e.g. an
     * `analysis`-gated feature is allowed for `analysis`, `auto`, `custom`).
     */
    public function aiLevelAllows(Team $team, string $required): bool
    {
        return $this->aiRank($this->aiLevel($team)) >= $this->aiRank($required);
    }

    /** Ascending rank for an AI capability level; unknown levels rank lowest. */
    private function aiRank(string $level): int
    {
        return match ($level) {
            'analysis' => 1,
            'auto' => 2,
            'custom' => 3,
            default => 0,
        };
    }

    /**
     * How many metered AI monitor setups the plan grants, or 0 when the tier
     * does not meter them (either it entitles the feature or grants none).
     */
    public function aiAnalysisTrialLimit(Team $team): int
    {
        return (int) ($this->limits($team)['ai_analysis_trials'] ?? 0);
    }

    /**
     * Metered AI monitor setups left for [$team], or `null` when the tier
     * entitles AI analysis outright (nothing to count down).
     */
    public function aiAnalysisTrialsRemaining(Team $team): ?int
    {
        if ($this->aiLevelAllows($team, 'analysis')) {
            return null;
        }

        $remaining = $this->aiAnalysisTrialLimit($team) - (int) $team->ai_analysis_trials_used;

        return max(0, $remaining);
    }

    /**
     * Ensure [$team] may run AI monitor analysis, else abort with the upgrade
     * target attached.
     *
     * Two ways to pass: the tier entitles the feature through its `ai` level,
     * or the tier meters it and the team has a setup left. A metered pass is
     * NOT consumed here: {@see consumeAiAnalysisTrial} runs after a setup
     * actually succeeds, so a failed probe never costs the user a try.
     */
    public function assertAiAnalysisAllowed(Team $team): void
    {
        if ($this->aiLevelAllows($team, 'analysis')) {
            return;
        }

        $remaining = $this->aiAnalysisTrialsRemaining($team);
        if ($remaining !== null && $remaining > 0) {
            return;
        }

        $limit = $this->aiAnalysisTrialLimit($team);

        throw new PlanUpgradeRequiredException(
            'pro',
            GatedFeature::AiMonitorAnalysis,
            $limit > 0
                ? __('plans.upgrade_required_metered', [
                    'count' => $limit,
                    'feature' => GatedFeature::AiMonitorAnalysis->label(),
                    'plan' => 'Pro',
                ])
                : null,
        );
    }

    /**
     * Spend one metered AI monitor setup, after one succeeded.
     *
     * No-op for a tier that entitles AI analysis outright, so an upgrade stops
     * the meter without resetting it.
     */
    public function consumeAiAnalysisTrial(Team $team): void
    {
        if ($this->aiLevelAllows($team, 'analysis')) {
            return;
        }

        $team->forceFill([
            'ai_analysis_trials_used' => (int) $team->ai_analysis_trials_used + 1,
        ])->save();
    }

    /** Live monitor count for the team. */
    public function monitorsUsed(Team $team): int
    {
        return Monitor::query()->where('team_id', $team->id)->count();
    }

    /** Live status-page count for the team. */
    public function statusPagesUsed(Team $team): int
    {
        return StatusPage::query()->where('team_id', $team->id)->count();
    }

    /**
     * Live distinct-responder count for the team: the owner plus every
     * attached member, de-duplicated (mirrors the billing usage report).
     */
    public function respondersUsed(Team $team): int
    {
        return $team->users
            ->pluck('id')
            ->push($team->user_id)
            ->unique()
            ->count();
    }

    /**
     * Ensure the team's AI level meets [$required] for [$feature], else abort
     * with a 403 carrying an upgrade message the client surfaces verbatim.
     */
    public function assertAiLevel(Team $team, string $required, GatedFeature $feature): void
    {
        if ($this->aiLevelAllows($team, $required)) {
            return;
        }

        // The plan catalog id, not a display label: the client turns it into an
        // upgrade action that starts checkout for exactly this tier.
        $plan = match ($required) {
            'auto' => 'business',
            'custom' => 'enterprise',
            default => 'pro',
        };

        throw new PlanUpgradeRequiredException($plan, $feature);
    }

    /** Human label for the team's plan, e.g. "Free". */
    public function planLabel(Team $team): string
    {
        return ucfirst($team->entitledPlan()->value);
    }
}
