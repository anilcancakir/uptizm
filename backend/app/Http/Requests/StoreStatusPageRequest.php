<?php

namespace App\Http\Requests;

use App\Models\StatusPage;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /status-pages.
 *
 * Mirrors {@see StoreMonitorRequest}'s authorization shape (an authenticated
 * user with a current team). `slug` is globally unique across all teams
 * because it addresses the public page on a shared path-based host
 * ({@see StatusPage::getRouteKeyName()}), so the uniqueness check
 * is not team-scoped.
 */
class StoreStatusPageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->current_team_id !== null;
    }

    /**
     * Enforce the team's status-page plan caps after field validation, as 422
     * errors with upgrade-oriented messages the client shows verbatim:
     *
     * - COUNT (create only; {@see UpdateStatusPageRequest} inherits this and an
     *   edit adds no page): a Free team (1 status page) cannot create more.
     * - PRIVATE (create and update): making a page private (`is_public=false`)
     *   requires the private-pages entitlement (Business+).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $team = Team::find($this->user()?->current_team_id);
            if ($team === null) {
                return;
            }

            $gate = new PlanGate;

            // Count: create only (a bound {statusPage} route is an edit).
            if (! ($this->route('statusPage') instanceof StatusPage)) {
                $limit = $gate->statusPageLimit($team);
                if ($limit !== null && $gate->statusPagesUsed($team) >= $limit) {
                    $suffix = $limit === 1 ? '' : 's';
                    $validator->errors()->add(
                        'plan',
                        "Your {$gate->planLabel($team)} plan is limited to {$limit} status page{$suffix}. Upgrade to add more.",
                    );
                }
            }

            // Private pages: create + update. Only gate an explicit private
            // request; an omitted or public flag needs no entitlement.
            if ($this->has('is_public') && ! $this->boolean('is_public') && ! $gate->allowsPrivatePages($team)) {
                $validator->errors()->add(
                    'is_public',
                    'Private status pages are available on the Business plan and up. Upgrade to make a page private.',
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:200',
            ],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('status_pages', 'slug'),
            ],
            'domain_mode' => [
                'nullable',
                'string',
                Rule::in([
                    'path',
                    'custom',
                ]),
            ],
            'custom_domain' => [
                'nullable',
                'string',
                'max:255',
            ],
            'brand_color' => [
                'nullable',
                'string',
                'max:9',
                'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/',
            ],
            'logo_path' => [
                'nullable',
                'string',
                'max:255',
            ],
            'logo_text' => [
                'nullable',
                'string',
                'max:8',
            ],
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
            'is_public' => [
                'boolean',
            ],
            'subscriptions_enabled' => [
                'boolean',
            ],
        ];
    }
}
