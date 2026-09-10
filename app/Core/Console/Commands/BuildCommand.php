<?php

namespace App\Core\Console\Commands;

use App\Core\Config;
use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use App\Services\Assets\AssetBuilder;
use Throwable;

/**
 * Compiles SCSS and minifies JS into public/.
 */
final class BuildCommand extends Command {
    protected string $name = 'build';
    protected string $description = 'Compiles SCSS and minifies JS into public/.';
    protected string $help = <<<'HELP'
        Usage: php tty build [--env=production|development] [--watch] [--interval=ms]

          --env       production minifies; development keeps the CSS expanded and
                      writes a source map. Defaults to the configured environment.
          --watch     rebuild whenever a file under assets/ changes.
          --interval  how often to look, in milliseconds (default 500, minimum 200).
        HELP;

    public function run(Input $input, Output $output): int {
        $env = $input->option('env');
        $env = is_string($env)
            ? $env
            : (string) Config::get('conscribe.environment', 'production');

        $production = $env === 'production';
        $watching = $input->hasOption('watch');

        if (!$watching) {
            return $this->build($production, $output) ? self::SUCCESS : self::FAILURE;
        }

        $interval = max(200, (int) $input->option('interval', '500')) * 1000;
        $last = null;

        $output->comment('watching assets/ (Ctrl+C to stop)');

        // for(;;) rather than while(true): the watcher runs until it is
        // interrupted, and a literal always-true condition is indistinguishable
        // from a loop whose exit test was lost in an edit.
        for (;;) {
            $signature = AssetBuilder::sourceSignature();

            if ($signature !== $last) {
                $last = $signature;
                // A failed build only reports; the last good output stays on
                // disk and the watcher keeps running so the next save can fix
                // it.
                $this->build($production, $output);
            }

            usleep($interval);
        }
    }

    private function build(bool $production, Output $output): bool {
        $started = microtime(true);

        try {
            (new AssetBuilder($production))->build();
        } catch (Throwable $e) {
            $output->error(sprintf('[%s] build failed: %s', date('H:i:s'), $e->getMessage()));

            return false;
        }

        $output->success(sprintf(
            '[%s] built %s in %d ms',
            date('H:i:s'),
            $production ? 'production' : 'development',
            (int) ((microtime(true) - $started) * 1000),
        ));

        return true;
    }
}
