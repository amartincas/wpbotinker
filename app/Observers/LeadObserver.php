<?php

namespace App\Observers;

use App\Models\Lead;
use App\Services\LeadAlertService;
use App\Services\MetaConversionsApiService;

/**
 * Sends every newly created Lead to Meta's Conversions API, regardless of
 * where it was created (the AI bot, an operator sending a template from the
 * Chat Center, a manual entry in the Filament admin, or the backfill
 * command). Centralizing this here means any future way of creating a Lead
 * gets this for free, instead of each call site having to remember to wire
 * it in itself.
 */
class LeadObserver
{
    public function created(Lead $lead): void
    {
        // 'Unknown' marks control/placeholder records (bot on/off toggles,
        // proactive-template send placeholders) that aren't a real customer
        // conversion — see WhatsAppChatCenter.php. Sending these to Meta
        // would report false lead/purchase signals.
        if ($lead->customer_name === 'Unknown') {
            return;
        }

        // A confirmed subscription payment (PaymentObserver) was already
        // alerted to the admin with its receipt: no second alert.
        if ($lead->payment_id === null) {
            LeadAlertService::notify($lead);
        }

        MetaConversionsApiService::sendLeadEvent(
            $lead->store,
            $lead->customer_phone,
            $lead->ctwa_clid,
            $lead->created_at
        );

        if ($lead->sale_value !== null) {
            MetaConversionsApiService::sendPurchaseEvent(
                $lead->store,
                $lead->customer_phone,
                (float) $lead->sale_value,
                $lead->store->meta_capi_currency,
                $lead->ctwa_clid,
                $lead->created_at
            );
        }

        // Recorded even when both calls above were no-ops (no ctwa_clid, no
        // CAPI credentials, etc.) — this column tracks "already considered
        // for CAPI", which is what the backfill command needs to avoid
        // reprocessing leads on every run, not "successfully delivered".
        $lead->update(['meta_capi_sent_at' => now()]);
    }

    /**
     * A returned order is a lost sale for this business (confirmed with the
     * store owner — it does not get re-shipped), so closing it out as
     * processed is automatic instead of requiring a separate manual step.
     */
    public function saving(Lead $lead): void
    {
        if ($lead->isDirty('order_status') && $lead->order_status === 'devuelto') {
            $lead->is_processed = true;
        }
    }
}
