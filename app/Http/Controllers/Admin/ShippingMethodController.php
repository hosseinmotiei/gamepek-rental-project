<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

class ShippingMethodController extends Controller
{
    public function index()
    {
        abort_if(!auth()->user()->can('view_shipping_methods'), 403);

        $methods = ShippingMethod::orderBy('sort_order')->orderBy('id')->get();

        return view('admin.shipping-methods.index', compact('methods'));
    }

    public function create()
    {
        abort_if(!auth()->user()->can('create_shipping_methods'), 403);

        return view('admin.shipping-methods.create');
    }

    public function store(Request $request)
    {
        abort_if(!auth()->user()->can('create_shipping_methods'), 403);

        $data = $this->validated($request);

        $shippingMethod = ShippingMethod::create($data);

        ActivityLogService::log('shipping_method.create', $shippingMethod, "ایجاد روش ارسال «{$shippingMethod->title_fa}»", [
            'base_cost' => $shippingMethod->base_cost,
        ]);

        return redirect()->route('admin.shipping-methods.index')->with('success', 'روش ارسال ایجاد شد.');
    }

    public function edit(ShippingMethod $shippingMethod)
    {
        abort_if(!auth()->user()->can('edit_shipping_methods'), 403);

        return view('admin.shipping-methods.edit', compact('shippingMethod'));
    }

    public function update(Request $request, ShippingMethod $shippingMethod)
    {
        abort_if(!auth()->user()->can('edit_shipping_methods'), 403);

        $data = $this->validated($request);
        $previousCost = $shippingMethod->base_cost;

        $shippingMethod->update($data);

        ActivityLogService::log('shipping_method.update', $shippingMethod, "به‌روزرسانی روش ارسال «{$shippingMethod->title_fa}»", [
            'changed_fields' => array_keys($shippingMethod->getChanges()),
            'base_cost_before' => $previousCost,
            'base_cost_after' => $shippingMethod->base_cost,
        ]);

        return redirect()->route('admin.shipping-methods.index')->with('success', 'روش ارسال بروزرسانی شد.');
    }

    public function destroy(ShippingMethod $shippingMethod)
    {
        abort_if(!auth()->user()->can('delete_shipping_methods'), 403);

        ActivityLogService::log('shipping_method.delete', $shippingMethod, "حذف روش ارسال «{$shippingMethod->title_fa}»");

        $shippingMethod->delete();

        return redirect()->route('admin.shipping-methods.index')->with('success', 'روش ارسال حذف شد.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title'                   => 'required|string|max:255',
            'title_fa'                => 'required|string|max:255',
            'description'             => 'nullable|string|max:1000',
            'base_cost'               => 'required|integer|min:0',
            'city'                    => 'nullable|string|max:255',
            'estimated_delivery_text' => 'nullable|string|max:255',
            'min_days'                => 'required|integer|min:0|max:255',
            'max_days'                => 'required|integer|min:0|max:255|gte:min_days',
            'is_active'               => 'boolean',
            'sort_order'              => 'required|integer|min:0',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['city'] = $data['city'] ?: null;

        return $data;
    }
}
