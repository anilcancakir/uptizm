<?php

namespace App\Http\Requests;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /incidents/{incident}/assign: handing an incident
 * to a responder, or clearing the assignment.
 *
 * `assignee_id` is nullable (null unassigns) and, when present, must be a
 * member of the acting user's current team. The controller's `authorizeTeam`
 * guarantees the incident belongs to that same team, so "member of my team" and
 * "member of the incident's team" are the same set here; validating it as a rule
 * means a non-member is a 422 field error the form can render inline rather than
 * an opaque failure.
 */
class AssignIncidentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->current_team_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_id' => [
                'nullable',
                'string',
                Rule::in($this->teamMemberIds()),
            ],
        ];
    }

    /**
     * Every user id on the acting team's roster: the owner plus the `team_user`
     * pivot members. Mirrors the union the team-members endpoint lists, so the
     * roster the client renders and the roster this rule accepts cannot drift.
     *
     * @return array<int, string>
     */
    protected function teamMemberIds(): array
    {
        $team = Team::query()->find($this->user()?->current_team_id);
        if ($team === null) {
            return [];
        }

        return $team->users()
            ->pluck('users.id')
            ->push($team->user_id)
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
