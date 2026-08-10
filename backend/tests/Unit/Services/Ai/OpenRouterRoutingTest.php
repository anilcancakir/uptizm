<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\LaravelAiAssistantGateway;
use App\Services\Ai\LaravelAiDigestGateway;
use App\Services\Ai\LaravelAiIncidentAnalysisGateway;
use App\Services\Ai\LaravelAiMetricDiscoveryGateway;
use App\Services\Ai\LaravelAiTriageGateway;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins OpenRouter's routing preference across EVERY gateway rather than one.
 *
 * The change these tests hold is one line of request body, `provider.sort =
 * latency`, and it exists because OpenRouter's default routing is
 * price-weighted: roughly twenty upstreams serve the pinned model and the
 * model page's own median end-to-end latency table spreads them 8x, from
 * CoreWeave at 3.42s to Ambient at 18.70s. One identical call measured 3.0s,
 * 6.0s and 33.8s here, which is that spread and not this application.
 *
 * The reason the suite enumerates instead of naming: this project has shipped
 * the same correct fix to ONE of several AI gateways three times. So
 * {@see test_every_laravel_ai_agent_in_the_ai_directory_carries_the_routing_preference}
 * derives the gateway list from the filesystem rather than from a literal, and
 * asserts the count first so it cannot pass over an empty list.
 */
class OpenRouterRoutingTest extends TestCase
{
    /**
     * Every gateway that prompts a model, and what each one is for.
     *
     * A literal list, deliberately: it is the second half of the enumeration
     * guard below. The guard proves nothing was MISSED; this list proves each
     * named gateway actually answers, which reflection alone cannot.
     *
     * @return list<class-string>
     */
    public static function gateways(): array
    {
        return [
            LaravelAiAnalysisGateway::class,
            LaravelAiAssistantGateway::class,
            LaravelAiDigestGateway::class,
            LaravelAiIncidentAnalysisGateway::class,
            LaravelAiMetricDiscoveryGateway::class,
            LaravelAiTriageGateway::class,
        ];
    }

    public function test_every_gateway_asks_openrouter_to_order_providers_by_latency(): void
    {
        foreach (self::gateways() as $class) {
            $gateway = app($class);

            $this->assertInstanceOf(HasProviderOptions::class, $gateway, $class.' cannot carry provider options.');

            $this->assertSame(
                'latency',
                $gateway->providerOptions(Lab::OpenRouter)['provider']['sort'] ?? null,
                $class.' does not ask OpenRouter to sort by latency.',
            );
        }
    }

    /**
     * The same assertion through the package's own path.
     *
     * `laravel/ai` does not call `providerOptions()` with a Lab: the OpenRouter
     * gateway passes `$provider->driver()`, the string `openrouter`, and
     * {@see TextGenerationOptions::providerOptions()} converts it. Asserting the
     * enum alone would pass even if that conversion stopped happening, and the
     * body would then ship without the routing preference.
     */
    public function test_the_routing_preference_reaches_the_package_from_the_driver_name(): void
    {
        foreach (self::gateways() as $class) {
            $options = TextGenerationOptions::forAgent(app($class))->providerOptions('openrouter');

            $this->assertSame('latency', $options['provider']['sort'] ?? null, $class.' loses the sort on the wire.');
        }
    }

    /**
     * Fallbacks stay ON and no upstream is pinned.
     *
     * The owner's stated preference: a slow correct answer beats a fast
     * failure, so a stalled top pick must still fall through. `order` / `only`
     * would hard-pin an upstream and `ignore` would be a hand-maintained list
     * against a roster that changes continuously. All four are absent, and this
     * asserts their absence because adding one is the plausible next edit.
     */
    public function test_no_gateway_pins_an_upstream_or_disables_fallbacks(): void
    {
        foreach (self::gateways() as $class) {
            $routing = app($class)->providerOptions(Lab::OpenRouter)['provider'];

            foreach (['allow_fallbacks', 'order', 'only', 'ignore'] as $key) {
                $this->assertArrayNotHasKey($key, $routing, $class.' constrains routing with ['.$key.'].');
            }
        }
    }

    public function test_the_routing_preference_is_a_no_op_for_a_provider_that_is_not_openrouter(): void
    {
        foreach (self::gateways() as $class) {
            $this->assertSame([], app($class)->providerOptions(Lab::Anthropic), $class.' leaks OpenRouter options.');
        }
    }

    /**
     * The anti-trap guard: the gateway list comes from the directory.
     *
     * A new gateway that prompts a model is a `laravel/ai` Agent living beside
     * the others, so it is discoverable. Without this, adding the seventh
     * gateway and forgetting the trait is invisible until a production latency
     * measurement disagrees with the code.
     */
    public function test_every_laravel_ai_agent_in_the_ai_directory_carries_the_routing_preference(): void
    {
        $agents = [];

        foreach (File::files(app_path('Services/Ai')) as $file) {
            $class = 'App\\Services\\Ai\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(Agent::class)) {
                continue;
            }

            $agents[] = $class;
        }

        // Asserted BEFORE the loop: an enumeration that found nothing would
        // otherwise certify every gateway by iterating an empty list.
        $this->assertSame(self::gateways(), $agents, 'The discovered gateway list drifted from the named one.');

        foreach ($agents as $class) {
            $this->assertTrue(
                (new ReflectionClass($class))->implementsInterface(HasProviderOptions::class),
                $class.' prompts a model but declares no provider options.',
            );
        }
    }
}
