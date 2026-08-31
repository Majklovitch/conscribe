<?php

namespace App\Services\Assets;

use App\Core\Config;
use App\Core\Logging\Log;
use MatthiasMullie\Minify;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;

/**
 * Compiles assets/scss into public/css/style.css and assets/js into
 * public/js/main.js. There is no manifest: asset() already appends
 * ?v=filemtime, so a changed output file is a changed URL.
 *
 * The compiled files are committed to the repository - deployment stays a
 * plain checkout with no build step on the server.
 */
final class AssetBuilder {
    /** @var array<string, mixed> */
    private array $config;

    private string $root;

    public function __construct(private bool $production) {
        $this->root = dirname(__DIR__, 3);

        $configured = Config::get('assets', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        // A deployed app/Config/main.php is gitignored and will not have gained
        // the 'assets' block, so the defaults have to cover it completely.
        $defaults = self::defaults();
        $this->config = $configured + $defaults;
        $this->config['scss'] = ($this->config['scss'] ?? []) + $defaults['scss'];
        $this->config['js'] = ($this->config['js'] ?? []) + $defaults['js'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            'auto_compile' => true,
            'source_maps'  => true,
            'scss' => [
                'entry'  => 'assets/scss/main.scss',
                'watch'  => 'assets/scss',
                'output' => 'public/css/style.css',
            ],
            'js' => [
                'sources' => ['assets/js/main.js'],
                'watch'   => 'assets/js',
                'output'  => 'public/js/main.js',
                'minify'  => true,
            ],
        ];
    }

    public static function fromConfig(): self {
        return new self(Config::get('conscribe.environment', 'production') === 'production');
    }

    /**
     * @return array{css: bool, js: bool} which outputs were rewritten
     */
    public function build(bool $force = true): array {
        return [
            'css' => $this->buildCss($force),
            'js'  => $this->buildJs($force),
        ];
    }

    /**
     * The development request-time build. A broken source file is reported to
     * the log and the last successfully compiled output stays in place, so a
     * stylesheet typo costs you the new styles rather than the whole site.
     * Without an output to fall back on the failure is rethrown - there would
     * be nothing to render anyway.
     *
     * @return array{css: bool, js: bool}
     */
    public function buildOrKeepLastGood(): array {
        $built = ['css' => false, 'js' => false];

        foreach (['css', 'js'] as $kind) {
            try {
                $built[$kind] = $kind === 'css' ? $this->buildCss(false) : $this->buildJs(false);
            } catch (\Throwable $e) {
                $output = $this->path($this->config[$kind === 'css' ? 'scss' : 'js']['output']);
                if (!is_file($output)) {
                    throw $e;
                }

                Log::get()->error('Asset build failed, serving the last good ' . $kind, [
                    'exception' => $e,
                ]);
            }
        }

        return $built;
    }

    public function buildCss(bool $force = true): bool {
        $entry  = $this->path($this->config['scss']['entry']);
        $output = $this->path($this->config['scss']['output']);
        $map    = $output . '.map';

        if (!is_file($entry)) {
            throw new \RuntimeException("SCSS entry point not found: {$entry}");
        }

        if (!$force && !$this->isStaleAgainst($this->config['scss']['watch'], 'scss', $output)) {
            return false;
        }

        $compiler = new Compiler();
        $compiler->setImportPaths([dirname($entry)]);
        $compiler->setOutputStyle($this->production ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED);

        $wantsMap = !$this->production && (bool) $this->config['source_maps'];
        if ($wantsMap) {
            $compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
            $compiler->setSourceMapOptions([
                'sourceMapURL'      => $this->publicUrl($map),
                'sourceMapFilename' => basename($output),
                'sourceMapBasepath' => $this->root,
                'sourceRoot'        => '/',
            ]);
        }

        $result = $compiler->compileString((string) file_get_contents($entry), $entry);
        $css    = $result->getCss();

        if (!$this->production) {
            $css = "/* GENERATED from assets/scss - do not edit */\n" . $css;
        }

        $this->writeAtomic($output, $css);

        if ($wantsMap && ($sourceMap = $result->getSourceMap()) !== null) {
            // scssphp emits the source paths with the OS separator; on Windows
            // that leaves backslashes in the JSON, which devtools cannot resolve.
            $sourceMap = str_replace('\\\\', '/', $sourceMap);
            $this->writeAtomic($map, $sourceMap);
        } elseif (is_file($map)) {
            // A production build must not leave a stale development map behind.
            @unlink($map);
        }

        return true;
    }

    public function buildJs(bool $force = true): bool {
        $sources = $this->jsSources();
        $output  = $this->path($this->config['js']['output']);

        if ($sources === []) {
            throw new \RuntimeException('No JS sources configured.');
        }

        if (!$force && !$this->isStaleAgainst($this->config['js']['watch'], 'js', $output)) {
            return false;
        }

        $concatenated = '';
        foreach ($sources as $file) {
            $concatenated .= file_get_contents($file) . "\n;\n";
        }

        if (!$this->production || !$this->config['js']['minify']) {
            // In development the sources are concatenated untouched so that
            // line numbers in the console still point at something real.
            $this->writeAtomic($output, $concatenated);

            return true;
        }

        $minifier = new Minify\JS();
        foreach ($sources as $file) {
            $minifier->add($file);
        }

        $minified = $minifier->minify();

        // matthiasmullie/minify is a tokenizer, not a parser, and a tokenizer
        // that loses its place typically truncates - so a suspiciously short
        // result is rejected rather than shipped. This catches only that
        // failure mode: it cannot detect ES6+ syntax being minified into
        // something plausible but wrong, which is why assets/js is ES5.
        // See assets/js/README.md.
        if (trim($minified) === '' || strlen($minified) < (int) (strlen($concatenated) * 0.3)) {
            throw new \RuntimeException(sprintf(
                'JS minification produced implausible output (%d bytes from %d). Set assets.js.minify to false to ship concatenated sources.',
                strlen($minified),
                strlen($concatenated)
            ));
        }

        $this->writeAtomic($output, $minified);

        return true;
    }

    /**
     * True when either output is older than its sources. Uses stat() only -
     * it must never compile in order to decide whether to compile.
     */
    public function isStale(): bool {
        return $this->isStaleAgainst($this->config['scss']['watch'], 'scss', $this->path($this->config['scss']['output']))
            || $this->isStaleAgainst($this->config['js']['watch'], 'js', $this->path($this->config['js']['output']));
    }

    /**
     * The newest modification time across all watched sources. The watcher
     * polls this; a changed value means something on disk changed.
     */
    public static function sourceSignature(): int {
        $builder = new self(false);

        return max(
            $builder->newestSource($builder->config['scss']['watch'], 'scss'),
            $builder->newestSource($builder->config['js']['watch'], 'js')
        );
    }

    /**
     * @return list<string> absolute paths, in the configured order
     */
    private function jsSources(): array {
        $files = [];
        foreach ((array) $this->config['js']['sources'] as $source) {
            $file = $this->path($source);
            if (!is_file($file)) {
                throw new \RuntimeException("JS source not found: {$file}");
            }

            $files[] = $file;
        }

        return $files;
    }

    private function isStaleAgainst(string $watchDir, string $extension, string $output): bool {
        if (!is_file($output)) {
            return true;
        }

        // Compared with >= rather than >: mtimes have one-second granularity, so
        // an edit saved in the same second as the previous build would otherwise
        // go unnoticed until the next save. The cost is at most one redundant
        // recompile, and only for that one second.
        return $this->newestSource($watchDir, $extension) >= filemtime($output);
    }

    private function newestSource(string $watchDir, string $extension): int {
        $dir = $this->path($watchDir);
        if (!is_dir($dir)) {
            return 0;
        }

        $newest = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== $extension) {
                continue;
            }

            $newest = max($newest, (int) $file->getMTime());
        }

        return $newest;
    }

    /**
     * Writes through a temporary file so that a concurrent request can never
     * pick up a half-written stylesheet, and clears the stat cache so that
     * asset() sees the new mtime within this same request.
     */
    private function writeAtomic(string $path, string $contents): void {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create output directory: {$dir}");
        }

        // Written with LF endings explicitly - the outputs are committed, and a
        // Windows checkout must not produce a diff against a Linux build.
        $contents = str_replace("\r\n", "\n", $contents);

        $tmp = $path . '.tmp' . getmypid();
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write: {$tmp}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace: {$path}");
        }

        clearstatcache(true, $path);
    }

    private function path(string $relative): string {
        $relative = str_replace('\\', '/', $relative);

        return str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:/#', $relative)
            ? $relative
            : $this->root . '/' . ltrim($relative, '/');
    }

    /**
     * The browser-facing URL of a file inside public/.
     */
    private function publicUrl(string $absolute): string {
        $root = str_replace('\\', '/', $this->root) . '/public/';
        $absolute = str_replace('\\', '/', $absolute);

        return '/' . (str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : basename($absolute));
    }
}
