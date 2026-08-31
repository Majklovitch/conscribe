# JavaScript sources

Compiled to `public/js/main.js` by `App\Services\Assets\AssetBuilder`. Never edit
the output directly - run `composer build` instead.

**Keep this code ES5.** The production build minifies with
`matthiasmullie/minify`, which is a tokenizer rather than a parser and has no
ES6+ awareness: template literals, optional chaining (`?.`), nullish coalescing
(`??`) and some arrow-function positions can produce output that is valid
syntax but wrong behaviour - and only in the production build.

If modern syntax becomes unavoidable, set `assets.js.minify` to `false` in
`app/Config/main.php`. Shipping 3 KB unminified beats shipping a broken page.
