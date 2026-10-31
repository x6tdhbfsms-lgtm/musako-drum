<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
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
        $identifier = $request->input('identifier', $request->input('email', ''));
        $request->merge(['identifier' => is_string($identifier) ? mb_strtolower(trim($identifier)) : $identifier]);
        $input = $request->validate(['identifier' => ['required', 'string', 'max:254'], 'password' => ['required', 'string']]);
        $staffLogin = str_contains($input['identifier'], '@');
        $credentials = [
            $staffLogin ? 'email' : 'login_name' => $input['identifier'],
            'password' => $input['password'],
            'role' => $staffLogin ? [UserRole::Admin->value, UserRole::Teacher->value] : UserRole::Student->value,
        ];
        $credentials['account_status'] = AccountStatus::Active->value;
        $otherPortal = $staffLogin ? 'student' : 'staff';
        $preserveToken = Auth::guard($otherPortal)->check();
        $csrfToken = $request->session()->token();
        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['identifier' => 'ログイン名またはパスワードが正しくありません。']);
        }
        $portal = $request->user()->role === UserRole::Student ? 'student' : 'staff';
        Auth::guard($portal)->login($request->user(), $request->boolean('remember'));
        $request->session()->put('portal_password_hash_'.$portal, $request->user()->getAuthPassword());
        $request->session()->regenerate();
        if ($preserveToken) {
            $request->session()->put('_token', $csrfToken);
        }

        if ($request->expectsJson()) {
            return response()->json(['user' => $request->user()->only(['id', 'name', 'login_name', 'role'])]);
        }

        $dashboardRoute = $request->user()->role === UserRole::Student
            ? 'student.dashboard'
            : 'staff.dashboard';

        return redirect()->route($dashboardRoute);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $portal = $request->input('portal');
        if (! in_array($portal, ['staff', 'student'], true)) {
            $portal = $user->role === UserRole::Student ? 'student' : 'staff';
        }
        Auth::guard($portal)->logout();
        $request->session()->forget('portal_password_hash_'.$portal);
        if (Auth::guard('web')->id() === $user->id) {
            Auth::guard('web')->logout();
        }
        if (Auth::guard('staff')->check() || Auth::guard('student')->check()) {
            $request->session()->migrate(true);
        } else {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $request->expectsJson() ? response()->json([], 204) : redirect('/login');
    }
}
