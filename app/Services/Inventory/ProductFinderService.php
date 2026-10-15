<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ProductFinderService
{
    /**
     * List of generic/short search terms that should trigger full catalog return
     */
    private const GENERIC_SEARCH_TERMS = [
        'precio', 'price', 'costo', 'cost',
        'taller', 'workshop', 'class', 'course',
        'información', 'information', 'info', 'details',
        'catalogo', 'catalog', 'lista', 'list',
        'productos', 'products', 'servicios', 'services',
        'oferta', 'offer', 'promo', 'promotion',
        'disponible', 'available', 'que hay', 'what', 'opciones', 'options'
    ];

    /**
     * Resolve the exact product a customer is asking about via the Meta
     * Click-to-WhatsApp ad ID (referral.source_id in the webhook payload),
     * scoped to the store already resolved from the phone number.
     *
     * More reliable than text search: the store admin tags the ad ID once
     * per product, so it doesn't depend on the ad's prefilled message
     * matching the product name.
     *
     * An ad ID tagged on more than one product of the store is ambiguous:
     * no product is returned (rather than an arbitrary first one), so the
     * conversation resolves the product as it does without an ad.
     *
     * @param string $adId
     * @param int $storeId
     * @return Product|null
     */
    public function findProductByAdId(string $adId, int $storeId): ?Product
    {
        $matches = Product::where('store_id', $storeId)
            ->whereJsonContains('meta_ad_ids', $adId)
            ->with('images')
            ->limit(2)
            ->get();

        $product = $matches->count() === 1 ? $matches->first() : null;

        Log::info('PRODUCT_FINDER: Resolución por ad_id de Meta', [
            'store_id' => $storeId,
            'ad_id' => $adId,
            'matched_product_id' => $product?->id,
            'ambiguous' => $matches->count() > 1,
        ]);

        return $product;
    }

    /**
     * Ids of the store's products tagged with this ad ID, in a stable order:
     * more than one means the ad alone cannot tell which product it is.
     *
     * @return list<int>
     */
    public function productIdsForAdId(string $adId, int $storeId): array
    {
        return Product::where('store_id', $storeId)
            ->whereJsonContains('meta_ad_ids', $adId)
            ->orderBy('id')
            ->limit(10)
            ->pluck('id')
            ->all();
    }

    /**
     * Search for products/services by query string in the store.
     * Returns array with formatted context and type information.
     * If generic/short search or no results found, returns full catalog.
     *
     * @param string $query
     * @param int $storeId
     * @param int $limit
     * @return array ['context' => string, 'products' => Collection, 'hasServices' => bool, 'hasProducts' => bool]
     */
    public function findProductsWithTypes(string $query, int $storeId, int $limit = 3): array
    {
        // Check whether a product is explicitly named in the message BEFORE
        // applying the generic-term heuristic below. Without this, a message
        // like "oferta de precio por el Dispositivo Urinario" gets flagged
        // generic just because it contains "oferta"/"precio" — even though
        // it names a specific product — and the AI ends up receiving every
        // product's (possibly contradictory) sales strategy at once.
        $mentionedProduct = $this->findProductMentionedInMessage($query, $storeId);

        if ($mentionedProduct) {
            Log::info("PRODUCT_FINDER: Product mentioned by name in message", [
                'store_id' => $storeId,
                'matched_product_id' => $mentionedProduct->id,
                'matched_product_name' => $mentionedProduct->name,
            ]);

            $products = Product::where('id', $mentionedProduct->id)
                ->with('images')
                ->get(['id', 'name', 'price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

            return [
                'context' => $this->formatProducts($products),
                'products' => $products,
                'hasServices' => $products->where('type', 'service')->isNotEmpty(),
                'hasProducts' => $products->where('type', 'product')->isNotEmpty(),
            ];
        }

        $queryLower = strtolower(trim($query));
        $isGenericQuery = $this->isGenericQuery($queryLower);

        Log::info("PRODUCT_FINDER: Search Parameters", [
            'store_id' => $storeId,
            'query_length' => strlen($queryLower), // length only: the query is the customer's message
            'is_generic' => $isGenericQuery,
        ]);

        // If generic or very short query, return full catalog
        if ($isGenericQuery) {
            Log::info("PRODUCT_FINDER: Generic query detected, fetching full catalog", [
                'store_id' => $storeId,
            ]);

            $products = Product::where('store_id', $storeId)
                ->with('images')
                ->limit($limit)
                ->get(['id', 'name', 'price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);
        } else {
            // Specific search query - use LIKE with wildcards
            $searchTerm = "%{$query}%";
            
            // Debug: Log all available products in this store for mismatch detection
            $allProductsInStore = Product::where('store_id', $storeId)->get(['id', 'name', 'type']);
            $availableNames = $allProductsInStore->pluck('name')->toArray();
            
            Log::info("PRODUCT_FINDER: Database inventory for store", [
                'store_id' => $storeId,
                'total_products_in_store' => $allProductsInStore->count(),
                'available_product_names' => $availableNames,
            ]);
            
            Log::info("PRODUCT_FINDER: Executing specific search", [
                'store_id' => $storeId,
            ]);

            $products = Product::where('store_id', $storeId)
                ->where(function (Builder $builder) use ($searchTerm) {
                    $builder->where('name', 'LIKE', $searchTerm)
                        ->orWhere('description', 'LIKE', $searchTerm);
                })
                ->with('images')
                ->limit($limit)
                ->get(['id', 'name', 'price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

            Log::info("PRODUCT_FINDER: Search result count", [
                'store_id' => $storeId,
                'results_found' => $products->count(),
            ]);

            // If specific search returned nothing, fall back to full catalog
            if ($products->isEmpty()) {
                Log::warning("PRODUCT_FINDER: Specific search returned no results, falling back to full catalog", [
                    'store_id' => $storeId,
                ]);

                $products = Product::where('store_id', $storeId)
                    ->with('images')
                    ->limit($limit)
                    ->get(['id', 'name', 'price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

                Log::info("PRODUCT_FINDER: Fallback catalog result", [
                    'store_id' => $storeId,
                    'fallback_results' => $products->count(),
                ]);
            }
        }

        $hasServices = $products->where('type', 'service')->isNotEmpty();
        $hasProducts = $products->where('type', 'product')->isNotEmpty();

        Log::info("PRODUCT_FINDER: Final result", [
            'store_id' => $storeId,
            'total_products' => $products->count(),
            'has_services' => $hasServices,
            'has_products' => $hasProducts,
        ]);

        return [
            'context' => $this->formatProducts($products),
            'products' => $products,
            'hasServices' => $hasServices,
            'hasProducts' => $hasProducts,
        ];
    }

    /**
     * Find a product whose name is directly contained within the customer's
     * message. Direction matters: this checks whether the (short) product
     * name is contained in the (longer) message, the inverse of the LIKE
     * search below, which requires the entire message to appear inside the
     * product's name/description and so rarely matches real conversation.
     *
     * @param string $message
     * @param int $storeId
     * @return Product|null
     */
    public function findProductMentionedInMessage(string $message, int $storeId): ?Product
    {
        $text = trim($message);

        if ($text === '' || mb_strlen($text) < 3) {
            return null;
        }

        return Product::where('store_id', $storeId)
            ->get(['id', 'name', 'store_id', 'price'])
            ->first(fn (Product $product) => filled($product->name) && mb_stripos($text, $product->name) !== false);
    }

    /**
     * Check if the search query is generic/short and should return full catalog.
     *
     * @param string $query
     * @return bool
     */
    private function isGenericQuery(string $query): bool
    {
        // If query is very short (1-2 chars), it's too generic
        if (strlen($query) <= 2) {
            return true;
        }

        // Check against known generic terms
        foreach (self::GENERIC_SEARCH_TERMS as $term) {
            if (stripos($query, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search for products by query string in the store.
     * Legacy method for backward compatibility.
     *
     * @param string $query
     * @param int $storeId
     * @param int $limit
     * @return string Formatted product results
     */
    public function findProducts(string $query, int $storeId, int $limit = 3): string
    {
        $result = $this->findProductsWithTypes($query, $storeId, $limit);
        return $result['context'];
    }

    /**
     * Format products and services for display.
     *
     * @param Collection $products
     * @return string
     */
    private function formatProducts(Collection $products): string
    {
        if ($products->isEmpty()) {
            return "No offerings found.";
        }

        $formatted = "📦 **Available Offerings:**\n\n";

        foreach ($products as $product) {
            if ($product->type === 'service') {
                $availability = $this->getServiceAvailability($product->stock);
                $formatted .= sprintf(
                    "🔧 **%s** (Service) - $%.2f\n  📝 %s\n  %s\n",
                    $product->name,
                    $product->price,
                    $this->truncateDescription($product->description),
                    $availability
                );
            } else {
                $stockStatus = $this->getStockStatus($product->stock);
                $formatted .= sprintf(
                    "📦 **%s** (Product) - $%.2f\n  📝 %s\n  %s\n",
                    $product->name,
                    $product->price,
                    $this->truncateDescription($product->description),
                    $stockStatus
                );
            }

            // Add images if available
            if ($product->images && $product->images->count() > 0) {
                $formatted .= "  🖼️ Images:\n";
                // Sort images: primary first, then by ID
                $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
                foreach ($sortedImages as $image) {
                    $formatted .= "    - [IMG:{$image->id}] Product image\n";
                }
            } else {
                $formatted .= "  🖼️ Images: None\n";
            }

            // Add database fields for AI sales & rules
            if (!empty($product->ai_sales_strategy)) {
                $formatted .= "  💼 Sales Strategy: " . $product->ai_sales_strategy . "\n";
            }

            if (!empty($product->faq_context)) {
                $formatted .= "  ❓ Rules & FAQ: " . $product->faq_context . "\n";
            }

            if (!empty($product->required_customer_info)) {
                $formatted .= "  📋 Required Data: " . $product->required_customer_info . "\n";
            }

            $formatted .= "\n";
        }

        return $formatted;
    }

    /**
     * Get human-readable service availability status.
     *
     * @param int $stock (1 = accepting clients, 0 = fully booked)
     * @return string
     */
    private function getServiceAvailability(int $stock): string
    {
        return $stock === 1
            ? "✅ Currently Accepting New Clients"
            : "❌ Fully Booked - Not Accepting New Clients";
    }

    /**
     * Get human-readable stock status.
     *
     * @param int $stock
     * @return string
     */
    private function getStockStatus(int $stock): string
    {
        if ($stock <= 0) {
            return "❌ Out of Stock";
        } elseif ($stock < 5) {
            return "⚠️ Low Stock ({$stock} units)";
        } elseif ($stock < 20) {
            return "✅ In Stock ({$stock} units)";
        } else {
            return "✅ In Stock ({$stock} units)";
        }
    }

    /**
     * Truncate description to a reasonable length.
     *
     * @param string $description
     * @param int $maxLength
     * @return string
     */
    private function truncateDescription(string $description, int $maxLength = 80): string
    {
        if (strlen($description) <= $maxLength) {
            return $description;
        }

        return substr($description, 0, $maxLength) . '...';
    }
}
