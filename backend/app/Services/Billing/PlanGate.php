<?php

namespace App\Services\Billing;

use App\Exceptions\PlanUpgradeRequiredException;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;

/**
 * Resolves a team's plan caps and current usage, and answers the enforcement
 * questions the create/update paths guard against.
 *
 * Caps come from the static catalog in config/plans.php, keyed by the team's
 * entitled plan (see {@see Team::entitledPlan()}); a null cap means
 * "unlimited". This is the single place plan gating reads its limits, so the
 * billing usage report and the write-path guards never diverge.
 */
class PlanGate
{
    /**
     * The cap map for the team's entitled plan (empty when the plan has no
     * catalog row).
     *
     * @return array<string, mixed>
     */
    public function limits(Team $team): array
    {
        return collect(config('plans.tiers'))
            ->firstWhere('id', $team->entitledPlan()->value)['limits'] ?? [];
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
            'AI monitor analysis',
            $limit > 0
                ? sprintf(
                    'You have used all %d free AI monitor setups. AI monitor analysis is available on the Pro plan and up.',
                    $limit,
                )
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
    public function assertAiLevel(Team $team, string $required, string $feature): void
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
