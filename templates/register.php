<h2>Registrace</h2>

<form method="post" action="/registrace">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>Jméno</span>
        <input type="text" name="display_name" value="<?= $this->e($old['display_name'] ?? '') ?>" required autocomplete="name">
    </label>

    <label class="field">
        <span>E-mail</span>
        <input type="email" name="email" value="<?= $this->e($old['email'] ?? '') ?>" required autocomplete="email">
    </label>

    <label class="field">
        <span>Heslo (alespoň 8 znaků)</span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>

    <button class="btn btn--block" type="submit">Založit účet</button>
</form>

<p class="muted" style="margin-top:1rem">Už účet máš? <a href="/prihlaseni">Přihlas se</a>.</p>
