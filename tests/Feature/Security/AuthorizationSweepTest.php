<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TenantTestCase;

/**
 * Milestone 8 authorization review: a signed-in school user with no role
 * reaches no staff screen and can change nothing.
 */
class AuthorizationSweepTest extends TenantTestCase
{
    /** Screens every signed-in staff user may open (they show only the user's own or school-wide public data). */
    private const OPEN_TO_ANY_STAFF = ['app/dashboard', 'app/calendar'];

    public function test_a_user_without_roles_is_refused_everywhere_in_the_staff_area(): void
    {
        $nobody = User::factory()->create(); // signed in, no role, no permission
        $reached = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'app/') || ! in_array('tenant', $route->gatherMiddleware(), true) || $route->parameterNames() !== []) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->actingAs($nobody)->call($method, '/'.$uri, ['_token' => csrf_token()])->getStatusCode();
                $checked++;

                if ($status !== 403 && ! ($method === 'GET' && in_array($uri, self::OPEN_TO_ANY_STAFF, true))) {
                    $reached[] = "{$method} /{$uri} → {$status}";
                }
            }
        }

        $this->assertGreaterThan(60, $checked);
        $this->assertSame([], $reached);
    }
}
