</main>
<footer class="site-footer">
    <div class="site-footer__inner">
        <p>&copy; <?= date('Y') ?> ConscribePHP<?= isset($appVersion) ? ' · v' . esc($appVersion) : '' ?></p>
        <nav aria-label="Footer">
            <ul>
                <li><a href="/">Home</a></li>
                <li><a href="/test">Test page</a></li>
                <li><a href="https://github.com/Majklovitch" rel="noopener noreferrer" target="_blank">GitHub</a></li>
            </ul>
        </nav>
    </div>
</footer>
</body>
</html>
