<?php

namespace App\Core\Logging;

use App\Core\Http\Request;
use Psr\Log\LoggerInterface;
use Tracy\Debugger;
use Tracy\IBarPanel;

/**
 * Development debugging through Tracy: the blue screen and the debug bar.
 *
 * Tracy is a dev dependency; production installs with --no-dev and does not
 * have it, so it is used only when genuinely available. It is not merely a
 * renderer - the debug bar needs Tracy's own output hook, so in development
 * Tracy registers the error, exception and shutdown handlers instead of
 * ErrorHandler. Nothing stops being logged:
 * TracyMonologAdapter passes everything it catches to the same Monolog logger.
 */
class Debug {
    private static bool $enabled = false;

    /**
     * An uncaught exception is also the last error PHP reports, so without this
     * the shutdown check below would log the same failure a second time.
     */
    private static bool $handled = false;

    /**
     * @param LoggerInterface|null $logger null = default logger from the configuration (lazily)
     *
     * @return bool true = Tracy took over the PHP handlers, so ErrorHandler
     *              must not register its own
     */
    public static function enable(bool $isDevelopment, ?LoggerInterface $logger = null): bool {
        if (!$isDevelopment || !class_exists(Debugger::class)) {
            return false;
        }

        if (self::$enabled) {
            return true;
        }

        // Tracy's default: a notice lands in the bar's warnings panel instead
        // of replacing the page with a blue screen.
        Debugger::$strictMode = false;
        // The bar is HTML; it has no business in the console output.
        Debugger::$showBar = PHP_SAPI !== 'cli';
        Debugger::$scream = false;
        // Monolog owns the files - Tracy must not write a second log of its own.
        Debugger::$logDirectory = null;
        Debugger::$email = null;
        Debugger::setLogger(new TracyMonologAdapter($logger));

        // Configuration passwords stay out of the blue screen. The masking
        // lives in Dump because it is needed in production too, where none of
        // this runs.
        Dump::configureBlueScreen();

        // A fatal error: this runs before Tracy's own shutdown handler, which
        // renders and then terminates.
        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if (!self::$handled && $error !== null && ($error['type'] & ErrorHandler::FATAL)) {
                ErrorHandler::logFatal($error);
            }
        });

        Debugger::enable(Debugger::Development);
        Debugger::getBar()->addPanel(self::requestPanel(), 'conscribe.request');
        self::logThroughTracy();

        return self::$enabled = true;
    }

    public static function isEnabled(): bool {
        return self::$enabled;
    }

    /**
     * Keeps the log complete in development.
     *
     * Tracy hands an exception to its logger only when it cannot show the blue
     * screen (CLI, AJAX), and a plain warning it merely counts in the bar - so
     * setLogger() alone would silently lose most of what production records.
     * Its handlers are therefore wrapped: log first, then let Tracy display it.
     */
    private static function logThroughTracy(): void {
        $previousError = set_error_handler(null);
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($previousError) {
            // Respect both error_reporting and the '@' operator.
            if (!(error_reporting() & $severity)) {
                return false;
            }

            ErrorHandler::logPhpError($severity, $message, $file, $line);

            return $previousError === null
                ? false
                : $previousError($severity, $message, $file, $line);
        });

        $previousException = set_exception_handler(null);
        set_exception_handler(static function (\Throwable $e) use ($previousException): void {
            self::$handled = true;
            ErrorHandler::logException($e);

            if ($previousException !== null) {
                $previousException($e);
            }
        });
    }

    /**
     * What the framework actually made of the request, not what is in
     * $_SERVER - that is what the built-in Tracy panels already show.
     */
    private static function requestPanel(): IBarPanel {
        return new class() implements IBarPanel {
            private ?Request $request = null;

            public function getTab(): string {
                return '<span title="Conscribe request">Conscribe '
                    . htmlspecialchars($this->request()->getMethod(), ENT_QUOTES, 'UTF-8') . '</span>';
            }

            public function getPanel(): string {
                $request = $this->request();

                $rows = [
                    'Method' => $request->getMethod(),
                    'Path'   => '/' . $request->getPath(),
                    'IP'     => $request->getClientIp(),
                    'Ajax'   => $request->isAjax() ? 'yes' : 'no',
                ];

                $html = '<h1>Conscribe request</h1><div class="tracy-inner"><table>';
                foreach ($rows as $label => $value) {
                    $html .= '<tr><th>' . $label . '</th><td>'
                        . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
                }

                return $html . '</table></div>';
            }

            /**
             * The bar renders the tab and the panel separately; the request is
             * the same one for both.
             */
            private function request(): Request {
                return $this->request ??= Request::createFromGlobals();
            }
        };
    }
}
