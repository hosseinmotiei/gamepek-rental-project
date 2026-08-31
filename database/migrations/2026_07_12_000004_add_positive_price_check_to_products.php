<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * BUG-090: `products.price`/`sale_price` are `unsignedBigInteger`,
     * which already blocks negative values at the schema layer, but
     * nothing blocks `price = 0`. The application-level gap is closed by
     * `Product::getEffectivePriceAttribute()` (returns null, not 0, for a
     * non-positive price, funneling into the existing null-price
     * rejection path in CartService/OrderService). This migration adds
     * the schema-layer backstop: CHECK constraints requiring `price > 0`
     * and `sale_price IS NULL OR sale_price > 0`. Supported by MariaDB
     * 10.2.1+ and MySQL 8.0.16+ (enforced CHECK constraints).
     *
     * No data is modified. Do not run until a zero/negative-price orphan
     * precheck against the target database confirms no existing row
     * would violate the constraint.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_positive_check CHECK (price > 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sale_price_positive_check CHECK (sale_price IS NULL OR sale_price > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_price_positive_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_sale_price_positive_check');
    }
};
