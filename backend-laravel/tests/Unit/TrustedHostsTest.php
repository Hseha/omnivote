<?php

namespace Tests\Unit;

use App\Support\TrustedHosts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Middleware\TrustHosts;
use Tests\TestCase;

class TrustedHostsTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustHosts::flushState();
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_callable_host_patterns_are_resolved_and_accept_the_application_host(): void
    {
        config([
            'app.url' => 'https://debian.tail7e9e1e.ts.net',
            'app.frontend_url' => 'https://debian.tail7e9e1e.ts.net',
            'app.trusted_hosts' => [],
        ]);

        TrustHosts::at(fn (): array => TrustedHosts::patterns(), subdomains: false);

        $middleware = new class (app()) extends TrustHosts
        {
            public function resolvedHosts(): array
            {
                return $this->hosts();
            }
        };

        $patterns = $middleware->resolvedHosts();
        Request::setTrustedHosts($patterns);

        $request = Request::create('https://debian.tail7e9e1e.ts.net/up');
        $response = $middleware->handle(
            $request,
            fn (Request $request): Response => new Response($request->getHost(), 200),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('debian.tail7e9e1e.ts.net', $response->getContent());
        $this->assertSame('debian.tail7e9e1e.ts.net', $request->getHost());
    }
}
