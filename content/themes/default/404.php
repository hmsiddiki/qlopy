<?php
if (file_exists(__DIR__ . '/header.php')) include __DIR__ . '/header.php';
?>
<main class="container mt-6" role="main">
    <section class="row">
        <div class="col-12">
            <h1>Not Found</h1>
            <p>Sorry, the requested resource could not be found.</p>
            <p><a href="<?php echo htmlspecialchars(SITE_URL); ?>">Return to home</a></p>
        </div>
    </section>
</main>
<?php
if (file_exists(__DIR__ . '/footer.php')) include __DIR__ . '/footer.php';
