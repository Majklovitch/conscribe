<?php

namespace App\Core\Http;

use App\Core\Config;

/**
 * Content-Security-Policy with a per-request nonce.
 *
 * The policy itself is configuration, not code - a site adds the hosts it
 * actually loads from and this class does nothing but stamp the request's
 * nonce into it. The nonce is generated once per request and handed to the
 * inline <script> blocks through the csp_nonce() template helper, so the
 * tracking codes keep working without 'unsafe-inline' opening the door to
 * every injected script as well.
 *
 * Report-only is the default: an existing site upgrading to this version gets
 * violation reports in the browser console instead of a blank page.
 */
final class Csp {
    private static ?string $nonce = null;

    /**
     * The nonce for this request. Generated on first use, so a response that
     * has no inline script never spends the entropy.
     */
    public static function nonce(): string {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Drops the generated nonce. Meant for tests, which run several requests
     * in one process.
     */
    public static function reset(): void {
        self::$nonce = null;
    }

    /**
     * The header to send, or null when no policy is configured.
     *
     * @return array{0: string, 1: string}|null [name, value]
     */
    public static function header(): ?array {
        $policy = Config::get('security.csp');

        if (!is_string($policy) || trim($policy) === '') {
            return null;
        }

        // Only pay for a nonce when the policy actually asks for one.
        if (str_contains($policy, '{nonce}')) {
            $policy = str_replace('{nonce}', self::nonce(), $policy);
        }

        $name = Config::get('security.csp_report_only', true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        return [$name, trim($policy)];
    }
}
