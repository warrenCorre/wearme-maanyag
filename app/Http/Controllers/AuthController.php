<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_LOCKOUT_SECONDS = 60;

    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function showForgotPasswordForm()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLinkEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($validated);

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', 'If an account with that email exists, a password reset link has been sent.');
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors([
                'email' => 'A password reset link was recently requested. Please wait before requesting another.',
            ])->withInput();
        }

        return back()->with('status', 'If an account with that email exists, a password reset link has been sent.');
    }

    public function showResetPasswordForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Your password has been reset successfully.');
        }

        $message = $status === Password::INVALID_TOKEN
            ? 'This password reset link is invalid or expired.'
            : 'Unable to reset the password with the provided information.';

        return back()->withErrors([
            'email' => $message,
        ])->withInput($request->only('email'));
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttleKey = Str::lower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => "Too many failed login attempts. Try again in {$seconds} seconds.",
            ])->onlyInput('email');
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            RateLimiter::hit($throttleKey, self::LOGIN_LOCKOUT_SECONDS);

            return back()->withErrors([
                'email' => 'Invalid email or password.',
            ])->onlyInput('email');
        }

        if ($user->status !== 'active') {
            RateLimiter::hit($throttleKey, self::LOGIN_LOCKOUT_SECONDS);

            return back()->withErrors([
                'email' => 'Your account is inactive.',
            ])->onlyInput('email');
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOGIN_LOCKOUT_SECONDS);

            return back()->withErrors([
                'email' => 'Invalid email or password.',
            ])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user);
        $request->session()->regenerate();

        if ($user->role === 'store_owner') {
            return redirect('/dashboard');
        }

        if ($user->role === 'cashier') {
            return redirect('/sales');
        }

        Auth::logout();

        return back()->withErrors([
            'email' => 'Unauthorized account role.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
