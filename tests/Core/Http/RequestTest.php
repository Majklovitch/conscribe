<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Config;
use App\Core\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Support\RequestFactory;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase {
    protected function tearDown(): void {
        Config::set([]);
    }

    private function viaProxy(string $forwardedFor, string $peer = '10.0.0.5'): Request {
        Config::set(['conscribe' => ['trusted_proxies' => ['10.0.0.0/8']]]);

        return RequestFactory::make('GET', '/', [], [
            'REMOTE_ADDR' => $peer,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]);
    }

    // -----------------------------------------------------------------
    // Client address
    // -----------------------------------------------------------------

    #[TestDox('with no trusted proxies configured, the peer address is the client')]
    public function testNoProxiesMeansRemoteAddr(): void {
        $request = RequestFactory::make('GET', '/', [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('203.0.113.9', $request->getClientIp());
    }

    #[TestDox('behind a trusted proxy the forwarded client is returned without having to ask')]
    public function testTrustedProxyYieldsTheClient(): void {
        // Previously this needed getClientIp(true); a caller that did not pass
        // it - the login throttle included - saw the proxy for every visitor.
        self::assertSame('198.51.100.7', $this->viaProxy('198.51.100.7')->getClientIp());
    }

    #[TestDox('a left-most address the client wrote itself is ignored')]
    public function testSpoofedLeftMostEntryIsIgnored(): void {
        // The client sends "X-Forwarded-For: 1.2.3.4" and the proxy appends the
        // real address. Taking the first entry let anyone claim any address.
        self::assertSame('198.51.100.7', $this->viaProxy('1.2.3.4, 198.51.100.7')->getClientIp());
    }

    #[TestDox('a chain of trusted proxies is walked past')]
    public function testChainOfTrustedProxies(): void {
        self::assertSame('198.51.100.7', $this->viaProxy('198.51.100.7, 10.0.0.9, 10.1.2.3')->getClientIp());
    }

    #[TestDox('an untrusted peer cannot forward anything')]
    public function testUntrustedPeerIsNotBelieved(): void {
        self::assertSame('203.0.113.9', $this->viaProxy('1.2.3.4', '203.0.113.9')->getClientIp());
    }

    #[TestDox('a hop that is not an address stops the walk')]
    public function testGarbageHopStopsTheWalk(): void {
        self::assertSame('10.0.0.5', $this->viaProxy('198.51.100.7, not-an-address')->getClientIp());
    }

    public function testTheDirectPeerCanStillBeRequested(): void {
        self::assertSame('10.0.0.5', $this->viaProxy('198.51.100.7')->getClientIp(false));
    }

    // -----------------------------------------------------------------
    // Bodies of non-POST requests
    // -----------------------------------------------------------------

    #[TestDox('a form-encoded body is parsed, as PHP only does for POST')]
    public function testUrlencodedBodyIsParsed(): void {
        self::assertSame(
            ['qty' => '2', 'csrf_token' => 't'],
            Request::parseUrlencoded('application/x-www-form-urlencoded; charset=UTF-8', 'qty=2&csrf_token=t'),
        );
    }

    public function testOtherContentTypesAreNotParsedAsForms(): void {
        self::assertNull(Request::parseUrlencoded('text/plain', 'qty=2'));
    }
}
