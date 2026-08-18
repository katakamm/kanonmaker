/* Živé hledání. Stránka funguje i bez JavaScriptu - tohle jen ušetří odeslání formuláře. */
(function () {
    var input = document.getElementById('search-input');
    var box = document.getElementById('search-results');
    if (!input || !box) { return; }

    var timer = null;
    var lastQuery = null;

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function chip(tag) {
        return '<li class="chip chip--' + escapeHtml(tag.group) + '">' + escapeHtml(tag.label) + '</li>';
    }

    function render(data) {
        if (data.works.length === 0) {
            box.innerHTML = '<p class="empty">' + (data.q === '' ? 'Napiš, co hledáš.' : 'Nic jsme nenašli.') + '</p>';
            return;
        }

        var html = '<p class="muted">' + data.works.length + ' výsledků</p><ul class="works">';
        data.works.forEach(function (work) {
            html += '<li class="work"><div class="work__body">'
                + (work.authors ? '<div class="work__author">' + escapeHtml(work.authors) + '</div>' : '')
                + '<div class="work__title"><a href="/dilo/' + encodeURIComponent(work.id) + '" class="plain">'
                + escapeHtml(work.title) + '</a></div>'
                + '<ul class="chips">' + work.tags.map(chip).join('') + '</ul>'
                + '</div>'
                + '<div class="muted" style="align-self:center">' + (work.inList ? '✓' : '') + '</div>'
                + '</li>';
        });
        box.innerHTML = html + '</ul><p class="muted">Pro přidání do seznamu otevři dílo.</p>';
    }

    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            var q = input.value.trim();
            if (q === lastQuery) { return; }
            lastQuery = q;

            fetch('/hledat.json?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () { /* ticho - formulář pořád funguje */ });
        }, 200);
    });
})();

/* Rozbalení lišty s pravidly. Bez JavaScriptu zůstane panel zavřený,
   ale počet splněných pravidel je vidět i tak. */
(function () {
    var toggle = document.getElementById('rulebar-toggle');
    var panel = document.getElementById('rulebar-panel');
    if (!toggle || !panel) { return; }

    toggle.addEventListener('click', function () {
        var open = panel.hasAttribute('hidden');
        if (open) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.querySelector('[aria-hidden]').textContent = open ? '▼' : '▲';
    });
})();
