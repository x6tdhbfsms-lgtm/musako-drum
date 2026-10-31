<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SelectPortalGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $portal = $request->is('staff', 'staff/*') ? 'staff'
            : ($request->is('student', 'student/*') ? 'student' : 'web');
        if ($request->is('logout') && in_array($request->input('portal'), ['staff', 'student'], true)) {
            $portal = $request->input('portal');
        }
        Auth::shouldUse($portal);
        if ($portal !== 'web') {
            $guard = Auth::guard($portal);
            if (! $request->session()->has($guard->getName())) {
                $guard->forgetUser();
            }
            $user = $guard->user();
            $passwordHash = $request->session()->get('portal_password_hash_'.$portal);
            if ($user && $passwordHash !== null && (! hash_equals($passwordHash, $user->getAuthPassword())
                || $user->account_status !== AccountStatus::Active)) {
                $guard->logout();
                $request->session()->forget('portal_password_hash_'.$portal);
                if (Auth::guard('web')->id() === $user->id) {
                    Auth::guard('web')->logout();
                }
            }
            if (! $guard->check() && Auth::guard('web')->check()) {
                $legacyUser = Auth::guard('web')->user();
                $legacyPortal = $legacyUser->role === UserRole::Student ? 'student' : 'staff';
                abort_unless($portal === $legacyPortal, 403);
                $guard->setUser($legacyUser);
            }
        }

        return $next($request);
    }
}
