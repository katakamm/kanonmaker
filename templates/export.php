<h2>Export do PDF</h2>

<p class="muted">Vyber, co má být na stránce. Seznam má právě <?= $this->e($count) ?> děl.</p>

<form method="post" action="/export">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <label class="field">
        <span>Jméno na seznamu</span>
        <input type="text" name="name" value="<?= $this->e($defaultName) ?>" placeholder="nechat prázdné = bez jména">
    </label>

    <label class="field">
        <span>Školní rok</span>
        <input type="text" name="year" value="<?= $this->e($defaultYear) ?>">
    </label>

    <p style="margin:1rem 0 .3rem">Obsah</p>

    <label class="checkline"><input type="checkbox" name="grouped" value="1"> Rozdělit podle kapitol kánonu</label>
    <label class="checkline"><input type="checkbox" name="chips" value="1" checked> Vypsat u každého díla jeho kategorie</label>
    <label class="checkline"><input type="checkbox" name="rules" value="1" checked> Přidat shrnutí pravidel</label>

    <button class="btn btn--block" type="submit" style="margin-top:1rem">Stáhnout PDF</button>
</form>
