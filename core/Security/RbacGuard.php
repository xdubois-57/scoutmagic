<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Http\Response;

class RbacGuard
{
    /**
     * Check if the current session has sufficient access for the route.
     */
    public function check(Role $requiredRole): bool
    {
        $currentRole = Role::fromString(AuthSession::getRole());

        return $currentRole->hasAccess($requiredRole);
    }

    /**
     * Enforce access. If check() fails:
     * - If user is not authenticated → redirect to /login
     * - If user is authenticated but insufficient role → return 403 Response
     *
     * @return Response|null null if access granted, Response if denied
     */
    public function enforce(Role $requiredRole): ?Response
    {
        if ($this->check($requiredRole)) {
            return null;
        }

        if (!AuthSession::isAuthenticated()) {
            return (new Response('', 302))->setHeader('Location', '/login');
        }

        return new Response('', 403);
    }
}
