<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title) ?> · Kánon GJK</title>
    <link rel="stylesheet" href="/assets/tokens.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="top">
    <h1><a href="/" class="plain">Kánon GJK</a></h1>
    <nav>
        <?php if ($user !== null): ?>
            <a href="/kanon">Kánon</a>
            <a href="/export">Export</a>
            <form method="post" action="/odhlasit">
                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                <button class="btn btn--quiet btn--small">Odhlásit</button>
            </form>
        <?php else: ?>
            <a href="/kanon">Kánon</a>
            <a href="/prihlaseni">Přihlásit</a>
            <a href="/registrace">Registrovat</a>
        <?php endif; ?>
    </nav>
</header>

<main class="wrap">
    <?php foreach ($flashes as $flash): ?>
        <p class="flash flash--<?= $this->e($flash['type']) ?>"><?= $this->e($flash['message']) ?></p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<?= $rulebar ?>
<script src="/assets/app.js" defer></script>
</body>
</html>
