<?php

namespace App\Services\Billing;

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

        $plan = match ($required) {
            'auto' => 'Business',
            'custom' => 'Enterprise',
            default => 'Pro',
        };

        abort(403, "{$feature} is available on the {$plan} plan and up. Upgrade to use it.");
    }

    /** Human label for the team's plan, e.g. "Free". */
    public function planLabel(Team $team): string
    {
        return ucfirst($team->entitledPlan()->value);
    }
}
