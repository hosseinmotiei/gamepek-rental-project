<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        abort_if(! auth()->user()->can('view_users'), 403);

        $query = User::withCount('orders')->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->paginate(20)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user)
    {
        abort_if(! auth()->user()->can('view_users'), 403);

        $user->load(['addresses', 'roles']);

        // `wishlists`, `reviews` and `questions` are Store features that were
        // pruned from Rental -- the relations do not exist on User, so counting
        // them threw BadMethodCallException and this page returned 500 for
        // every user. Replaced with relations Rental actually has; the Store
        // features are deliberately NOT reintroduced.
        $user->loadCount(['orders', 'rentalApplications', 'conversations']);

        $orders = collect();
        if (auth()->user()->can('view_user_orders')) {
            $orders = $user->orders()
                ->with('latestTransaction')
                ->latest()
                ->limit(15)
                ->get();
        }

        $totalSpent = $user->orders()
            ->whereIn('status', ['paid', 'processing', 'shipped', 'delivered'])
            ->sum('total');

        $availableRoles = Role::whereIn('name', config('rental.admin.roles'))->orderBy('name')->get();

        $activityLogs = collect();
        if (auth()->user()->can('view_users')) {
            $activityLogs = UserActivityLog::where('user_id', $user->id)
                ->latest()
                ->limit(30)
                ->get();
        }

        return view('admin.users.show', compact('user', 'orders', 'totalSpent', 'availableRoles', 'activityLogs'));
    }

    /**
     * Grants or revokes admin-panel access by assigning/clearing a Spatie
     * role. Restricted to super_admin -- any lower admin role must not be
     * able to create new admins (privilege escalation).
     */
    public function updateRole(Request $request, User $user)
    {
        abort_unless(auth()->user()->hasRole('super_admin'), 403);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'نمی‌توانید نقش خودتان را تغییر دهید.');
        }

        $allowedRoles = config('rental.admin.roles');

        $data = $request->validate([
            'role' => 'nullable|string|in:'.implode(',', $allowedRoles),
        ]);

        $before = $user->roles->pluck('name')->implode(', ') ?: 'بدون دسترسی ادمین';

        if (empty($data['role'])) {
            $user->syncRoles([]);
        } else {
            $user->syncRoles([$data['role']]);
        }

        ActivityLogService::log('user.update_role', $user, "تغییر نقش کاربر «{$user->full_name}»", [
            'before' => $before,
            'after' => $data['role'] ?? 'بدون دسترسی ادمین',
        ]);

        return back()->with('success', 'نقش کاربر بروزرسانی شد.');
    }

    /**
     * Sets/changes a user's email + password so they can log into the admin
     * panel (AdminLoginController authenticates with email+password, but
     * most storefront customers only ever sign up via mobile+OTP and have
     * neither). Restricted to super_admin, same as role assignment -- this
     * is effectively handing out login credentials for another account.
     */
    public function updateCredentials(Request $request, User $user)
    {
        abort_unless(auth()->user()->hasRole('super_admin'), 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.required' => 'ایمیل الزامی است.',
            'email.email' => 'فرمت ایمیل صحیح نیست.',
            'email.unique' => 'این ایمیل قبلاً برای کاربر دیگری ثبت شده.',
            'password.required' => 'رمز عبور الزامی است.',
            'password.min' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
        ]);

        $user->update([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        ActivityLogService::log('user.update_credentials', $user, "تنظیم ایمیل/رمز ورود پنل ادمین برای «{$user->full_name}»");

        return back()->with('success', 'ایمیل و رمز عبور ورود به پنل ادمین ثبت شد.');
    }

    public function toggleBlock(User $user)
    {
        abort_if(! auth()->user()->can('block_users'), 403);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'نمی‌توانید حساب خودتان را مسدود کنید.');
        }

        $beforeStatus = $user->status;
        $newStatus = $user->status === 'active' ? 'blocked' : 'active';
        $user->update(['status' => $newStatus]);

        ActivityLogService::log('user.toggle_status', $user, "تغییر وضعیت کاربر «{$user->full_name}»", [
            'before' => $beforeStatus, 'after' => $newStatus,
        ]);

        $message = $newStatus === 'active' ? 'کاربر فعال شد.' : 'کاربر مسدود شد.';

        return back()->with('success', $message);
    }
}
