<h2>Přihlášení</h2>

<form method="post" action="/prihlaseni">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>E-mail</span>
        <input type="email" name="email" value="<?= $this->e($old['email'] ?? '') ?>" required autocomplete="email">
    </label>

    <label class="field">
        <span>Heslo</span>
        <input type="password" name="password" required autocomplete="current-password">
    </label>

    <button class="btn btn--block" type="submit">Přihlásit se</button>
</form>

<p class="muted" style="margin-top:1rem">Nemáš účet? <a href="/registrace">Zaregistruj se</a>.</p>
