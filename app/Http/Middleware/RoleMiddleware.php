<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request and verify the authenticated user holds an allowed role.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            if ($request->expectsJson()) {
                abort(401, 'Silakan masuk terlebih dahulu.');
            }

            return redirect()->route('login');
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Anda tidak memiliki hak akses untuk halaman atau aksi ini.');
        }

        return $next($request);
    }
}
