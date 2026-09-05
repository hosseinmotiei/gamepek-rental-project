<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index()
    {
        abort_if(! auth()->user()->can('view_coupons'), 403);

        $coupons = Coupon::latest()->paginate(20);

        return view('admin.coupons.index', compact('coupons'));
    }

    public function create()
    {
        abort_if(! auth()->user()->can('create_coupons'), 403);

        return view('admin.coupons.create');
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()->can('create_coupons'), 403);

        $data = $this->validated($request);
        $data['code'] = strtoupper($data['code']);
        $data['used_count'] = 0;

        $coupon = Coupon::create($data);

        ActivityLogService::log('coupon.create', $coupon, "ایجاد کد تخفیف «{$coupon->code}»", [
            'type' => $coupon->type, 'value' => $coupon->value,
        ]);

        return redirect()->route('admin.coupons.index')->with('success', 'کد تخفیف ایجاد شد.');
    }

    public function edit(Coupon $coupon)
    {
        abort_if(! auth()->user()->can('edit_coupons'), 403);

        return view('admin.coupons.edit', compact('coupon'));
    }

    public function update(Request $request, Coupon $coupon)
    {
        abort_if(! auth()->user()->can('edit_coupons'), 403);

        $data = $this->validated($request, $coupon);
        $data['code'] = strtoupper($data['code']);

        $coupon->update($data);

        ActivityLogService::log('coupon.update', $coupon, "به‌روزرسانی کد تخفیف «{$coupon->code}»", [
            'changed_fields' => array_keys($coupon->getChanges()),
        ]);

        return redirect()->route('admin.coupons.index')->with('success', 'کد تخفیف بروزرسانی شد.');
    }

    public function destroy(Coupon $coupon)
    {
        abort_if(! auth()->user()->can('delete_coupons'), 403);

        ActivityLogService::log('coupon.delete', $coupon, "حذف کد تخفیف «{$coupon->code}»");

        $coupon->delete();

        return redirect()->route('admin.coupons.index')->with('success', 'کد تخفیف حذف شد.');
    }

    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $codeRule = 'required|string|max:50|alpha_dash|unique:coupons,code';
        if ($coupon) {
            $codeRule .= ','.$coupon->id;
        }

        $data = $request->validate([
            'code' => $codeRule,
            'type' => 'required|in:fixed,percentage',
            'value' => ['required', 'integer', 'min:1', function ($attribute, $value, $fail) use ($request) {
                if ($request->input('type') === 'percentage' && $value > 100) {
                    $fail('درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.');
                }
            }],
            'min_order_amount' => 'nullable|integer|min:0',
            'max_discount' => 'nullable|integer|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
