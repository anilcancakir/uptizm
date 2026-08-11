<?php

namespace App\Services\Ai\Concerns;

use App\Services\Ai\LaravelAiAnalysisGateway;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

/**
 * Ask OpenRouter to order its upstreams by measured latency instead of price.
 *
 * Satisfies {@see HasProviderOptions} for every gateway in this application, so
 * a class using it must also declare that contract: `laravel/ai` reads the
 * method through an `instanceof` check
 * ({@see Laravel\Ai\Gateway\TextGenerationOptions::providerOptions()}), and a
 * public method on a class that does not declare the interface is never called.
 *
 * WHY LATENCY, AND WHAT IT COSTS
 *
 * One identical call against the pinned model measured 3.0s, 6.0s and 33.8s at
 * different times, and a per-call ceiling truncated the slowest outright. The
 * cause is routing, not this application: roughly twenty upstreams serve
 * DeepSeek V4 Flash, OpenRouter's default routing is PRICE-weighted, and the
 * model page's own median end-to-end latency table spreads them about 8x. The
 * fast cluster sits near 3.4-3.8s (CoreWeave, Cloudflare, DeepSeek native,
 * Baidu Qianfan) and the slow tail near 14-19s (DigitalOcean at 14.58s median
 * with a range topping 25.8s, Morph, OpenInference, Ambient). The observed
 * spread lands exactly between the two clusters.
 *
 * The accepted cost, decided by the product owner: this turns OpenRouter's
 * price-weighted load balancing off, so cost per call may rise.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * - `allow_fallbacks` is left at its default `true`. A stalled top pick must
 *   still fall through to the next upstream, because a slow correct answer beats
 *   a fast failure: every caller of these gateways degrades to a deterministic
 *   answer on an error, so a refused route is a worse outcome than a slow one.
 * - `order` and `only` are absent: pinning an upstream trades the tail latency
 *   for a single point of failure, and `sort` already prefers the fast cluster
 *   without giving up the other nineteen.
 * - `ignore` is absent by decision rather than oversight. Naming today's slow
 *   upstreams would freeze a roster that changes continuously into a list
 *   nobody will revisit; the sort re-derives the same preference on every call.
 *
 * WHY A TRAIT AND NOT SIX COPIES
 *
 * Six gateways prompt a model through OpenRouter, and this project has shipped a
 * correct fix to exactly ONE of them three times. Six identical method bodies
 * would make the next routing decision a six-file edit with five chances to
 * miss one; the shared source makes it one edit, and
 * {@see Tests\Unit\Services\Ai\OpenRouterRoutingTest} enumerates the directory
 * so a seventh gateway that forgets the trait fails a test rather than shipping
 * a quiet latency regression.
 */
trait RoutesOpenRouterByLatency
{
    /**
     * The provider-routing object sent with every request to this provider.
     *
     * Empty for anything that is not OpenRouter: `provider` is OpenRouter's own
     * field and every other lab would either ignore it or refuse the body.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::OpenRouter) {
            return [];
        }

        return [
            'provider' => [
                'sort' => 'latency',
                ...$this->openRouterRoutingConstraints(),
            ],
        ];
    }

    /**
     * Extra keys to merge into the `provider` routing object.
     *
     * A gateway that needs a routing CONSTRAINT on top of the shared preference
     * overrides this; {@see LaravelAiAnalysisGateway} is the one that does, for
     * `require_parameters`. It stays a separate seam because a constraint
     * narrows which upstreams may serve the call at all, which is a per-gateway
     * decision, while the sort only reorders them and is not.
     *
     * @return array<string, mixed>
     */
    protected function openRouterRoutingConstraints(): array
    {
        return [];
    }
}
