<?php

namespace Tests\Feature\Marketing;

use App\Enums\MetricSource;
use App\Models\Monitor;
use Tests\TestCase;

/**
 * The inspection and status-page sections.
 *
 * Both are depth arguments about capabilities that really ship, so what these pin is the
 * boundary of each: the numbers come from the enums and defaults that govern them, and
 * neither section reaches for the neighbouring feature that does not work.
 */
class SectionsTest extends TestCase
{
    public function test_every_section_the_nav_offers_is_on_the_page(): void
    {
        // The reverse of ChromeTest's dangling-anchor guard: a section list that grew an
        // entry without the section landing with it.
        $html = $this->get('/')->getContent();

        foreach (['how-it-decides', 'beyond-status-codes', 'status-pages'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "Section #{$id} is missing.");
            $this->assertStringContainsString('href="#'.$id.'"', $html, "Nothing navigates to #{$id}.");
        }
    }

    public function test_the_selector_count_and_kinds_come_from_the_enum(): void
    {
        // `MetricSource` is what the write requests validate against, and its backing
        // values are printed verbatim as the mechanism. A source added there shows up on
        // the page without anybody editing copy.
        $response = $this->get('/')->assertSee(count(MetricSource::cases()).' kinds of selector');

        foreach (MetricSource::cases() as $source) {
            $response->assertSee($source->value);
        }
    }

    public function test_the_certificate_window_comes_from_the_model_default(): void
    {
        // Not a literal 14: read off the model's own attribute default, which is what a
        // monitor created today actually gets.
        $this->get('/')->assertSee('ssl_alert_threshold_days = '.Monitor::make()->ssl_alert_threshold_days);
    }

    public function test_the_status_page_section_does_not_offer_a_custom_domain(): void
    {
        /*
         * `DomainMode` has three cases and the API accepts the third, but no route
         * answers on a customer's own hostname; it needs upstream DNS and a vhost that do
         * not exist. So the feature list is typed rather than derived from the enum,
         * which is the one place in this controller where deriving would have been the
         * wrong instinct.
         */
        $this->get('/')
            ->assertSee('own subdomain')
            ->assertDontSee('custom domain')
            ->assertDontSee('your own domain')
            ->assertDontSee('CNAME');
    }

    public function test_the_uptime_strip_shows_missing_history_as_missing(): void
    {
        /*
         * A status page that paints days it has no data for as healthy is the dishonesty
         * this product removed from its own client (the 90-day bar used to render no-data
         * days green). The illustration has to hold the same line, so the first days use
         * the paused family rather than up.
         */
        $this->get('/')
            ->assertSee('var(--app-paused-soft)', escape: false)
            ->assertSee('var(--app-up)', escape: false);
    }

    public function test_a_two_column_section_leads_with_its_heading(): void
    {
        /*
         * The status-page illustration is on the left at desktop width, which reads well
         * and is a trap: put it first in the DOM to achieve that and a phone gets an
         * unexplained status-page mockup before any heading tells it what section it is
         * in. It shipped that way for one commit, invisible at 1440px.
         *
         * So the copy comes first in the markup and `lg:order-first` moves the
         * illustration on wide screens only.
         */
        $html = $this->get('/')->getContent();

        $section = substr($html, strpos($html, 'id="status-pages"'));
        $section = substr($section, 0, strpos($section, '</section>'));

        $this->assertLessThan(
            strpos($section, 'Acme Status'),
            strpos($section, '<h2'),
            'The illustration precedes the heading in the markup, so a narrow screen reads it first.',
        );
    }

    public function test_the_closing_band_repeats_the_free_tier_from_the_catalog(): void
    {
        // Same derived sentence as the hero, so the bottom of the page cannot promise a
        // limit the top contradicts.
        config(['plans.tiers' => [[
            'id' => 'free',
            'limits' => ['monitors' => 1, 'check_interval_sec' => 180, 'status_pages' => 1],
        ]]]);

        $html = $this->get('/')->getContent();

        $this->assertSame(
            2,
            substr_count($html, '3-minute checks'),
            'The free-tier line should appear exactly twice: the hero and the closing band.',
        );
    }
}
