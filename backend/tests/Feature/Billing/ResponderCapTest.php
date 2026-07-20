<?php

namespace Tests\Feature\Billing;

use App\Actions\PlanGatedInviteTeamMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The plan responder cap enforced by {@see PlanGatedInviteTeamMember}, bound
 * over the starter's InvitesTeamMembers contract in AppServiceProvider.
 */
class ResponderCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_free_team_cannot_invite_past_its_single_responder_cap(): void
    {
        // Free allows 1 responder, which is the owner, so any invite is blocked.
        $user = User::factory()->create();
        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Solo Ops',
            'personal_team' => true,
        ]);

        $this->expectException(ValidationException::class);

        (new PlanGatedInviteTeamMember)->invite($user, $team, 'new@example.com', 'member');
    }

    public function test_a_paid_team_may_invite_within_its_responder_cap(): void
    {
        Notification::fake();
        Mail::fake();

        // Pro allows 3 responders; the owner is 1, so an invite is allowed and
        // delegates to the starter action, returning the created invitation.
        $user = User::factory()->create();
        $team = Team::create(['user_id' => $user->id, 'name' => 'Pro Ops']);
        $team->forceFill(['plan' => 'pro'])->save();

        $invitation = (new PlanGatedInviteTeamMember)->invite($user, $team, 'new@example.com', 'member');

        $this->assertSame('new@example.com', $invitation->email);
    }
}
