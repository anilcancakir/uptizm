<?php

namespace App\Models;

use App\Enums\HttpAuthType;
use App\Http\Controllers\Api\V1\MonitorController;
use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One analyze request that sent an operator-supplied credential to a target.
 *
 * The system of record for the detection control described in the migration:
 * `POST /monitors/analyze` will send an arbitrary `Authorization` header to any
 * public host and hand the answer back, and it persists nothing else, so without
 * this row the capability leaves no trace that can be queried.
 *
 * WHAT IT DELIBERATELY CANNOT HOLD. The credential value, and the URL. Only the
 * scheme ({@see HttpAuthType}) and the host, written by
 * {@see MonitorController::auditCredentialledProbe()}, which is also the single
 * writer: nothing revises a row afterwards.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $user_id
 * @property string|null $host
 * @property HttpAuthType $auth_type
 * @property CarbonImmutable|null $created_at
 */
class CredentialProbeAudit extends Model
{
    use ConditionallyUsesUuids;

    /**
     * Disable `updated_at` management: an audit row states what happened at one
     * past instant and is never revised, so the column does not exist.
     */
    public const UPDATED_AT = null;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'auth_type' => HttpAuthType::class,
        'created_at' => 'immutable_datetime',
    ];

    /**
     * The evidence-channel context for this row, read off the row itself.
     *
     * This is what closes the divergence risk in carrying the same fact in two
     * places: the caller writes the row and then logs THIS, so the line cannot
     * describe an attempt the table does not hold, and `audit_id` is the handle
     * that takes a reader from the file to the queryable record. Every value is
     * an identifier or an enum case; nothing here is derived from a credential.
     *
     * @return array<string, string|null>
     */
    public function evidenceContext(): array
    {
        return [
            'audit_id' => (string) $this->getKey(),
            'team_id' => $this->team_id,
            'user_id' => $this->user_id,
            'host' => $this->host,
            'auth_type' => $this->auth_type->value,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
