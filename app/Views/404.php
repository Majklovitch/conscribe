<section class="section">
    <div class="container flex-col align-center justify-center gap-sm py-xl">
        <img style="width: min(350px, 80%); height: auto; object-fit: contain" src="/img/error.webp" alt="" width="788" height="300">
        <p class="text-4xl color-primary" style="line-height: 1"><?= esc((string) $code) ?></p>
        <h1>That didn't work out</h1>
        <p class="measure color-muted"><?= esc((string) $message) ?></p>
        <div class="hero__actions mt-sm">
            <a class="primary-button" href="/">Home page</a>
            <button class="secondary-button" type="button" data-history-back>Go back</button>
        </div>
    </div>
</section>
