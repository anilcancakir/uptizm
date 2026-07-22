<?php

namespace App\Models;

use App\Enums\EscalationTargetType;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered step in an {@see EscalationPolicy}'s paging chain: fires
 * `delay_minutes` after the previous step (or after incident open, for the
 * first step) against `target_type`. Escalation is people-only.
 *
 * `target_id` names a specific user when targeting
 * {@see EscalationTargetType::User}; it is unused when targeting
 * {@see EscalationTargetType::OnCall} (resolved at dispatch time from the
 * team's on-call rotation).
 *
 * Relationships:
 * - belongs to {@see EscalationPolicy} (the owning paging chain)
 */
class EscalationStep extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'escalation_policy_id',
        'position',
        'delay_minutes',
        'target_type',
        'target_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
        'delay_minutes' => 'integer',
        'target_type' => EscalationTargetType::class,
    ];

    /**
     * Owning escalation policy.
     *
     * @return BelongsTo<EscalationPolicy, self>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class, 'escalation_policy_id');
    }
}
