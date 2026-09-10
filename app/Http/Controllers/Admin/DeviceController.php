<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceOwnership;
use App\Enums\DeviceState;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Owner;
use App\Models\Product;
use App\Services\Audit\AuditLogger;
use App\Services\Rental\DeviceRegistrationService;
use Illuminate\Http\Request;

/**
 * Admin visibility and review for the mixed fleet.
 *
 * Permission-checked explicitly on every action rather than relying on the
 * Gate::before admin bypass -- CLAUDE.md requires admin screens to check and
 * audit in their own right.
 *
 * Scope is deliberately narrow: list, inspect, approve, reject, and register
 * GamePek's own stock. Pickup, inspection, delivery, return and settlement
 * screens belong to the operations phase.
 */
class DeviceController extends Controller
{
    public function __construct(private DeviceRegistrationService $devices) {}

    public function index(Request $request)
    {
        abort_if(! $request->user()->can('view_devices'), 403);

        $devices = Device::with(['product', 'owner.user'])
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->get('state')))
            ->when($request->filled('ownership'), fn ($q) => $q->where('ownership', $request->get('ownership')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = Device::normalizeSerial((string) $request->get('q'));
                $q->where('serial_normalized', 'like', '%'.$needle.'%');
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.devices.index', [
            'devices' => $devices,
            'states' => DeviceState::cases(),
            'ownerships' => DeviceOwnership::cases(),
        ]);
    }

    public function show(Request $request, Device $device)
    {
        abort_if(! $request->user()->can('view_devices'), 403);

        $device->loadMissing(['product', 'owner.user', 'approvedBy']);

        // Reading a device's full record exposes its serial and its owner's
        // identity, so the read itself is audited -- the same rule the
        // verification screens follow.
        AuditLogger::log(
            action: 'device.viewed',
            resourceType: 'Device',
            resourceId: $device->id,
            context: ['serial_mask' => $device->maskedSerial(), 'owner_id' => $device->owner_id],
            actor: $request->user(),
        );

        return view('admin.devices.show', compact('device'));
    }

    public function approve(Request $request, Device $device)
    {
        abort_if(! $request->user()->can('manage_devices'), 403);

        try {
            $this->devices->approve($device, $request->user(), $request->input('note'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'دستگاه تأیید شد.');
    }

    public function reject(Request $request, Device $device)
    {
        abort_if(! $request->user()->can('manage_devices'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], ['reason.required' => 'ثبت دلیل رد دستگاه الزامی است.']);

        try {
            $this->devices->reject($device, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'دستگاه رد شد.');
    }

    /** Register a device GamePek itself owns. No owner account involved. */
    public function storeGamePekDevice(Request $request)
    {
        abort_if(! $request->user()->can('manage_devices'), 403);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'serial_number' => ['required', 'string', 'max:120'],
            'condition' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'product_id.required' => 'انتخاب مدل دستگاه الزامی است.',
            'serial_number.required' => 'وارد کردن شماره سریال دستگاه الزامی است.',
        ]);

        try {
            $device = $this->devices->registerForGamePek(
                Product::findOrFail($data['product_id']),
                $data['serial_number'],
                ['condition' => $data['condition'] ?? null, 'notes' => $data['notes'] ?? null],
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.devices.show', $device)
            ->with('success', 'دستگاه گیم‌پک ثبت شد.');
    }

    public function owners(Request $request)
    {
        abort_if(! $request->user()->can('view_owners'), 403);

        $owners = Owner::with('user')
            ->withCount('devices')
            ->latest('id')
            ->paginate(20);

        return view('admin.owners.index', compact('owners'));
    }

    public function showOwner(Request $request, Owner $owner)
    {
        abort_if(! $request->user()->can('view_owners'), 403);

        $owner->loadMissing('user');
        $devices = $owner->devices()->with('product')->latest('id')->get();

        return view('admin.owners.show', compact('owner', 'devices'));
    }
}
