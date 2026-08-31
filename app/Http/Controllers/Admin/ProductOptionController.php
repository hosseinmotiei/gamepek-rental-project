<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

/**
 * Manages the "ادیشن / ریجن / ظرفیت"-style variant picker shown on a
 * product's page (see Product::optionGroups, gated in the storefront by
 * A group is a row like "وضعیت دستگاه"; its values are
 * the selectable pills within that row (e.g. "آمریکا", "ترکیه").
 */
class ProductOptionController extends Controller
{
    public function storeGroup(Request $request, Product $product)
    {
        abort_if(! auth()->user()->can('edit_products'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
        ]);

        $nextSort = (int) $product->optionGroups()->max('sort_order') + 1;

        $group = $product->optionGroups()->create([
            'title' => $data['title'],
            'sort_order' => $nextSort,
        ]);

        ActivityLogService::log('product_option_group.create', $product, "افزودن گروه گزینه «{$group->title}» به محصول «{$product->title_fa}»");

        return back()->with('success', 'گروه گزینه ایجاد شد.');
    }

    public function destroyGroup(Product $product, ProductOptionGroup $group)
    {
        abort_if(! auth()->user()->can('edit_products'), 403);
        abort_unless((int) $group->product_id === (int) $product->id, 404);

        ActivityLogService::log('product_option_group.delete', $product, "حذف گروه گزینه «{$group->title}» از محصول «{$product->title_fa}»");

        $group->delete();

        return back()->with('success', 'گروه گزینه حذف شد.');
    }

    public function storeValue(Request $request, Product $product, ProductOptionGroup $group)
    {
        abort_if(! auth()->user()->can('edit_products'), 403);
        abort_unless((int) $group->product_id === (int) $product->id, 404);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'is_default' => ['boolean'],
            'price_modifier' => ['nullable', 'integer'],
        ]);

        if ($request->boolean('is_default')) {
            $group->values()->update(['is_default' => false]);
        }

        $nextSort = (int) $group->values()->max('sort_order') + 1;

        $value = $group->values()->create([
            'label' => $data['label'],
            'sort_order' => $nextSort,
            'is_default' => $request->boolean('is_default'),
            'price_modifier' => $data['price_modifier'] ?? 0,
        ]);

        ActivityLogService::log('product_option_value.create', $product, "افزودن گزینه «{$value->label}» به گروه «{$group->title}» محصول «{$product->title_fa}»", [
            'price_modifier' => $value->price_modifier,
        ]);

        return back()->with('success', 'گزینه اضافه شد.');
    }

    public function destroyValue(Product $product, ProductOptionGroup $group, ProductOptionValue $value)
    {
        abort_if(! auth()->user()->can('edit_products'), 403);
        abort_unless((int) $group->product_id === (int) $product->id, 404);
        abort_unless((int) $value->option_group_id === (int) $group->id, 404);

        ActivityLogService::log('product_option_value.delete', $product, "حذف گزینه «{$value->label}» از گروه «{$group->title}» محصول «{$product->title_fa}»");

        $value->delete();

        return back()->with('success', 'گزینه حذف شد.');
    }
}
