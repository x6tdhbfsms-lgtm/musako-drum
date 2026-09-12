<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $credentials['account_status'] = AccountStatus::Active->value;
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $request->session()->regenerate();

        return $request->expectsJson()
            ? response()->json(['user' => $request->user()->only(['id', 'name', 'email', 'role'])])
            : redirect()->intended('/');
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json([], 204) : redirect('/login');
    }
}
