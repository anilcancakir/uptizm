<?php

namespace App\Http\Requests;

/**
 * Validation rules for PUT /monitors/{monitor}/metrics/{metric}.
 *
 * Mirrors {@see StoreMonitorMetricRequest} by consuming its one rule definition
 * and asking for the partial variant, so a partial edit validates only the keys
 * it sends. Nothing is re-declared here on purpose: the controller used to carry
 * the whole rule set inline, which is how the two paths drifted.
 *
 * The parent's cross-field hook is inherited deliberately. It reads MERGED
 * state (the stored row plus the incoming keys), which is what makes a PATCH
 * carrying one value list still get checked against the two lists it did not
 * carry, and it reads the stored row off this route's `metric` parameter.
 */
class UpdateMonitorMetricRequest extends StoreMonitorMetricRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->metricRules(partial: true);
    }
}
