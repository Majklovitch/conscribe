<?php

declare(strict_types=1);

namespace Tests\Core\Template;

use App\Core\Config;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * renderTrackingCodes() must not print anything that runs or downloads before
 * the visitor answers the cookie banner - the trackers are loaded by
 * assets/js/main.js once their consent category is accepted.
 */
final class TrackingCodesTest extends TestCase {
    protected function tearDown(): void {
        Config::set([]);
    }

    private static function render(array $tracking): string {
        Config::set(['tracking' => $tracking]);

        return renderTrackingCodes();
    }

    /** @return array<string, mixed> */
    private static function decode(string $html): array {
        self::assertSame(1, preg_match('#<script type="application/json" id="tracking-config">(.*)</script>#s', $html, $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    #[TestDox('Nothing is rendered without tracking IDs')]
    public function testEmpty(): void {
        self::assertSame('', self::render([]));
        self::assertSame('', self::render(['ga4_id' => null, 'sklik_id' => '', 'fb_pixel_id' => '  ']));
    }

    #[TestDox('No executable script and no tracker URL before consent')]
    public function testNothingRunsBeforeConsent(): void {
        $html = self::render([
            'ga4_id'      => 'G-ABC123',
            'gtag_id'     => 'GT-XYZ',
            'adwords_id'  => 'AW-999',
            'sklik_id'    => '123456',
            'fb_pixel_id' => '1234567890',
        ]);

        // The only script is the JSON data block, which the browser never runs.
        self::assertSame(1, substr_count($html, '<script'));
        self::assertStringContainsString('type="application/json"', $html);
        foreach (['googletagmanager', 'seznam.cz', 'facebook', '<noscript', '<img', ' src='] as $needle) {
            self::assertStringNotContainsString($needle, $html);
        }
    }

    #[TestDox('IDs are grouped by consent category')]
    public function testCategories(): void {
        $config = self::decode(self::render([
            'ga4_id'      => ' G-ABC123 ',
            'gtag_id'     => 'GT-XYZ',
            'adwords_id'  => 'AW-999',
            'sklik_id'    => '123456',
            'fb_pixel_id' => '1234567890',
        ]));

        self::assertSame([
            'analytics' => ['G-ABC123', 'GT-XYZ'],
            'ads'       => ['AW-999'],
            'sklik'     => 123456,
            'fbPixel'   => '1234567890',
        ], $config);
    }

    #[TestDox('Only the configured trackers are listed')]
    public function testPartial(): void {
        self::assertSame(['ads' => ['AW-1']], self::decode(self::render(['adwords_id' => 'AW-1'])));
    }

    #[TestDox('A non-numeric Sklik ID is dropped')]
    public function testSklikMustBeNumeric(): void {
        self::assertSame('', self::render(['sklik_id' => '12; alert(1)']));
    }

    #[TestDox('A value cannot close the script block')]
    public function testEscaping(): void {
        $html = self::render(['ga4_id' => '</script><script>alert(1)</script>', 'fb_pixel_id' => "x'\"&"]);

        self::assertSame(1, substr_count($html, '</script>'));
        self::assertSame(['</script><script>alert(1)</script>'], self::decode($html)['analytics']);
        self::assertSame("x'\"&", self::decode($html)['fbPixel']);
    }
}
