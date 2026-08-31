<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteProfileRequest;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Services\AuthService;
use App\Services\UserActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function showLogin(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('profile.index');
        }

        return view('auth.login');
    }

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        try {
            $otp = $this->authService->sendOtp($request->mobile);
            $payload = [
                'success' => true,
                'message' => 'کد تایید ارسال شد.',
            ];

            if ($this->authService->canShowOtpInDevelopment()) {
                $payload['dev_otp'] = $otp;
                session()->flash('dev_otp_message', 'کد تست ورود: '.$otp);
            }

            return response()->json($payload);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 422);
        }
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $mobile = $request->mobile;
        $code = $request->otp;

        if (! $this->authService->verifyOtp($mobile, $code)) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید نامعتبر است.',
            ], 422);
        }

        try {
            ['user' => $user, 'is_new' => $isNew] = $this->authService->findOrCreateUser($mobile);
            $this->authService->loginUser($user);

            UserActivityLogService::log(
                $isNew ? 'user.register' : 'user.login',
                $user,
                description: $isNew ? 'ثبت‌نام کاربر جدید' : 'ورود کاربر با کد یکبارمصرف'
            );

            return response()->json([
                'success' => true,
                'message' => 'ورود با موفقیت انجام شد.',
                'is_new_user' => $isNew,
                'needs_name' => empty($user->full_name),
                'redirect_url' => $isNew && empty($user->full_name)
                    ? route('auth.complete-profile')
                    : route('profile.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function showCompleteProfile(): View|RedirectResponse
    {
        if (! auth()->check()) {
            return redirect()->route('auth.login');
        }
        if (auth()->user()->full_name) {
            return redirect()->route('profile.index');
        }

        return view('auth.complete-profile');
    }

    public function completeProfile(CompleteProfileRequest $request): JsonResponse
    {
        $this->authService->completeProfile(auth()->user(), $request->full_name, $request->email);

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات حساب کاربری تکمیل شد.',
            'redirect_url' => route('profile.index'),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $this->authService->logout();

        UserActivityLogService::log('user.logout', $user, description: 'خروج کاربر');

        return redirect()->route('home');
    }
}
