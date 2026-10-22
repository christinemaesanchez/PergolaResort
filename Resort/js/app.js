/* =========================================================
   Pergola Resort - shared front-end behaviour (admin + customer)
   1. Notification bell (reads notifications.php)
   2. Collapsible admin sidebar (icons, tooltips, remembers state)
   3. Click-to-view accommodation gallery (lightbox)
   Loaded with: <script src=".../js/app.js" data-base="../" data-role="admin"></script>
   ========================================================= */
(function () {
    'use strict';

    var me = document.currentScript;
    var BASE = (me && me.getAttribute('data-base')) || '';   // path from the current page to the project root
    var ROLE = (me && me.getAttribute('data-role')) || '';

    function el(tag, cls, text) {
        var x = document.createElement(tag);
        if (cls) x.className = cls;
        if (text !== undefined) x.textContent = text;
        return x;
    }
    var ICONS = {
        bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        dashboard: '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>',
        reservations: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        accommodations: '<path d="M3 21V7l9-4 9 4v14"/><path d="M9 21v-6h6v6"/>',
        customers: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        resort: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        reports: '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
        chevrons: '<path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/>'
    };
    function icon(name) {
        var s = el('span', 'ui-ico');
        s.setAttribute('aria-hidden', 'true');
        s.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || '') + '</svg>';
        return s;
    }

    /* ---------------------------------------------------------
       1. NOTIFICATION BELL
    --------------------------------------------------------- */
    function initBell() {
        var host, wrap = el('div', 'nf-wrap');
        var btn = el('button', 'nf-btn');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Notifications');
        btn.setAttribute('aria-haspopup', 'true');
        btn.setAttribute('aria-expanded', 'false');
        btn.appendChild(icon('bell'));
        var badge = el('span', 'nf-badge', '0');
        badge.hidden = true;
        btn.appendChild(badge);

        var panel = el('div', 'nf-panel');
        panel.hidden = true;
        var head = el('div', 'nf-head');
        head.appendChild(el('strong', '', 'Notifications'));
        var markAll = el('button', 'nf-markall', 'Mark all read');
        markAll.type = 'button';
        head.appendChild(markAll);
        var list = el('div', 'nf-list');
        panel.appendChild(head);
        panel.appendChild(list);
        wrap.appendChild(btn);
        wrap.appendChild(panel);

        var topbar = document.querySelector('.topbar');
        var navLinks = document.querySelector('.nav-links');
        if (topbar) {                                   // ADMIN: group bell + menu button on the right
            var actions = el('div', 'topbar-actions');
            var menuBtn = topbar.querySelector('.menu-btn');
            topbar.appendChild(actions);
            actions.appendChild(wrap);
            if (menuBtn) actions.appendChild(menuBtn);
            wrap.classList.add('nf-admin');
        } else if (navLinks) {                          // CUSTOMER: bell sits before "Sign Out"
            var last = navLinks.querySelector('.nav-btn-outline');
            wrap.classList.add('nf-customer');
            navLinks.insertBefore(wrap, last || null);
        } else {
            return;
        }

        var csrf = '';
        function render(d) {
            csrf = d.csrf || csrf;
            badge.hidden = !(d.unread > 0);
            badge.textContent = d.unread > 99 ? '99+' : String(d.unread);
            btn.setAttribute('aria-label', 'Notifications' + (d.unread > 0 ? ' (' + d.unread + ' unread)' : ''));
            list.innerHTML = '';
            if (!d.items.length) {
                list.appendChild(el('div', 'nf-empty', 'No notifications yet.'));
                return;
            }
            d.items.forEach(function (n) {
                var item = el('button', 'nf-item' + (n.is_read ? '' : ' unread'));
                item.type = 'button';
                item.appendChild(el('span', 'nf-title', n.title));
                item.appendChild(el('span', 'nf-msg', n.message));
                item.appendChild(el('span', 'nf-time', n.time));
                item.addEventListener('click', function () {
                    post({ action: 'read', id: n.id }).then(function () {
                        if (n.link) window.location.href = BASE + n.link;
                        else load();
                    });
                });
                list.appendChild(item);
            });
        }
        function load() {
            return fetch(BASE + 'notifications.php', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (d && d.ok) render(d); })
                .catch(function () {});
        }
        function post(data) {
            var fd = new FormData();
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
            fd.append('csrf', csrf);
            return fetch(BASE + 'notifications.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.ok) render(d); })
                .catch(function () {});
        }
        function setOpen(open) {
            panel.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) load();
        }
        btn.addEventListener('click', function (ev) { ev.stopPropagation(); setOpen(panel.hidden); });
        markAll.addEventListener('click', function () { post({ action: 'read_all' }); });
        panel.addEventListener('click', function (ev) { ev.stopPropagation(); });
        document.addEventListener('click', function () { if (!panel.hidden) setOpen(false); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !panel.hidden) { setOpen(false); btn.focus(); } });

        load();
        // Light refresh: once a minute, only while the tab is visible
        setInterval(function () { if (!document.hidden && panel.hidden) load(); }, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
    }

    /* ---------------------------------------------------------
       2. COLLAPSIBLE ADMIN SIDEBAR
    --------------------------------------------------------- */
    function initSidebar() {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        var root = document.documentElement;
        var KEY = 'pergolaSidebar';
        var mobile = window.matchMedia('(max-width: 768px)');

        // icons + labels + tooltips on the existing links (hrefs/order untouched)
        sidebar.querySelectorAll('.sidebar-nav a, .logout-link').forEach(function (a) {
            if (a.querySelector('.nav-label')) return;
            var key = (a.getAttribute('href') || '').replace(/\.php.*$/, '').replace(/^.*\//, '');
            var name = a.classList.contains('logout-link') ? 'logout' : key;
            var label = a.textContent.trim();
            a.textContent = '';
            a.appendChild(icon(name));
            a.appendChild(el('span', 'nav-label', label));
            a.setAttribute('data-tip', label);
            a.setAttribute('aria-label', label);
        });

        // desktop collapse button
        var toggle = el('button', 'sb-toggle');
        toggle.type = 'button';
        toggle.setAttribute('aria-label', 'Collapse or expand sidebar');
        toggle.appendChild(icon('chevrons'));
        var brand = sidebar.querySelector('.sidebar-brand');
        if (brand) brand.appendChild(toggle); else sidebar.insertBefore(toggle, sidebar.firstChild);

        function applyCollapsed(c) {
            root.classList.toggle('sb-collapsed', c);
            toggle.setAttribute('aria-expanded', c ? 'false' : 'true');
        }
        var saved = false;
        try { saved = localStorage.getItem(KEY) === '1'; } catch (e) {}
        applyCollapsed(saved && !mobile.matches);

        toggle.addEventListener('click', function () {
            var c = !root.classList.contains('sb-collapsed');
            applyCollapsed(c);
            try { localStorage.setItem(KEY, c ? '1' : '0'); } catch (e) {}
        });
        mobile.addEventListener('change', function () {
            sidebar.classList.remove('open');
            applyCollapsed(!mobile.matches && saved);
        });

        // mobile: drawer with a backdrop (existing "Menu" button keeps toggling .open)
        var backdrop = el('div', 'sb-backdrop');
        document.body.appendChild(backdrop);
        function syncBackdrop() { backdrop.classList.toggle('show', sidebar.classList.contains('open') && mobile.matches); }
        new MutationObserver(syncBackdrop).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        backdrop.addEventListener('click', function () { sidebar.classList.remove('open'); });
        sidebar.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { sidebar.classList.remove('open'); }); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') sidebar.classList.remove('open'); });
    }

    /* ---------------------------------------------------------
       3. ACCOMMODATION GALLERY + LIGHTBOX
       Markup: <div class="acc-gallery" data-images='[{"src":"..","alt":".."}]'> with
               <button data-i="0"> triggers inside.
    --------------------------------------------------------- */
    function initGallery() {
        var galleries = document.querySelectorAll('.acc-gallery[data-images]');
        if (!galleries.length) return;

        var box = el('div', 'lb');
        box.hidden = true;
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-label', 'Image viewer');
        var closeB = el('button', 'lb-close', '\u00d7'); closeB.type = 'button'; closeB.setAttribute('aria-label', 'Close viewer');
        var prevB = el('button', 'lb-nav lb-prev', '\u2039'); prevB.type = 'button'; prevB.setAttribute('aria-label', 'Previous image');
        var nextB = el('button', 'lb-nav lb-next', '\u203a'); nextB.type = 'button'; nextB.setAttribute('aria-label', 'Next image');
        var fig = el('figure', 'lb-fig');
        var img = el('img', 'lb-img');
        var cap = el('figcaption', 'lb-cap');
        var count = el('div', 'lb-count');
        fig.appendChild(img); fig.appendChild(cap);
        [closeB, prevB, fig, nextB, count].forEach(function (n) { box.appendChild(n); });
        document.body.appendChild(box);

        var imgs = [], idx = 0, opener = null, startX = null;
        function show(i) {
            idx = (i + imgs.length) % imgs.length;
            img.src = imgs[idx].src;
            img.alt = imgs[idx].alt || '';
            cap.textContent = imgs[idx].alt || '';
            var many = imgs.length > 1;
            prevB.hidden = nextB.hidden = count.hidden = !many;
            count.textContent = (idx + 1) + ' of ' + imgs.length;
        }
        function open(list, i, from) {
            imgs = list; opener = from;
            show(i);
            box.hidden = false;
            document.body.classList.add('lb-open');
            closeB.focus();
        }
        function close() {
            box.hidden = true;
            document.body.classList.remove('lb-open');
            img.removeAttribute('src');
            if (opener) opener.focus();
        }
        closeB.addEventListener('click', close);
        prevB.addEventListener('click', function () { show(idx - 1); });
        nextB.addEventListener('click', function () { show(idx + 1); });
        box.addEventListener('click', function (ev) { if (ev.target === box || ev.target === fig) close(); });
        document.addEventListener('keydown', function (ev) {
            if (box.hidden) return;
            if (ev.key === 'Escape') close();
            else if (ev.key === 'ArrowLeft' && imgs.length > 1) show(idx - 1);
            else if (ev.key === 'ArrowRight' && imgs.length > 1) show(idx + 1);
        });
        box.addEventListener('touchstart', function (ev) { startX = ev.touches[0].clientX; }, { passive: true });
        box.addEventListener('touchend', function (ev) {
            if (startX === null || imgs.length < 2) return;
            var dx = ev.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 50) show(dx < 0 ? idx + 1 : idx - 1);
            startX = null;
        }, { passive: true });

        galleries.forEach(function (g) {
            var list = [];
            try { list = JSON.parse(g.getAttribute('data-images')) || []; } catch (e) {}
            if (!list.length) return;
            var main = g.querySelector('.acc-main');
            var thumbs = g.querySelectorAll('.acc-thumb');
            var cur = 0;
            function setMain(i) {
                cur = i;
                if (main) { var im = main.querySelector('img'); if (im) { im.src = list[i].src; im.alt = list[i].alt || ''; } }
                thumbs.forEach(function (t, k) { t.classList.toggle('active', k === i); });
            }
            if (main) main.addEventListener('click', function () { open(list, cur, main); });
            thumbs.forEach(function (t) {
                t.addEventListener('click', function () { setMain(parseInt(t.getAttribute('data-i'), 10) || 0); });
            });
        });
    }

    function boot() {
        if (ROLE === 'admin' || document.getElementById('sidebar')) initSidebar();
        if (ROLE) initBell();
        initGallery();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();