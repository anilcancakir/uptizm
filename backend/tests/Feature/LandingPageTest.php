<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The apex host's marketing page.
 *
 * The assertions that matter here are the two things a deploy gets wrong: the
 * sign-in and register calls to action have to point at the FRONTEND host (the
 * Flutter client lives on its own origin, so a link built from this app's own
 * URL lands on the API and 404s), and the region list has to come from
 * configuration so the page cannot advertise a region no monitor can select.
 */
class LandingPageTest extends TestCase
{
    public function test_the_apex_root_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_calls_to_action_point_at_the_frontend_host(): void
    {
        config([
            'app.url' => 'https://api.uptizm.test',
            'app.frontend_url' => 'https://app.uptizm.test',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('https://app.uptizm.test/register', escape: false);
        $response->assertSee('https://app.uptizm.test/login', escape: false);
        $response->assertDontSee('https://api.uptizm.test/register', escape: false);
    }

    public function test_a_trailing_slash_on_the_frontend_url_does_not_double_up(): void
    {
        config(['app.frontend_url' => 'https://app.uptizm.test/']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('https://app.uptizm.test//login', escape: false);
    }

    public function test_the_region_list_comes_from_configuration(): void
    {
        config(['relay.regions' => ['ap-south', 'sa-east']]);

        $response = $this->get('/');

        $response->assertSee('ap-south');
        $response->assertSee('sa-east');
    }

    public function test_the_region_section_is_omitted_when_no_region_is_configured(): void
    {
        config(['relay.regions' => []]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Where checks run from');
    }
}
