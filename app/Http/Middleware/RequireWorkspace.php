<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Sendportal\Base\Facades\Sendportal;

class RequireWorkspace
{
    /**
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response|mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function handle($request, Closure $next)
    {
        try {
            Sendportal::currentWorkspaceId();
        } catch (RuntimeException $exception) {
            $user = Auth::user();

            if ($user && method_exists($user, 'workspaces')) {
                $workspace = $user->workspaces()->first();

                if ($workspace && method_exists($user, 'switchToWorkspace')) {
                    $user->switchToWorkspace($workspace);

                    return $next($request);
                }
            }

            if ($request->is('api/*')) {
                return response('Unauthorized.', 401);
            }

            abort(404);
        }

        return $next($request);
    }
}
