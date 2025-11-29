<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GroupAccessMiddleware
{
    /**
     * Ensure user can only access their own group's data
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Super admin can access everything
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        // Extract group_id from route parameters
        $routeGroupId = $request->route('groupId');
        
        if ($routeGroupId && $user->group_id != $routeGroupId) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You can only access your own group data'
            ], 403);
        }

        return $next($request);
    }
}
