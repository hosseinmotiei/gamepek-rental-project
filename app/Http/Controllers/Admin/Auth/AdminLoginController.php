<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminLoginController extends Controller
{
    public function showLogin()
    {
        if (Auth::check() && Auth::user()->hasAnyRole(config('rental.admin.roles'))) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'ایمیل الزامی است.',
            'email.email' => 'فرمت ایمیل صحیح نیست.',
            'password.required' => 'رمز عبور الزامی است.',
        ]);

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'ایمیل یا رمز عبور اشتباه است.'])
                ->withInput($request->only('email'));
        }

        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::logout();

            return back()
                ->withErrors(['email' => 'حساب کاربری شما مسدود شده است.'])
                ->withInput($request->only('email'));
        }

        if (! $user->hasAnyRole(config('rental.admin.roles'))) {
            Auth::logout();

            return back()
                ->withErrors(['email' => 'شما دسترسی به پنل مدیریت ندارید.'])
                ->withInput($request->only('email'));
        }

        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'با موفقیت خارج شدید.');
    }
}
