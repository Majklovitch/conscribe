<?php
/**
 * Demo page: a quick visual check of the layout, helpers and components.
 */
?>
<section class="section">
    <div class="container flex-col gap-sm">
        <span class="badge">Demo</span>
        <h1>Test page</h1>
        <p class="lead measure color-muted">
            Here to confirm the layout, the helpers and the assets all work. Feel free to delete it
            &ndash; nothing links to it apart from the menu and the footer.
        </p>
    </div>
</section>

<section class="section section--alt">
    <div class="container">
        <div class="section__head">
            <h2>Images and gallery</h2>
            <p>The thumbnail comes from <code>image()</code> using the <code>thumb</code> preset; Spotlight handles the lightbox.</p>
        </div>
        <a class="spotlight" href="/img/thumb1.jpg"
           data-title="Image title"
           data-description="Image description">
            <img src="<?= image('/img/thumb1.jpg', 'thumb') ?>" alt="Sample image" width="500" height="500">
        </a>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section__head">
            <h2>Interface elements</h2>
            <p>Buttons, cards and form fields in the same visual language as the home page.</p>
        </div>

        <div class="split">
            <div class="flex-col gap-md">
                <div class="flex-row gap-sm">
                    <a class="primary-button" href="/">Primary button</a>
                    <a class="secondary-button" href="/">Secondary button</a>
                </div>

                <form class="demo-form" method="post" action="/test">
                    <?= csrf_field() ?>
                    <div class="flex-col gap-2xs">
                        <label for="demo-name">Name</label>
                        <input id="demo-name" type="text" name="name" value="<?= esc(old('name')) ?>" placeholder="Jane Doe">
                    </div>
                    <div class="flex-col gap-2xs">
                        <label for="demo-email">Email</label>
                        <input id="demo-email" type="email" name="email" value="<?= esc(old('email')) ?>" placeholder="jane@example.com">
                    </div>
                    <button class="primary-button" type="submit">Submit</button>
                    <p class="text-sm color-muted">
                        The form has no POST route yet &ndash; it only demonstrates <code>csrf_field()</code> and <code>old()</code>.
                    </p>
                </form>
            </div>

            <div class="grid">
                <article class="card">
                    <div class="card-icon" aria-hidden="true">◆</div>
                    <h3>Card</h3>
                    <p>A basic content block with a border, a shadow and a hover state.</p>
                </article>
                <article class="card">
                    <div class="card-icon" aria-hidden="true">◈</div>
                    <h3>Second card</h3>
                    <p>The grid adapts to the number of items and the screen width on its own.</p>
                </article>
            </div>
        </div>
    </div>
</section>
