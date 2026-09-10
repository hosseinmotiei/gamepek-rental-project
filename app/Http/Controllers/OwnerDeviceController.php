<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Product;
use App\Services\Rental\DeviceRegistrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * The owner's own side of the fleet: their profile and their devices.
 *
 * Thin by design -- every rule lives in DeviceRegistrationService and the
 * policies. Note what is NOT accepted from a request anywhere here: ownership,
 * owner_id, review state, approval, or price. An owner cannot hand their device
 * to someone else, approve it, or touch GamePek's pricing by posting a field.
 */
class OwnerDeviceController extends Controller
{
    public function __construct(private DeviceRegistrationService $devices) {}

    /** Owner entry point: become an owner, or see the dashboard. */
    public function dashboard(Request $request)
    {
        $owner = $request->user()->owner;

        if (! $owner) {
            return view('owner.start');
        }

        $this->authorize('view', $owner);

        $devices = Device::with('product')
            ->forOwner($owner->id)
            ->latest('id')
            ->paginate(15);

        return view('owner.dashboard', compact('owner', 'devices'));
    }

    /** Opt in to becoming a device owner. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        $this->devices->ensureOwnerProfile($request->user(), $data['display_name'] ?? null);

        return redirect()->route('owner.dashboard')
            ->with('success', 'حساب مالک شما ایجاد شد. اکنون می‌توانید دستگاه ثبت کنید.');
    }

    public function create(Request $request)
    {
        $owner = $this->requireOwner($request);

        $products = Product::active()
            ->whereNotNull('attributes->_rental')
            ->orderBy('title_fa')
            ->get(['id', 'title_fa']);

        return view('owner.devices.create', compact('owner', 'products'));
    }

    public function storeDevice(Request $request)
    {
        $owner = $this->requireOwner($request);

        // `product_id` and `serial_number` are the only structural inputs.
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'serial_number' => ['required', 'string', 'max:120'],
            'condition' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'product_id.required' => 'انتخاب مدل دستگاه الزامی است.',
            'serial_number.required' => 'وارد کردن شماره سریال دستگاه الزامی است.',
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            $device = $this->devices->registerForOwner(
                $owner,
                $product,
                $data['serial_number'],
                ['condition' => $data['condition'] ?? null, 'notes' => $data['notes'] ?? null],
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('owner.devices.show', $device)
            ->with('success', 'دستگاه شما ثبت شد و در انتظار بررسی کارشناسان است.');
    }

    public function show(Request $request, Device $device)
    {
        $this->authorize('view', $device);

        $device->loadMissing(['product', 'owner']);

        return view('owner.devices.show', compact('device'));
    }

    /**
     * Take a device out of the fleet.
     *
     * POLICY GATE: a penalty is understood to apply and is UNDEFINED, so
     * nothing is charged here. The action is recorded and audited only.
     */
    public function disable(Request $request, Device $device)
    {
        $this->authorize('disable', $device);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->devices->disable($device, $request->user(), $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'دستگاه غیرفعال شد.');
    }

    /** @throws AuthorizationException */
    private function requireOwner(Request $request)
    {
        $owner = $request->user()->owner;

        abort_if($owner === null, 403, 'برای ثبت دستگاه ابتدا باید حساب مالک ایجاد کنید.');

        $this->authorize('manageDevices', $owner);

        return $owner;
    }
}
