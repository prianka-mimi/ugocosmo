<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeEmailRequest;
use App\Http\Requests\ForgetPasswordRequest;
use App\Http\Requests\InitiatePasswordChangeRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\SocialLoginRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Requests\VerifyPasswordChangeOtpRequest;
use App\Http\Resources\AuthUserResource;
use App\Manager\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuthController extends Controller
{
    protected AuthManager $authManager;

    public function __construct(AuthManager $authManager)
    {
        $this->authManager = $authManager;
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $result = $this->authManager->register($request->validated());
            DB::commit();

            $this->data = AuthUserResource::make($result['user']);
            $this->status_message = 'Registration successful';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Registration error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authManager->login($request->validated());

            $this->data = [
                'token' => $result['token'],
                'user' => AuthUserResource::make($result['user']),
            ];
            $this->status_message = 'Login successful';
            $this->status_code = 200;

            return $this->JsonResponseFormat()->withCookie($result['cookie']);
        } catch (Throwable $e) {
            Log::error('Login error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function logout(): JsonResponse
    {
        try {
            $result = $this->authManager->logout();

            $this->data = [];
            $this->status_message = 'Logged out successfully';
            $this->status_code = 200;

            return $this->JsonResponseFormat()->withCookie($result['cookie']);
        } catch (Throwable $e) {
            Log::error('Logout error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function refreshToken(): JsonResponse
    {
        try {
            $result = $this->authManager->refreshToken();

            $this->data = ['token' => $result['token']];
            $this->status_message = 'Token refreshed successfully';
            $this->status_code = 200;

            return $this->JsonResponseFormat()->withCookie($result['cookie']);
        } catch (Throwable $e) {
            Log::error('Token refresh error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function forgetPassword(ForgetPasswordRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $otp = $this->authManager->forgetPassword($request->validated());
            DB::commit();

            $this->status_message = 'OTP sent for password reset';
            $this->data = ['otp' => $otp];
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Forget password error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = $this->authManager->verifyOtp($request->validated());
            DB::commit();

            // $this->data = $user;
            $this->status_message = 'OTP verified successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Verify OTP error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function resetPassword(VerifyOtpRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = $this->authManager->resetPassword($request->validated());
            DB::commit();

            // $this->data = $user;
            $this->status_message = 'Password reset successful';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Reset password error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function passwordChange(InitiatePasswordChangeRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $result = $this->authManager->passwordChange(auth()->user(), $request->validated());
            DB::commit();

            $this->data = [
                'otp' => $result['otp'],
            ];
            $this->status_message = 'Password change initiated. Verification OTP sent.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Password change error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function passwordVerifyOtp(VerifyPasswordChangeOtpRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = $this->authManager->passwordVerifyOtp(auth()->user(), $request->validated());
            DB::commit();

            $this->status_message = 'OTP verified successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Password OTP verify error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function passwordUpdate(UpdatePasswordRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $user = $this->authManager->passwordUpdate(auth()->user(), $request->validated());
            DB::commit();

            $this->status_message = 'Password changed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Password update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function emailChange(ChangeEmailRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $result = $this->authManager->emailChange(auth()->user(), $request->validated());
            DB::commit();

            $this->data = [
                'otp' => $result['otp'],
            ];
            $this->status_message = 'Email change initiated. Verification OTP sent.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Email change error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function resendOtp(ForgetPasswordRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $otp = $this->authManager->resendOtp($request->validated());
            DB::commit();

            $this->status_message = 'OTP resent successfully';
            $this->data = [
                'otp' => $otp,
            ];
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Resend OTP error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function socialLogin(SocialLoginRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $result = $this->authManager->socialLogin($request->validated());
            DB::commit();

            $this->data = [
                'token' => $result['token'],
                'user' => AuthUserResource::make($result['user']),
            ];
            $this->status_message = 'Social login successful';
            $this->status_code = 200;

            return $this->JsonResponseFormat()->withCookie($result['cookie']);
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Social login error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
