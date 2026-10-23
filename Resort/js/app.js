/* =========================================================
   Pergola Resort - mobile helpers (no dependencies)
   1. Hamburger menu for the top navbar (customer pages + landing page)
   2. Turns wide tables into stacked "cards" on phones (CSS does the layout)
   Include on any page:  <script src="js/responsive.js"></script>  (use ../js/ inside /customer or /admin)
   ========================================================= */
(function () {
    'use strict';

    /* ---------- 1. NAVBAR HAMBURGER ---------- */
    function initNav() {
        var nav = document.querySelector('.navbar');
        var links = nav && nav.querySelector('.nav-links');
        if (!nav || !links || nav.querySelector('.nav-toggle')) return;

        var box = nav.querySelector('.nav-container') || nav;
        if (!links.id) links.id = 'primaryNav';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'nav-toggle';
        btn.setAttribute('aria-label', 'Open menu');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', links.id);
        btn.innerHTML = '<span class="nav-bars" aria-hidden="true"><i></i><i></i><i></i></span>';
        box.insertBefore(btn, links);          // sits between brand and links, so it shows on the right

        function setOpen(open) {
            nav.classList.toggle('nav-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        }
        btn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            setOpen(!nav.classList.contains('nav-open'));
        });
        links.addEventListener('click', function (ev) {
            if (ev.target.closest && ev.target.closest('a')) setOpen(false);
        });
        document.addEventListener('click', function (ev) {
            if (nav.classList.contains('nav-open') && !nav.contains(ev.target)) setOpen(false);
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && nav.classList.contains('nav-open')) { setOpen(false); btn.focus(); }
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth > 860) setOpen(false);
        });
    }

    /* ---------- 2. RESPONSIVE TABLES ---------- */
    function initTables() {
        var tables = document.querySelectorAll('.table-wrap table, table.rtable');
        Array.prototype.forEach.call(tables, function (table) {
            if (table.classList.contains('m-table') ||        // calendar matrix must keep its grid
                table.classList.contains('pf-table') ||
                table.hasAttribute('data-no-stack') ||
                table.classList.contains('rtable')) return;

            var head = table.querySelector('thead tr');
            if (!head) return;
            var labels = Array.prototype.map.call(head.children, function (th) {
                return (th.textContent || '').replace(/\s+/g, ' ').trim();
            });

            Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (tr) {
                var i = 0;
                Array.prototype.forEach.call(tr.children, function (td) {
                    var span = parseInt(td.getAttribute('colspan') || '1', 10);
                    if (span > 1) { td.classList.add('rt-full'); i += span; return; }
                    td.setAttribute('data-label', labels[i] || '');
                    // cells holding forms/buttons need the whole card width
                    if (td.querySelector('form, select, input, textarea, button, .action-stack, .table-actions')) {
                        td.classList.add('rt-block');
                    }
                    i++;
                });
            });
            table.classList.add('rtable');
        });
    }

    function init() { initNav(); initTables(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();