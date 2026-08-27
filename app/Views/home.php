<?php
/**
 * The ConscribePHP welcome page.
 *
 * @var string      $appVersion
 * @var array<int, array{icon: string, title: string, text: string}> $features
 */
$appVersion = $appVersion ?? '';
$features   = $features ?? [];
?>
<section class="hero">
    <div class="container hero__inner">
        <span class="badge">ConscribePHP<?= $appVersion !== '' ? ' v' . esc($appVersion) : '' ?></span>
        <h1>A lightweight PHP&nbsp;MVC base you can build on right away</h1>
        <p class="lead">
            Router, templates, asset cache-busting, hardened sessions, CSRF, a thin database layer,
            logging, an image pipeline, a module system and security headers. No framework overhead,
            no configuration marathon &ndash; just add a controller and a template.
        </p>
        <div class="hero__actions">
            <a class="primary-button" href="#quick-start">Quick start</a>
            <a class="secondary-button" href="/test">Demo page</a>
        </div>
        <p class="hero__meta">
            <span>PHP 8.4+</span>
            <span>No framework dependency</span>
            <span>Docker ready</span>
        </p>
    </div>
</section>

<section class="section" id="features">
    <div class="container">
        <div class="section__head">
            <h2>What you get out of the box</h2>
            <p>Everything set up so the first production deploy doesn't mean writing infrastructure first.</p>
        </div>
        <div class="grid">
            <?php foreach ($features as $feature): ?>
                <article class="card">
                    <div class="card-icon" aria-hidden="true"><?= esc($feature['icon']) ?></div>
                    <h3><?= esc($feature['title']) ?></h3>
                    <p><?= esc($feature['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section section--alt" id="quick-start">
    <div class="container">
        <div class="section__head">
            <h2>Quick start</h2>
            <p>From clone to a running page in about two minutes.</p>
        </div>
        <div class="split">
            <ol class="step-list">
                <li>
                    <div>
                        <strong>Install the dependencies</strong>
                        <span>In production use <code>composer install --no-dev --optimize-autoloader</code>.</span>
                    </div>
                </li>
                <li>
                    <div>
                        <strong>Copy the configuration</strong>
                        <span><code>app/Config/main.example.php</code> &rarr; <code>app/Config/main.php</code>, then fill in the database and tracking IDs.</span>
                    </div>
                </li>
                <li>
                    <div>
                        <strong>Point the web server at <code>public/</code></strong>
                        <span>Or run <code>docker-compose up -d</code> and open localhost.</span>
                    </div>
                </li>
                <li>
                    <div>
                        <strong>Add your own page</strong>
                        <span>A route, a controller method, a template in <code>app/Views</code>. Done.</span>
                    </div>
                </li>
            </ol>

            <div class="flex-col gap-sm">
<pre class="code-block"><code><span class="c-comment">// app/Config/routes.php</span>
$router-&gt;<span class="c-key">get</span>(<span class="c-key">'contact'</span>, [WebController::<span class="c-key">class</span>, <span class="c-key">'contact'</span>]);

<span class="c-comment">// app/Controllers/WebController.php</span>
<span class="c-key">public function</span> contact() {
    <span class="c-key">return</span> $this-&gt;render(<span class="c-key">'contact'</span>, [
        <span class="c-key">'pageTitle'</span>   =&gt; <span class="c-key">'Contact'</span>,
        <span class="c-key">'menuItems'</span>   =&gt; $this-&gt;menuItems,
    ]);
}</code></pre>
                <p class="text-sm color-muted">
                    The template is wrapped in the header and footer from <code>app/Views/layout</code> automatically.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section" id="helpers">
    <div class="container">
        <div class="section__head">
            <h2>Helpers within reach</h2>
            <p>Global functions available in every template &ndash; no imports, no service container.</p>
        </div>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Overview of the global helpers available in templates</caption>
                <thead>
                    <tr>
                        <th scope="col">Helper</th>
                        <th scope="col">What it does</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>esc($value)</code></td>
                        <td>Safe HTML output; handles <code>null</code> and numbers too.</td>
                    </tr>
                    <tr>
                        <td><code>asset('css/style.css')</code></td>
                        <td>A link to a static file with a cache buster based on its modification time.</td>
                    </tr>
                    <tr>
                        <td><code>image($path, 'thumb')</code></td>
                        <td>A resized image variant from a named preset, generated on first render.</td>
                    </tr>
                    <tr>
                        <td><code>image_tag($path, 600, 400)</code></td>
                        <td>A ready-made <code>&lt;img&gt;</code> including dimensions and lazy loading.</td>
                    </tr>
                    <tr>
                        <td><code>svg('icon')</code></td>
                        <td>An inline SVG icon, sanitized, with your own classes and ARIA attributes.</td>
                    </tr>
                    <tr>
                        <td><code>csrf_field()</code></td>
                        <td>A hidden token field; verify it with <code>check_csrf()</code>.</td>
                    </tr>
                    <tr>
                        <td><code>flash()</code> / <code>old()</code></td>
                        <td>Messages and form repopulation after a redirect.</td>
                    </tr>
                    <tr>
                        <td><code>dateFormat($date, 'd. m. Y')</code></td>
                        <td>Formats a date string with a given <code>DateTime</code> format.</td>
                    </tr>
                    <tr>
                        <td><code>truncate($text, 100)</code></td>
                        <td>Cuts a string to the given length and appends an ellipsis.</td>
                    </tr>
                    <tr>
                        <td><code>component('name', $data)</code></td>
                        <td>Renders an isolated fragment with its own data scope.</td>
                    </tr>
                    <tr>
                        <td><code>renderTrackingCodes()</code></td>
                        <td>Outputs GA4, Google Tag, Ads, Sklik and Facebook Pixel scripts from config.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="cta">
            <h2>Ready to use</h2>
            <p class="measure">
                Delete this welcome page, keep the layout and the helpers, and start writing your own project.
            </p>
            <div class="hero__actions">
                <a class="primary-button" href="/test">View the demo</a>
                <a class="secondary-button" href="https://github.com/Majklovitch" rel="noopener noreferrer" target="_blank">Docs in the README</a>
            </div>
        </div>
    </div>
</section>
