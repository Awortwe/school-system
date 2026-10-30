<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.change*') && ! $request->routeIs('logout')) {
            return redirect()->route('password.change')
                ->with('warning', 'You must change your initial password before continuing.');
        }

        return $next($request);
    }
}
