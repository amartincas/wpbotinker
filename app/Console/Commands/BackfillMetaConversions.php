<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Inventory\ProductFinderService;
use App\Services\MetaConversionsApiService;
use Illuminate\Console\Command;

/**
 * One-off backfill for leads that existed before the LeadObserver started
 * sending every new Lead to Meta's Conversions API automatically. Leads
 * created after the observer went live don't need this — they're already
 * marked via meta_capi_sent_at at creation time.
 *
 * Two attribution paths, depending on what data survived:
 * - Leads with a stored ctwa_clid: sent via the business_messaging path,
 *   exactly like a live conversation would be.
 * - Leads without one (created before ctwa_clid capture existed, but that
 *   still came from a real ad according to the business): sent via the
 *   physical_store/offline path instead, letting Meta match the person by
 *   phone number rather than by click id. Meta documents offline events as
 *   accepted up to ~62 days after they happened — older ones may still be
 *   accepted by the API but are unlikely to be used for attribution.
 */
class BackfillMetaConversions extends Command
{
    protected $signature = 'meta:backfill-conversions {--store= : Store ID to backfill (required — this is a multi-tenant app, never run across all stores at once)} {--dry-run : List what would be sent without actually sending it}';

    protected $description = 'Send existing leads that predate the CAPI integration to Meta\'s Conversions API';

    private const OFFLINE_EVENT_MAX_AGE_DAYS = 62;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $storeId = $this->option('store');

        if (!$storeId) {
            $this->error('Falta --store=ID. Cada tienda de la plataforma tiene su propia moneda y catálogo — corre esto una tienda a la vez.');
            return self::FAILURE;
        }

        $leads = Lead::whereNull('meta_capi_sent_at')
            ->where('store_id', $storeId)
            ->where('customer_name', '!=', 'Unknown')
            ->with(['store', 'payment'])
            ->get();

        $this->info("Leads pendientes de procesar: {$leads->count()}");

        $sentBusinessMessaging = 0;
        $sentOffline = 0;
        $skippedTooOld = 0;
        $skippedNoStore = 0;

        foreach ($leads as $lead) {
            if (!$lead->store) {
                $skippedNoStore++;
                continue;
            }

            $ctwaClid = $lead->ctwa_clid ?? Conversation::where('store_id', $lead->store_id)
                ->where('customer_phone', $lead->customer_phone)
                ->value('ctwa_clid');

            // Same fallback chain used at live creation time: prefer the
            // linked product's price, else try to match product_service_name
            // against the catalog by name.
            $salePrice = $lead->sale_value;
            if ($salePrice === null && $lead->product_id) {
                $salePrice = Product::find($lead->product_id)?->price;
            }
            if ($salePrice === null && !empty($lead->product_service_name)) {
                $salePrice = (new ProductFinderService())
                    ->findProductMentionedInMessage($lead->product_service_name, $lead->store_id)
                    ?->price;
            }

            if ($ctwaClid) {
                $this->line("  [business_messaging] lead #{$lead->id} ({$lead->customer_phone}), sale_value=" . ($salePrice ?? 'null'));

                if (!$dryRun) {
                    MetaConversionsApiService::sendLeadEvent($lead->store, $lead->customer_phone, $ctwaClid, $lead->created_at);

                    if ($salePrice !== null) {
                        MetaConversionsApiService::sendPurchaseEvent(
                            $lead->store, $lead->customer_phone, (float) $salePrice,
                            $lead->payment?->currency ?? $lead->store->meta_capi_currency, $ctwaClid, $lead->created_at
                        );
                    }
                }

                $sentBusinessMessaging++;
            } else {
                $ageInDays = $lead->created_at->diffInDays(now());
                $tooOld = $ageInDays > self::OFFLINE_EVENT_MAX_AGE_DAYS;
                $ageNote = $tooOld ? " (¡{$ageInDays} días, Meta pudo rechazarlo o no usarlo!)" : " ({$ageInDays} días)";

                $this->line("  [offline/phone] lead #{$lead->id} ({$lead->customer_phone}), sale_value=" . ($salePrice ?? 'null') . $ageNote);

                if ($tooOld) {
                    $skippedTooOld++;
                }

                if (!$dryRun) {
                    MetaConversionsApiService::sendOfflineLeadEvent($lead->store, $lead->customer_phone, $lead->created_at);

                    if ($salePrice !== null) {
                        MetaConversionsApiService::sendOfflinePurchaseEvent(
                            $lead->store, $lead->customer_phone, (float) $salePrice,
                            $lead->payment?->currency ?? $lead->store->meta_capi_currency, $lead->created_at
                        );
                    }
                }

                $sentOffline++;
            }

            if (!$dryRun) {
                $lead->update(['meta_capi_sent_at' => now()]);
            }
        }

        $this->newLine();
        $this->info("Enviados vía business_messaging (ctwa_clid): {$sentBusinessMessaging}");
        $this->info("Enviados vía offline/telefono (sin ctwa_clid): {$sentOffline}, de los cuales {$skippedTooOld} tienen más de " . self::OFFLINE_EVENT_MAX_AGE_DAYS . " días");
        $this->info("Sin tienda: {$skippedNoStore}");

        if ($dryRun) {
            $this->comment('Esto fue un dry-run — no se envió ni se marcó nada. Corre sin --dry-run para procesarlos de verdad.');
        }

        return self::SUCCESS;
    }
}
