<?php
namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteParameterTest extends TestCase
{
    /** Any route id that is not numeric must fail routing (404) instead of hitting Postgres as a bigint (500). */
    public function test_every_id_parameter_rejects_non_numeric_values(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $params = array_diff($route->parameterNames(), ['token']);
            if (!$params) { continue; }
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $m[1] === 'token' ? 'tok' : "abc'", $route->uri());
            $method = $route->methods()[0];
            $this->assertFalse($route->matches(Request::create('/'.$uri, $method), false), "{$method} {$route->uri()} accepts a non-numeric id");
            $checked++;
        }
        $this->assertGreaterThan(20, $checked);
    }
}
