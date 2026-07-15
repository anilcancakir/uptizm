<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Cashier\Invoice;

/**
 * JSON shape for a single Cashier invoice on the billing screen.
 *
 * Money is rendered server-side ({@see Invoice::total()} returns the formatted,
 * currency-aware string) so the client never does amount math; `pdf_url` is the
 * Stripe-hosted PDF link the "Receipt" action opens.
 *
 * @property Invoice $resource
 */
class InvoiceResource extends JsonResource
{
    /**
     * Transform the invoice into its wire shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'number' => $this->resource->number,
            'date' => $this->resource->date()->toIso8601String(),
            'amount' => $this->resource->total(),
            'status' => $this->resource->status,
            'pdf_url' => $this->resource->invoice_pdf,
        ];
    }
}
