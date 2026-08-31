<?php

namespace App\Services;

use App\Models\Product;

class InventoryService
{
    /**
     * Sprint 2 Task 4: extracted verbatim from OrderService::markAsPaid()'s
     * physical-stock-check block. Aggregates required physical/mixed
     * quantity per product across the given order items, locks each
     * distinct product row in deterministic ascending-ID order (to avoid a
     * deadlock risk against another order referencing the same products),
     * and throws if any locked product's stock is insufficient.
     *
     * Must be called from within the caller's own transaction — this method
     * does not open one itself, so the caller remains the transaction owner.
     *
     * @return array<int, Product> locked Product models keyed by product_id
     */
    public function lockAndValidateStock(iterable $items): array
    {
        $requiredQtyByProductId = [];
        foreach ($items as $item) {
            if ($item->product) {
                $requiredQtyByProductId[$item->product_id] =
                    ($requiredQtyByProductId[$item->product_id] ?? 0) + $item->quantity;
            }
        }

        $lockedProducts = [];
        if (!empty($requiredQtyByProductId)) {
            $productIds = array_keys($requiredQtyByProductId);
            sort($productIds);

            foreach ($productIds as $productId) {
                $product = Product::where('id', $productId)->lockForUpdate()->first();

                if (!$product || $product->stock_quantity < $requiredQtyByProductId[$productId]) {
                    throw new \Exception('موجودی این محصول کافی نیست.', 422);
                }

                $lockedProducts[$productId] = $product;
            }
        }

        return $lockedProducts;
    }

    /**
     * Sprint 2 Task 4: extracted verbatim from OrderService::markAsPaid()'s
     * per-item physical-stock-decrement block. Operates on a Product row
     * already locked and validated by lockAndValidateStock() — never fetches
     * or locks a row itself.
     */
    public function decrementStock(Product $lockedProduct, int $quantity): void
    {
        $lockedProduct->decrement('stock_quantity', $quantity);
        $lockedProduct->increment('sales_count', $quantity);

        if ($lockedProduct->stock_quantity <= 0) {
            $lockedProduct->update(['stock_status' => 'out_of_stock']);
        }
    }

    /**
     * Sprint 2 Task 4: extracted verbatim from OrderService::cancelOrder()'s
     * physical-stock-restore block (BUG-007: restores stock decremented by
     * markAsPaid() when a previously-paid order is cancelled). Aggregates
     * required physical/mixed quantity per product across the given order
     * items, locks each distinct product row in deterministic ascending-ID
     * order, and restores it, reconciling stock_status back to in_stock if
     * it had been depleted.
     *
     * Must be called from within the caller's own transaction.
     */
    public function restoreStock(iterable $items): void
    {
        $requiredQtyByProductId = [];
        foreach ($items as $item) {
            if ($item->product) {
                $requiredQtyByProductId[$item->product_id] =
                    ($requiredQtyByProductId[$item->product_id] ?? 0) + $item->quantity;
            }
        }

        if (empty($requiredQtyByProductId)) {
            return;
        }

        $productIds = array_keys($requiredQtyByProductId);
        sort($productIds);

        foreach ($productIds as $productId) {
            $product = Product::where('id', $productId)->lockForUpdate()->first();

            if (!$product) {
                continue;
            }

            $product->increment('stock_quantity', $requiredQtyByProductId[$productId]);

            if ($product->stock_quantity > 0 && $product->stock_status === 'out_of_stock') {
                $product->update(['stock_status' => 'in_stock']);
            }
        }
    }

    /**
     * Cheap pre-flight availability check, called from CartService::addItem()
     * and ::updateItem().
     *
     * This is a read-only, unlocked check against the caller's already-loaded
     * $product -- it does not lock or re-fetch the row, and is not a
     * replacement for OrderService::markAsPaid()'s authoritative locked
     * check at payment time.
     */
    public function assertPhysicalStockAvailable(Product $product, int $requestedQuantity): void
    {
        if ($requestedQuantity > $product->stock_quantity) {
            throw new \Exception('موجودی این محصول کافی نیست.', 422);
        }
    }
}
