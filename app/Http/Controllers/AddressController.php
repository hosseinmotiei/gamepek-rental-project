<?php

namespace App\Http\Controllers;

use App\Http\Requests\Address\StoreAddressRequest;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AddressController extends Controller
{
    public function index(): View
    {
        $addresses = auth()->user()->addresses()->orderByDesc('is_default')->get();

        return view('profile.addresses', compact('addresses'));
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $user = auth()->user();

        $address = DB::transaction(function () use ($request, $user) {
            // orderBy('id') keeps lock-acquisition order identical across
            // concurrent requests, avoiding lock-order deadlocks.
            $user->addresses()->orderBy('id')->lockForUpdate()->get();

            $isDefault = $request->boolean('is_default') || $user->addresses()->count() === 0;

            if ($isDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            $payload = array_merge($request->validated(), [
                'province' => 'تهران',
                'city' => 'تهران',
                'is_default' => $isDefault,
            ]);

            return $user->addresses()->create($payload);
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'آدرس با موفقیت ذخیره شد.',
            'address' => $address,
        ]);
    }

    public function update(StoreAddressRequest $request, Address $address): JsonResponse
    {
        $this->authorize('update', $address);
        $user = auth()->user();

        DB::transaction(function () use ($request, $user, $address) {
            // orderBy('id') keeps lock-acquisition order identical across
            // concurrent requests, avoiding lock-order deadlocks.
            $user->addresses()->orderBy('id')->lockForUpdate()->get();

            if ($request->boolean('is_default')) {
                $user->addresses()->update(['is_default' => false]);
            }

            $payload = array_merge($request->validated(), [
                'province' => 'تهران',
                'city' => 'تهران',
            ]);

            $address->update($payload);
        }, 3);

        return response()->json(['success' => true, 'message' => 'آدرس ویرایش شد.', 'address' => $address->fresh()]);
    }

    public function destroy(Address $address): JsonResponse
    {
        $this->authorize('delete', $address);
        $address->delete();

        return response()->json(['success' => true, 'message' => 'آدرس حذف شد.']);
    }

    public function setDefault(Address $address): JsonResponse
    {
        $this->authorize('update', $address);
        $user = auth()->user();

        DB::transaction(function () use ($user, $address) {
            // orderBy('id') keeps lock-acquisition order identical across
            // concurrent requests, avoiding lock-order deadlocks.
            $user->addresses()->orderBy('id')->lockForUpdate()->get();
            $user->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        }, 3);

        return response()->json(['success' => true, 'message' => 'آدرس پیش‌فرض تغییر یافت.']);
    }
}
