<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (blank($token)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // កែប្រែត្រង់នេះ៖ លុប ->where('is_admin', true) ចោល
        $user = User::query()
            ->where('admin_api_token_hash', hash('sha256', $token))
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}