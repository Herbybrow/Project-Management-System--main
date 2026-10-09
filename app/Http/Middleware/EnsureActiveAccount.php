<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_active === false) {
            return response()->json(['message' => 'This account is disabled. Contact ICT Support.'], 403);
        }

        return $next($request);
    }
}
