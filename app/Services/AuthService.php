<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    public function __construct(private OtpService $otpService)
    {
    }

    public function findOrCreateUser(string $mobile): array
    {
        $user = User::where('mobile', $mobile)->first();
        $isNewUser = false;

        if (!$user) {
            $user = User::create([
                'mobile' => $mobile,
                'status' => 'active',
            ]);
            $isNewUser = true;
        }

        if ($user->status === 'blocked') {
            throw new \Exception('حساب کاربری شما مسدود شده است.', 403);
        }

        return ['user' => $user, 'is_new' => $isNewUser];
    }

    public function loginUser(User $user): void
    {
        Auth::login($user, true);
        $user->update(['last_login_at' => now()]);
    }

    public function completeProfile(User $user, string $fullName, ?string $email = null): User
    {
        $data = ['full_name' => $fullName];

        if ($email !== null) {
            $data['email'] = $email;
        }

        $user->update($data);
        return $user->fresh();
    }

    public function logout(): void
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    public function sendOtp(string $mobile): string
    {
        return $this->otpService->generateAndSend($mobile);
    }

    public function canShowOtpInDevelopment(): bool
    {
        return $this->otpService->canShowOtpInDevelopment();
    }

    public function verifyOtp(string $mobile, string $code): bool
    {
        return $this->otpService->verify($mobile, $code);
    }
}
