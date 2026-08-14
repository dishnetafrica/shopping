<?php declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\RequireSameOrigin;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * v5.05 — the panel API's cross-site gate (launch audit P1-5).
 *
 * `papi/*` is CSRF-exempt while ~40 of its WRITE endpoints are registered as GET, and a
 * SameSite=Lax cookie rides a top-level GET navigation. This middleware refuses the shapes that
 * can only be cross-site.
 *
 * It calls handle() DIRECTLY rather than going through the HTTP kernel, for two reasons: the
 * middleware short-circuits under runningUnitTests() (the test client sends no Origin or Referer,
 * so every papi write in the suite would otherwise be refused), and this is the only way to assert
 * on the header combinations a real browser produces.
 */
class RequireSameOriginTest extends TestCase
{
    private function run(string $method, string $path, array $headers = []): int
    {
        $req = Request::create('https://app.mycloudbss.com/' . ltrim($path, '/'), $method);
        foreach ($headers as $k => $v) {
            $req->headers->set($k, $v);
        }

        $res = (new RequireSameOrigin())->handle($req, fn () => response('ok', 200));

        return $res->getStatusCode();
    }

    public function test_a_cross_site_write_is_refused(): void
    {
        $this->assertSame(403, $this->run('POST', 'papi/record-payment', ['Origin' => 'https://evil.example']));
        $this->assertSame(403, $this->run('GET', 'papi/delete-product?id=412', ['Referer' => 'https://evil.example/page']));
    }

    public function test_a_cross_site_read_is_refused_too(): void
    {
        // It announced where it came from and it was not here; there is no reason to serve it.
        $this->assertSame(403, $this->run('GET', 'papi/products', ['Origin' => 'https://evil.example']));
    }

    public function test_the_panels_own_requests_pass(): void
    {
        $this->assertSame(200, $this->run('POST', 'papi/record-payment', ['Origin' => 'https://app.mycloudbss.com']));
        $this->assertSame(200, $this->run('GET', 'papi/delete-product?id=1', ['Referer' => 'https://app.mycloudbss.com/panel/products']));
        $this->assertSame(200, $this->run('GET', 'papi/products', ['Referer' => 'https://app.mycloudbss.com/panel']));
    }

    public function test_a_sibling_host_on_the_same_site_passes(): void
    {
        $this->assertSame(200, $this->run('POST', 'papi/record-payment', ['Origin' => 'https://www.mycloudbss.com']));
    }

    /**
     * The shape that matters: a top-level navigation sends no Origin, carries the Lax cookie, and
     * can suppress its own Referer. An unattributed GET that WRITES is the attack.
     */
    public function test_an_unattributed_writing_get_is_refused(): void
    {
        foreach (['papi/delete-product?id=412', 'papi/settings-save?x=1', 'papi/update-product?id=9',
                  'papi/update-status?id=1&s=Delivered', 'papi/branch-delete?id=2', 'papi/bot-toggle?on=0'] as $p) {
            $this->assertSame(403, $this->run('GET', $p), $p . ' should be refused');
        }
    }

    public function test_an_unattributed_read_is_allowed(): void
    {
        foreach (['papi/products', 'papi/dashboard', 'papi/reports?range=30', 'papi/track?id=1&t=abc'] as $p) {
            $this->assertSame(200, $this->run('GET', $p), $p . ' should be allowed');
        }
    }

    /**
     * A POST with neither header is curl, a mobile client or a test — never a browser carrying a
     * victim's cookie, because a browser always sends Origin on a cross-origin POST. Refusing it
     * was the regression that broke the CI suite: every postJson() in tests/ arrived this way.
     */
    public function test_an_unattributed_post_is_allowed(): void
    {
        $this->assertSame(200, $this->run('POST', 'papi/trade-cashbook/transfer'));
        $this->assertSame(200, $this->run('POST', 'papi/staff-cash/receive'));
        $this->assertSame(200, $this->run('POST', 'papi/work-orders/5/complete'));
    }

    public function test_a_malformed_origin_is_not_mistaken_for_evidence(): void
    {
        // parse_url returns no host for these; they must fall through to the method rules, not
        // pass as "same site".
        $this->assertSame(403, $this->run('GET', 'papi/delete-product?id=1', ['Origin' => 'null']));
        $this->assertSame(200, $this->run('POST', 'papi/record-payment', ['Origin' => 'null']));
    }
}
