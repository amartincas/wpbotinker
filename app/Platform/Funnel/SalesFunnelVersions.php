<?php

namespace App\Platform\Funnel;

use App\Models\Plan;
use App\Models\Product;
use App\Models\SalesFunnel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The versions of a product's sales funnel. Publishing adds a version and
 * makes it the active one, serialized per product by a row lock (the unique
 * product/version index backs it up); there is one active version at most
 * because it is one column of the product. It only reads the product and
 * moves that column: no other configuration, and never the free replies
 * already given (sample_deliveries).
 */
class SalesFunnelVersions
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function publish(Product $product, array $definition, ?int $createdBy = null): SalesFunnel
    {
        return $this->addVersion($product, $definition, $createdBy, null);
    }

    /** Publishes a copy of a version as a new one; the history stays as it was. */
    public function restore(SalesFunnel $version, ?int $createdBy = null): SalesFunnel
    {
        return $this->addVersion($version->product, $version->definition, $createdBy, $version->id);
    }

    /** The product goes back to working without a funnel; its versions stay. */
    public function deactivate(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->lock($product);
            $this->activate($product, null);
        });
    }

    public function active(Product $product): ?SalesFunnel
    {
        $id = Product::whereKey($product->id)->value('active_sales_funnel_id');

        return $id !== null ? SalesFunnel::find($id) : null;
    }

    /**
     * @return Collection<int, SalesFunnel> newest first
     */
    public function history(Product $product): Collection
    {
        return SalesFunnel::where('product_id', $product->id)->orderByDesc('version')->get();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function addVersion(Product $product, array $definition, ?int $createdBy, ?int $restoredFrom): SalesFunnel
    {
        return DB::transaction(function () use ($product, $definition, $createdBy, $restoredFrom) {
            $locked = $this->lock($product);

            $funnel = SalesFunnel::create([
                'product_id' => $locked->id,
                'version' => (int) SalesFunnel::where('product_id', $locked->id)->max('version') + 1,
                'definition' => $definition,
                'product_snapshot' => $this->snapshot($locked),
                'restored_from_id' => $restoredFrom,
                'created_by' => $createdBy,
            ]);

            $this->activate($product, $funnel->id);

            return $funnel;
        });
    }

    private function lock(Product $product): Product
    {
        return Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Only this column, with a plain query: publishing is not an edit of the
     * product, so it neither runs the product's checks nor touches updated_at.
     */
    private function activate(Product $product, ?int $funnelId): void
    {
        DB::table('products')->where('id', $product->id)->update(['active_sales_funnel_id' => $funnelId]);

        $product->setRawAttributes(['active_sales_funnel_id' => $funnelId] + $product->getAttributes(), sync: true);
    }

    /**
     * The commercial configuration of the product at publication, to consult
     * later. The service instructions (ai_sales_strategy) are not part of it.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Product $product): array
    {
        return [
            ...$product->only([
                'name', 'description', 'faq_context', 'access_mode', 'domain_key', 'aliases', 'meta_ad_ids',
                'opening_message', 'opening_triggers', 'sample_instructions', 'sample_closing_message', 'commercial_instructions',
            ]),
            'plans' => Plan::where('product_id', $product->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['name', 'price', 'currency', 'duration_days'])->toArray(),
        ];
    }
}
