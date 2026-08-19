<h2>Změna hesla</h2>

<form method="post" action="/heslo">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>Stávající heslo</span>
        <input type="password" name="current" required autocomplete="current-password">
    </label>

    <label class="field">
        <span>Nové heslo (alespoň 8 znaků)</span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>

    <label class="field">
        <span>Nové heslo znovu</span>
        <input type="password" name="password_again" required minlength="8" autocomplete="new-password">
    </label>

    <button class="btn btn--block" type="submit">Změnit heslo</button>
</form>
