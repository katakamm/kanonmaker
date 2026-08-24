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
            <a class="btn btn--quiet btn--small" href="<?= $this->e($canonUrl ?? '/kanon') ?>">Kánon</a>
            <a class="btn btn--quiet btn--small" href="/ucet">Účet</a>
        <?php else: ?>
            <a class="btn btn--quiet btn--small" href="<?= $this->e($canonUrl ?? '/kanon') ?>">Kánon</a>
            <a class="btn btn--quiet btn--small" href="/prihlaseni">Přihlásit</a>
            <?php if ($showRegister ?? true): ?>
                <a class="btn btn--quiet btn--small" href="/registrace">Registrovat</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
</header>

<main class="wrap">
    <?php foreach ($flashes as $flash): ?>
        <p class="flash flash--<?= $this->e($flash['type']) ?>"><?= $this->e($flash['message']) ?></p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="wrap site-footer">
    <p class="muted">
        Seznam vychází ze
        <a href="<?= $this->e($canonDocumentUrl) ?>" target="_blank" rel="noopener">školního kánonu GJK</a>
        pro školní rok <?= $this->e($schoolYear) ?>.
    </p>
</footer>

<?= $rulebar ?>
<script src="/assets/app.js" defer></script>
</body>
</html>
