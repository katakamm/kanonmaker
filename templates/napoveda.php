<h2>Nápověda</h2>

<p class="muted" style="margin-top:0">
    Tahle stránka je na sestavení maturitního seznamu četby. Vybíráš z děl,
    která má tvoje škola v kánonu, a průběžně vidíš, která pravidla už seznam
    splňuje a která ještě ne.
</p>

<h3>Jak si seznam sestavit</h3>
<ol>
    <li><strong>Najdi dílo.</strong> Do hledání stačí psát bez diakritiky —
        <em>capek</em> najde Čapka, <em>zert</em> najde Žert. Hledá se v názvu
        i ve jméně autora.</li>
    <li><strong>Přidej ho tlačítkem +.</strong> Přidané dílo pozná podle ✓;
        stejným tlačítkem ho zase odebereš.</li>
    <li><strong>Sleduj lištu dole.</strong> Ukazuje, kolik děl už máš a kolik
        pravidel zbývá. Klepnutím se rozbalí celý seznam pravidel.</li>
</ol>

<p class="muted">
    Nevíš, co přidat? Otevři <a href="/kanon">celý kánon</a> a profiltruj si ho
    podle kapitoly nebo podle značky.
</p>

<h3>Pravidla, která seznam hlídá</h3>

<p class="muted">Přesně tahle pravidla ti kontroluje lišta dole:</p>

<table style="width:100%;border-collapse:collapse">
    <?php foreach ($rules as $rule): ?>
        <tr style="border-bottom:1px solid var(--line)">
            <td style="padding:.45rem 0"><?= $this->e($rule->label) ?></td>
            <td style="padding:.45rem 0;text-align:right;white-space:nowrap" class="muted">
                <?= $rule->required > 0 ? 'min. ' . $this->e($rule->required) : '' ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p class="muted">
    Jedno dílo se počítá do víc pravidel najednou — česká báseň z 19. století
    se započítá do období, do české literatury i do poezie. Proto součet
    minim vyjde víc než celkový počet titulů.
</p>

<h3>Nesplněné pravidlo je zkratka</h3>
<p class="muted">
    V rozbalené liště jsou nesplněná pravidla odkazy. Klepneš na „Drama“ a
    rovnou uvidíš jen dramata, ze kterých si můžeš vybrat. Nemusíš nic hledat
    ručně.
</p>

<h3>Co znamenají barevné značky</h3>
<ul class="chips" style="margin:.5rem 0 1rem">
    <li class="chip chip--obdobi">období</li>
    <li class="chip chip--podobdobi">literární období</li>
    <li class="chip chip--narodni">národní literatura</li>
    <li class="chip chip--forma">literární forma</li>
    <li class="chip chip--special">zvláštní</li>
</ul>
<p class="muted">
    <strong>Období</strong> je jedna ze tří skupin ze školních kritérií.
    <strong>Literární období</strong> (starověk, středověk, renesance, baroko,
    klasicismus) mají jen díla do konce 18. století — z nich musí být aspoň tři
    různá. <strong>Zvláštní</strong> značku nese česká poezie 2. poloviny
    20. století a novější; aspoň jedna taková sbírka v seznamu být musí.
</p>

<h3>Otazník u značky</h3>
<p class="muted">
    Školní dokument u každého díla neuvádí všechno — třeba u kapitoly, kde jsou
    česká i světová díla pohromadě, se u jednotlivých titulů nepíše, které je
    které. Takové značky doplnil program a <strong>otazník znamená, že je zatím
    nepotvrdil člověk</strong>. Dílo se s nimi počítá normálně, ale než seznam
    odevzdáš, hodí se je u sporných titulů ověřit ve
    <a href="<?= $this->e($canonDocumentUrl) ?>" target="_blank" rel="noopener">školním dokumentu</a>.
</p>

<h3>Export</h3>
<p class="muted">
    <a href="/export">Export</a> udělá PDF k vytištění. Sám si vybereš, co na
    stránce bude: jméno, školní rok, rozdělení podle kapitol, výpis kategorií
    u každého díla a shrnutí pravidel.
</p>

<h3>Účet</h3>
<p class="muted">
    Pod <a href="/ucet">Účet</a> najdeš svoje seznamy, změnu hesla a odhlášení.
    Seznam se ukládá průběžně, takže se k němu můžeš vrátit z jiného zařízení.
</p>

<h3>Něco nesedí?</h3>
<p class="muted">
    Když u díla sedí značka špatně nebo v kánonu nějaké dílo chybí, řekni to
    správci seznamu — opraví se to na jednom místě pro všechny.
</p>
