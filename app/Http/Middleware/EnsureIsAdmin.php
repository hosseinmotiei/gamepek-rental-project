<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return redirect()->route('admin.login')->with('error', 'برای دسترسی به پنل مدیریت وارد شوید.');
        }

        $user = Auth::user();

        if (!$user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            return redirect()->route('admin.login')->withErrors(['email' => 'حساب کاربری شما مسدود شده است.']);
        }

        if (!$user->hasAnyRole(config('rental.admin.roles'))) {
            Auth::logout();
            $request->session()->invalidate();
            return redirect()->route('admin.login')->withErrors(['email' => 'شما دسترسی به پنل مدیریت ندارید.']);
        }

        return $next($request);
    }
}
