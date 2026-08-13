<?php

namespace ParsDesign\Grow4MeDataConnector\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('grow4me-connector.token');
        $providedToken = $request->bearerToken();

        if (! is_string($configuredToken)
            || $configuredToken === ''
            || ! is_string($providedToken)
            || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
