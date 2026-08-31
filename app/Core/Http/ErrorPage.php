<?php

namespace App\Core\Http;

use App\Core\Config;
use App\Core\Logging\Log;
use App\Core\Template\View;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renders the error pages.
 *
 * It is invokable, so it can be handed straight to Router::setErrorHandler()
 * and ErrorHandler::register() - both take a callable with this signature.
 *
 * The template is chosen by the status code, so a bespoke page is added by
 * dropping in a file, not by branching here:
 *
 *     Views/errors/404.php  ->  Views/errors/4xx.php  ->  Views/errors/error.php
 *
 * With none of them present it falls back to plain text, so the core keeps
 * working in an application that has no templates at all.
 */
class ErrorPage {
    /**
     * Extra template variables (the menu, for instance). It is a callable and
     * not an array because nothing is needed on a successful request - the
     * application must not pay for the error page it never renders.
     *
     * @var (callable(): array)|null
     */
    private $viewData;

    private ?LoggerInterface $logger;

    /**
     * @param (callable(): array)|null $viewData extra variables for the template
     * @param LoggerInterface|null     $logger   null = default logger from the configuration (lazily)
     */
    public function __construct(?callable $viewData = null, ?LoggerInterface $logger = null) {
        $this->viewData = $viewData;
        $this->logger = $logger;
    }

    public function __invoke(int $code, string $message, Request $request): Response {
        $this->log($code, $message, $request);

        $public = $this->publicMessage($code, $message);
        $view = $this->resolveView($code);

        if ($view !== null) {
            try {
                $html = View::render($view, [
                    'pageTitle' => "Error {$code}",
                    'code'      => $code,
                    'message'   => $public,
                ] + $this->extraData());

                return Response::html($html, $code);
            } catch (Throwable $e) {
                // The error page is the last thing that may throw - a second
                // failure here would leave the visitor with a blank page.
                $this->logger()->error('Error page {view} failed to render: {message}', [
                    'view'      => $view,
                    'message'   => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return new Response("Error {$code}: {$public}", $code, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * The first template that exists, from the most specific to the most
     * generic one. Null means the application ships no error template.
     */
    protected function resolveView(int $code): ?string {
        $candidates = [
            "errors/{$code}",
            'errors/' . intdiv($code, 100) . 'xx',
            'errors/error',
        ];

        foreach ($candidates as $candidate) {
            if (View::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The keys above take precedence, so the application cannot accidentally
     * overwrite $code or $message. A failure is swallowed on purpose: a 500
     * caused by an unreachable database must not fail again while fetching
     * the menu for the page reporting it.
     */
    protected function extraData(): array {
        if ($this->viewData === null) {
            return [];
        }

        try {
            $data = ($this->viewData)();
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * The text shown to the visitor. Messages for 5xx describe the internals of
     * the application (class names, a missing controller) and the template
     * prints them onto the page, so only a generic sentence goes out. The detail
     * stays in the log.
     */
    protected function publicMessage(int $code, string $message): string {
        if ($code < 500) {
            return $message;
        }

        return Config::get('conscribe.environment', 'production') === 'development'
            ? $message
            : 'Something went wrong on the server. Please try again later.';
    }

    /**
     * A 5xx is an application error, a 4xx merely information about the request.
     * What actually gets written is decided by the threshold in the 'log.level'
     * configuration (warning by default in production, so 404s make no noise).
     */
    protected function log(int $code, string $message, Request $request): void {
        $level = match (true) {
            $code >= 500 => 'error',
            $code === 405 => 'warning',
            default => 'info',
        };

        $this->logger()->log($level, 'HTTP {code}: {message}', [
            'code'    => $code,
            'message' => $message,
            'method'  => $request->getMethod(),
            'path'    => '/' . $request->getPath(),
            'ip'      => $request->getClientIp(),
            'referer' => $request->getHeader('referer'),
            'agent'   => $request->getHeader('user-agent'),
        ]);
    }

    /**
     * Built on first use only - constructing the page must stay free.
     */
    protected function logger(): LoggerInterface {
        return $this->logger ??= Log::get();
    }
}
