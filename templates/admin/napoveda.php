<?= $this->render('admin/layout-nav') ?>

<h2>Nápověda pro správce</h2>

<h3>Ke kontrole</h3>
<p class="muted">
    Import doplnil značky, které školní dokument neuvádí — národní literaturu
    tam, kde jsou v kapitole česká i světová díla pohromadě, literární formu a
    literární období. Tyhle značky nesou otazník, dokud je nepotvrdí člověk.
</p>
<ul class="muted">
    <li><strong>Po dílech</strong> (<a href="/kontrola">Ke kontrole</a>) — díla
        v pořadí školního kánonu, u každého všechny značky. Klepnutí na značku
        s otazníkem ji potvrdí, tlačítko ✓ potvrdí celé dílo. Stránka se vrátí
        na stejné místo, takže jde projít několik děl po sobě.</li>
    <li><strong>Po skupinách</strong> (<a href="/kontrola/skupiny">rychlejší</a>) —
        například „41 děl, u kterých import určil <em>světová literatura</em>“
        potvrdíš jedním tlačítkem. Skupiny jsou seřazené podle důležitosti:
        literární období a česká poezie první, protože na nich stojí pravidla,
        která student ručně nespočítá.</li>
</ul>
<p class="muted">
    Potvrzená značka se uloží jako lidské rozhodnutí a <strong>příští import ji
    už nepřepíše</strong>. Špatnou značku oprav v <a href="/dila">detailu díla</a>.
</p>

<h3>Pravidla</h3>
<p class="muted">
    V <a href="/pravidla">pravidlech</a> se mění jen čísla a zapnutí; typ
    pravidla je v kódu. Uložit nejde nic, co by kontrola neuměla vyhodnotit —
    formulář to odmítne dřív, než se to dostane ke studentům.
</p>

<h3>Import</h3>
<p class="muted">
    <a href="/import">Import</a> čte dva soubory z repozitáře: kopii školního
    dokumentu a ručně doplněné značky. Na internet nesahá, takže se kánon
    nezmění sám od sebe. Vždycky nejdřív <strong>nanečisto</strong> — uvidíš,
    co by se stalo, a teprve pak to spustíš doopravdy.
</p>
<p class="muted">
    Když ze školního dokumentu nějaké dílo zmizí, import ho sám nesmaže; jen ho
    vypíše, včetně toho, v kolika studentských seznamech je. Smaže se až na
    výslovný pokyn.
</p>

<h3>Uživatelé</h3>
<p class="muted">
    V <a href="/uzivatele">uživatelích</a> se udělují práva správce a blokují
    účty. Vlastní účet si měnit nemůžeš — o přístup do administrace by se dalo
    přijít bez cesty zpět. Registrace tady není; účty studentů vznikají na
    studentském webu.
</p>

<h3>Když je potřeba zasáhnout hlouběji</h3>
<p class="muted">
    Nasazení, migrace databáze a import z příkazové řádky popisuje
    <code>README.md</code> a <code>~/CLAUDE.md</code> na vývojovém serveru.
</p>
