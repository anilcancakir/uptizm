<?php

namespace Tests\Feature\StatusPage;

use App\Mail\StatusPageSubscribeConfirmation;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the public subscribe flow: a subscribe sends exactly one confirm mail,
 * a second subscribe with the same address is deduped (no second mail, same
 * uniform response, no already-subscribed oracle), confirmation is single-use
 * (the token is burned so a replay is a 404), unsubscribe removes the row, and
 * every route 404s on an unknown, private, or subscriptions-disabled page with
 * the same fail-closed parity the read surface enforces.
 */
class SubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribe_sends_one_mail_and_creates_an_unconfirmed_row(): void
    {
        Mail::fake();
        $page = $this->makePage('acme', isPublic: true, subscriptionsEnabled: true);

        $this->post('/s/acme/subscribe', ['email' => 'demo@example.com'])->assertOk();

        Mail::assertSent(StatusPageSubscribeConfirmation::class, 1);
        Mail::assertSent(
            StatusPageSubscribeConfirmation::class,
            fn (StatusPageSubscribeConfirmation $mail) => $mail->hasTo('demo@example.com'),
        );

        $subscriber = StatusPageSubscriber::query()->where('email', 'demo@example.com')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame($page->id, $subscriber->status_page_id);
        $this->assertNull($subscriber->confirmed_at);
        $this->assertNotNull($subscriber->confirmed_token);
        $this->assertNotNull($subscriber->unsubscribe_token);
    }

    public function test_a_second_subscribe_with_the_same_email_is_deduped(): void
    {
        Mail::fake();
        $this->makePage('acme', isPublic: true, subscriptionsEnabled: true);

        $first = $this->post('/s/acme/subscribe', ['email' => 'demo@example.com']);
        $second = $this->post('/s/acme/subscribe', ['email' => 'demo@example.com']);

        $first->assertOk();
        $second->assertOk();

        // Dedupe: no second confirm mail, and a single row remains.
        Mail::assertSent(StatusPageSubscribeConfirmation::class, 1);
        $this->assertSame(1, StatusPageSubscriber::query()->where('email', 'demo@example.com')->count());

        // No already-subscribed oracle: the second response is byte-identical.
        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_confirm_stamps_confirmed_at_and_burns_the_token(): void
    {
        Mail::fake();
        $this->makePage('acme', isPublic: true, subscriptionsEnabled: true);
        $this->post('/s/acme/subscribe', ['email' => 'demo@example.com'])->assertOk();

        $token = StatusPageSubscriber::query()->where('email', 'demo@example.com')->value('confirmed_token');

        $this->get("/s/acme/subscribe/confirm/{$token}")->assertOk();

        $subscriber = StatusPageSubscriber::query()->where('email', 'demo@example.com')->first();
        $this->assertNotNull($subscriber->confirmed_at);
        $this->assertNull($subscriber->confirmed_token);
    }

    public function test_confirming_again_with_the_burned_token_is_a_no_op_four_oh_four(): void
    {
        Mail::fake();
        $this->makePage('acme', isPublic: true, subscriptionsEnabled: true);
        $this->post('/s/acme/subscribe', ['email' => 'demo@example.com'])->assertOk();

        $token = StatusPageSubscriber::query()->where('email', 'demo@example.com')->value('confirmed_token');

        $this->get("/s/acme/subscribe/confirm/{$token}")->assertOk();

        // Single-use: the token was nulled on first confirm, so a replay finds
        // nothing and 404s rather than re-confirming.
        $this->get("/s/acme/subscribe/confirm/{$token}")->assertNotFound();
    }

    public function test_unsubscribe_removes_the_row(): void
    {
        Mail::fake();
        $this->makePage('acme', isPublic: true, subscriptionsEnabled: true);
        $this->post('/s/acme/subscribe', ['email' => 'demo@example.com'])->assertOk();

        $token = StatusPageSubscriber::query()->where('email', 'demo@example.com')->value('unsubscribe_token');

        $this->get("/unsubscribe/{$token}")->assertOk();

        $this->assertSame(0, StatusPageSubscriber::query()->where('email', 'demo@example.com')->count());
    }

    public function test_unsubscribe_with_an_unknown_token_is_a_four_oh_four(): void
    {
        $this->get('/unsubscribe/'.Str::random(48))->assertNotFound();
    }

    public function test_confirm_with_an_unknown_token_is_a_four_oh_four(): void
    {
        $this->makePage('acme', isPublic: true, subscriptionsEnabled: true);

        $this->get('/s/acme/subscribe/confirm/'.Str::random(48))->assertNotFound();
    }

    public function test_subscribe_on_an_unknown_slug_is_a_four_oh_four(): void
    {
        Mail::fake();

        $this->post('/s/nope/subscribe', ['email' => 'demo@example.com'])->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_subscribe_on_a_private_slug_is_a_four_oh_four(): void
    {
        Mail::fake();
        $this->makePage('priv', isPublic: false, subscriptionsEnabled: true);

        $this->post('/s/priv/subscribe', ['email' => 'demo@example.com'])->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_subscribe_when_subscriptions_are_disabled_is_a_four_oh_four(): void
    {
        Mail::fake();
        $this->makePage('acme', isPublic: true, subscriptionsEnabled: false);

        $this->post('/s/acme/subscribe', ['email' => 'demo@example.com'])->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_a_locale_submitted_with_the_subscribe_post_is_captured_on_the_row(): void
    {
        // The subscribe route carries no locale segment (Step 1's Must NOT), so
        // the only honest capture source is a value the page's own form submits.
        // The production form is not yet wired to send one (that view sits
        // outside this step's scope), so this simulates the payload it will send
        // once it is: a Turkish-URL visitor's form posting `locale=tr` alongside
        // the email.
        Mail::fake();
        $page = $this->makePage('acme-tr', isPublic: true, subscriptionsEnabled: true);

        $this->post('/s/acme-tr/subscribe', [
            'email' => 'demo@example.com',
            'locale' => 'tr',
        ])->assertOk();

        $subscriber = StatusPageSubscriber::query()->where('email', 'demo@example.com')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame('tr', $subscriber->locale);

        // The confirmation mail's subject resolves in the subscriber's language,
        // not the page's (the page here has no `locale` set at all).
        Mail::assertSent(StatusPageSubscribeConfirmation::class, function ($mail) use ($page) {
            $mail->locale('tr');

            return $mail->envelope()->subject === __(
                'status.emails.confirm.subject',
                ['page' => $page->name],
                'tr',
            );
        });

        // The unsubscribe page answers in the subscriber's language, and still
        // does so after the row is deleted in the same request.
        $this->get(route('status.unsubscribe', ['token' => $subscriber->unsubscribe_token]))
            ->assertOk()
            ->assertSee('Abonelikten çıkıldı')
            ->assertDontSee('Unsubscribed');

        $this->assertSame(0, StatusPageSubscriber::query()->where('email', 'demo@example.com')->count());
    }

    public function test_an_unsupported_submitted_locale_is_stored_as_null_not_rejected(): void
    {
        Mail::fake();
        $this->makePage('acme-xx', isPublic: true, subscriptionsEnabled: true);

        $this->post('/s/acme-xx/subscribe', [
            'email' => 'demo@example.com',
            'locale' => 'de',
        ])->assertOk();

        $subscriber = StatusPageSubscriber::query()->where('email', 'demo@example.com')->first();
        $this->assertNotNull($subscriber);
        $this->assertNull($subscriber->locale);
    }

    public function test_with_no_submitted_locale_the_pages_own_canonical_language_is_captured(): void
    {
        // The fallback case, from the unprefixed URL: nothing in the POST body
        // names a language, so the capture falls through to the page's own
        // `locale` column exactly as the description requires.
        Mail::fake();
        $page = $this->makePage('acme-fallback', isPublic: true, subscriptionsEnabled: true);
        $page->update(['locale' => 'tr']);

        $this->post('/s/acme-fallback/subscribe', ['email' => 'demo@example.com'])->assertOk();

        $subscriber = StatusPageSubscriber::query()->where('email', 'demo@example.com')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame('tr', $subscriber->locale);
    }

    /**
     * Creates a persisted status page for a fresh team, with the given
     * visibility and subscription toggle. No monitors are attached: the
     * subscribe flow never reads them.
     */
    protected function makePage(string $slug, bool $isPublic, bool $subscriptionsEnabled): StatusPage
    {
        $team = $this->makeTeam();

        $page = new StatusPage([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => $isPublic,
            'subscriptions_enabled' => $subscriptionsEnabled,
        ]);
        $page->save();

        return $page;
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Status Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Status Team',
        ]);
    }
}
