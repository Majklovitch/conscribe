<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logging\Log;
use App\Models\Menu\MenuRepository;
use Psr\Log\LoggerInterface;

/**
 * Renders the error pages.
 *
 * It is invokable, so it can be handed to the router directly as the error
 * handler (Router::setErrorHandler() takes a callable), which keeps the
 * registration to a single line - no logic in the route configuration.
 */
class ErrorController extends Controller {
    protected array $menuItems;
    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null) {
        $this->menuItems = (new MenuRepository())->all();
        $this->logger = $logger ?? Log::get();
    }

    public function __invoke(int $code, string $message, Request $request): Response {
        $this->setRequest($request);
        $this->log($code, $message, $request);

        return $this->render('404', [
            'pageTitle' => "Error {$code}",
            'code'      => $code,
            'message'   => $this->publicMessage($code, $message),
            'menuItems' => $this->menuItems,
        ], 'main', $code);
    }

    /**
     * The text shown to the visitor. Messages for 5xx describe the internals of
     * the application (class names, a missing controller) and the error template
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

        $this->logger->log($level, 'HTTP {code}: {message}', [
            'code'     => $code,
            'message'  => $message,
            'method'   => $request->getMethod(),
            'path'     => '/' . $request->getPath(),
            'ip'       => $request->getClientIp(),
            'referer'  => $request->getHeader('referer'),
            'agent'    => $request->getHeader('user-agent'),
        ]);
    }
}
