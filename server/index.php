<?php
/**
 * XTeInk Todo UI
 * Business demo mode (screenshots): ?demo=1 — fake list, no API writes
 */
if (is_readable(__DIR__ . '/bootstrap.php')) {
    require_once __DIR__ . '/bootstrap.php';
} elseif (!function_exists('xteink_config')) {
    function xteink_config(): array {
        $defaults = ['base_url' => '', 'api_token' => ''];
        $path = __DIR__ . '/config.php';
        if (!is_readable($path)) {
            return $defaults;
        }
        $loaded = require $path;
        return array_merge($defaults, is_array($loaded) ? $loaded : []);
    }
    function xteink_require_auth(): void {
    }
}
$demoMode = isset($_GET['demo']);
$apiToken = (string)(xteink_config()['api_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XTeInk — Todos<?= $demoMode ? ' (demo)' : '' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700;1,9..40,400&family=IBM+Plex+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #1a1f1c;
            --bg-2: #242b27;
            --ink: #e8efe9;
            --muted: #8a968c;
            --line: #3a453d;
            --accent: #c4f082;
            --accent-ink: #1a2214;
            --danger: #e8a0a0;
            --done: #6b756e;
            --font: "DM Sans", sans-serif;
            --mono: "IBM Plex Mono", monospace;
            --eink-bg: #e8e6df;
            --eink-ink: #111;
            /* Zone écran dans img/xteink.png (646×1016) */
            --screen-left: 11.76%;
            --screen-top: 6.3%;
            --screen-right: 11.92%;
            --screen-bottom: 13.09%;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: var(--font);
            color: var(--ink);
            background:
                radial-gradient(ellipse 80% 50% at 10% -10%, #2d3a28 0%, transparent 55%),
                radial-gradient(ellipse 60% 40% at 100% 100%, #1e2a30 0%, transparent 50%),
                var(--bg);
            padding: 2rem 1.25rem 4rem;
        }

        .page {
            width: min(560px, 100%);
            margin: 0 auto;
        }

        .panel { min-width: 0; }

        header { margin-bottom: 2rem; }

        .brand {
            font-family: var(--mono);
            font-size: 0.75rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--accent);
            margin-bottom: 0.5rem;
        }

        h1 {
            font-size: clamp(1.8rem, 4vw, 2.4rem);
            font-weight: 700;
            letter-spacing: -0.03em;
            line-height: 1.15;
        }

        .meta {
            margin-top: 0.6rem;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .meta strong { color: var(--ink); font-weight: 600; }

        .composer {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .composer input {
            flex: 1;
            background: var(--bg-2);
            border: 1px solid var(--line);
            border-radius: 10px;
            color: var(--ink);
            font: inherit;
            font-size: 1rem;
            padding: 0.9rem 1rem;
            outline: none;
        }

        .composer input:focus { border-color: var(--accent); }
        .composer input::placeholder { color: var(--muted); }

        button {
            font: inherit;
            cursor: pointer;
            border: none;
            border-radius: 10px;
            transition: transform 0.12s ease, opacity 0.12s ease;
        }

        button:active { transform: scale(0.97); }

        .btn-primary {
            background: var(--accent);
            color: var(--accent-ink);
            font-weight: 700;
            padding: 0.9rem 1.1rem;
            white-space: nowrap;
        }

        .btn-ghost {
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--line);
            padding: 0.45rem 0.75rem;
            font-size: 0.85rem;
        }

        .btn-ghost:hover { color: var(--ink); border-color: var(--muted); }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            gap: 0.75rem;
        }

        .toolbar h2 {
            font-size: 0.8rem;
            font-family: var(--mono);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 500;
        }

        .list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .item {
            display: grid;
            grid-template-columns: auto auto 1fr auto;
            align-items: center;
            gap: 0.65rem;
            background: color-mix(in oklab, var(--bg-2) 88%, black);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 0.85rem 0.9rem;
            animation: in 0.25s ease both;
            touch-action: manipulation;
        }

        .item.dragging { opacity: 0.45; }
        .item.drag-over {
            border-color: var(--accent);
            box-shadow: inset 0 0 0 1px var(--accent);
        }

        .grip {
            width: 1.5rem;
            height: 2rem;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--muted);
            cursor: grab;
            display: grid;
            place-items: center;
            padding: 0;
            font-size: 1rem;
            letter-spacing: -0.1em;
            line-height: 1;
            user-select: none;
        }

        .grip:active { cursor: grabbing; }
        .grip:hover { color: var(--ink); background: var(--bg); }

        @keyframes in {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: none; }
        }

        .item.done .text {
            color: var(--done);
            text-decoration: line-through;
            text-decoration-thickness: 1px;
        }

        .check {
            width: 1.35rem;
            height: 1.35rem;
            border-radius: 6px;
            border: 1.5px solid var(--muted);
            background: transparent;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            padding: 0;
        }

        .item.done .check {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--accent-ink);
        }

        .check svg { width: 0.85rem; height: 0.85rem; opacity: 0; }
        .item.done .check svg { opacity: 1; }

        .text {
            font-size: 1.05rem;
            line-height: 1.35;
            word-break: break-word;
        }

        .text[contenteditable="true"] {
            outline: 1px solid var(--accent);
            border-radius: 6px;
            padding: 0.15rem 0.35rem;
            margin: -0.15rem -0.35rem;
        }

        .actions {
            display: flex;
            gap: 0.25rem;
            opacity: 0.55;
        }

        .item:hover .actions,
        .item:focus-within .actions { opacity: 1; }

        .icon-btn {
            width: 2rem;
            height: 2rem;
            border-radius: 8px;
            background: transparent;
            color: var(--muted);
            display: grid;
            place-items: center;
            padding: 0;
        }

        .icon-btn:hover { background: var(--bg); color: var(--ink); }
        .icon-btn.danger:hover { color: var(--danger); }
        .icon-btn:disabled {
            opacity: 0.25;
            cursor: default;
            pointer-events: none;
        }

        .empty {
            border: 1px dashed var(--line);
            border-radius: 12px;
            padding: 2rem 1rem;
            text-align: center;
            color: var(--muted);
        }

        .status {
            margin-top: 1.25rem;
            font-family: var(--mono);
            font-size: 0.75rem;
            color: var(--muted);
            min-height: 1.2em;
        }

        .status.error { color: var(--danger); }

        .hint {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--line);
            font-size: 0.85rem;
            color: var(--muted);
            line-height: 1.5;
        }

        .hint code {
            font-family: var(--mono);
            font-size: 0.8em;
            color: var(--accent);
        }

        /* Preview desktop — masqué sur mobile */
        .preview-stage {
            display: none;
        }

        @media (min-width: 1100px) {
            body {
                padding: 2rem 2rem 3rem;
            }

            .page {
                width: min(1180px, 100%);
                display: grid;
                grid-template-columns: minmax(420px, 560px) minmax(320px, 420px);
                gap: 3rem;
                align-items: start;
                justify-content: center;
            }

            .preview-stage {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.85rem;
                position: sticky;
                top: 0;
                align-self: start;
                height: calc(100vh - 4rem);
            }

            .device {
                position: relative;
                width: min(100%, 380px);
                filter: drop-shadow(0 24px 48px rgba(0, 0, 0, 0.55));
            }

            .device > img {
                display: block;
                width: 100%;
                height: auto;
                user-select: none;
                pointer-events: none;
            }

            .eink {
                position: absolute;
                left: var(--screen-left);
                top: var(--screen-top);
                right: var(--screen-right);
                bottom: var(--screen-bottom);
                background: var(--eink-bg);
                color: var(--eink-ink);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                padding: 6.5% 6.25% 4%;
                /* ratio proche 480×800 */
                container-type: size;
            }

            .eink-head {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 0.5rem;
                flex-shrink: 0;
            }

            .eink-title {
                font-family: var(--mono);
                font-weight: 700;
                font-size: 12.5cqw;
                letter-spacing: 0.02em;
                line-height: 1;
            }

            .eink-count {
                font-family: var(--mono);
                font-weight: 500;
                font-size: 5.2cqw;
                white-space: nowrap;
            }

            .eink-rule {
                height: 1.5px;
                background: var(--eink-ink);
                margin: 3.5% 0 7%;
                flex-shrink: 0;
            }

            .eink-list {
                --eink-count: 1;
                --eink-row-max: 13%;
                list-style: none;
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 0;
                min-height: 0;
            }

            .eink-item {
                display: grid;
                grid-template-columns: 7.2cqw 1fr;
                gap: 3.8cqw;
                align-items: center;
                height: min(var(--eink-row-max), calc(100% / var(--eink-count)));
                min-height: 0;
            }

            .eink-box {
                width: 7.2cqw;
                height: 7.2cqw;
                border: 2px solid var(--eink-ink);
                position: relative;
                flex-shrink: 0;
                display: grid;
                place-items: center;
            }

            .eink-box svg {
                width: 78%;
                height: 78%;
                display: none;
            }

            .eink-item.done .eink-box svg {
                display: block;
            }

            .eink-text {
                font-family: Helvetica, "Helvetica Neue", Arial, sans-serif;
                font-weight: 700;
                font-size: 7.6cqw;
                line-height: 1.15;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: clip;
                justify-self: start;
                max-width: 100%;
            }

            .eink-item.done .eink-text {
                text-decoration: line-through;
                text-decoration-thickness: 1.5px;
                text-underline-offset: 0;
            }

            .eink-empty {
                font-family: var(--mono);
                font-size: 6.5cqw;
                line-height: 1.45;
                padding-top: 12%;
            }

            .eink-foot {
                flex-shrink: 0;
                margin-top: auto;
                padding-top: 2%;
                display: flex;
                justify-content: space-between;
                gap: 0.35rem;
                font-family: var(--mono);
                font-size: 4.6cqw;
                font-weight: 500;
            }

            .preview-nav {
                display: none;
                align-items: center;
                gap: 0.5rem;
            }

            .preview-nav.is-visible {
                display: flex;
            }

            .preview-nav button {
                background: var(--bg-2);
                border: 1px solid var(--line);
                color: var(--ink);
                width: 2.4rem;
                height: 2.4rem;
                border-radius: 8px;
                font-size: 1rem;
            }

            .preview-nav button:disabled {
                opacity: 0.3;
                cursor: default;
            }

            .preview-nav span {
                font-family: var(--mono);
                font-size: 0.75rem;
                color: var(--muted);
                min-width: 4.5rem;
                text-align: center;
            }

            .preview-label {
                font-family: var(--mono);
                font-size: 0.7rem;
                letter-spacing: 0.1em;
                text-transform: uppercase;
                color: var(--muted);
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="panel">
            <header>
                <p class="brand">XTeInk</p>
                <h1>TODO list</h1>
                <p class="meta"><strong id="pendingCount">0</strong> open · <span id="totalCount">0</span> total</p>
            </header>

            <form class="composer" id="addForm">
                <input id="newTodo" type="text" maxlength="120" placeholder="New task…" autocomplete="off" autofocus>
                <button class="btn-primary" type="submit">Add</button>
            </form>

            <div class="toolbar">
                <h2>List</h2>
                <button type="button" class="btn-ghost" id="clearDone">Clear done</button>
            </div>

            <ul class="list" id="list"></ul>
            <p class="status" id="status" aria-live="polite"></p>

            <p class="hint">
                Drag tasks with <strong>⋮⋮</strong> (or ↑ ↓) to reorder.
                <br>The XTeInk fetches tasks on wake — press CONFIRM to force a refresh.
            </p>
        </div>

        <aside class="preview-stage" aria-label="XTeInk preview">
            <p class="preview-label">Live preview — <span class="brand">XTeInk</span></p>
            <div class="device">
                <img src="img/xteink.png" alt="XTeInk X4" width="646" height="1016">
                <div class="eink" id="eink">
                    <div class="eink-head">
                        <span class="eink-title">TODO</span>
                        <span class="eink-count" id="einkCount">0/0</span>
                    </div>
                    <div class="eink-rule"></div>
                    <ul class="eink-list" id="einkList"></ul>
                    <div class="eink-foot">
                        <span>Bat:95%</span>
                        <span>WiFi:OK</span>
                        <span id="einkMaj">Upd:--:-- M</span>
                    </div>
                </div>
            </div>
            <div class="preview-nav">
                <button type="button" id="prevPage" aria-label="Previous page">‹</button>
                <span id="pageLabel">p1/1</span>
                <button type="button" id="nextPage" aria-label="Next page">›</button>
            </div>
        </aside>
    </div>

    <script>
        const API = 'todos.php';
        const API_TOKEN = <?= json_encode($apiToken, JSON_UNESCAPED_UNICODE) ?>;
        const TODOS_PER_PAGE = 10;
        const DEMO_MODE = <?= $demoMode ? 'true' : 'false' ?>;
        const listEl = document.getElementById('list');
        const statusEl = document.getElementById('status');
        const pendingEl = document.getElementById('pendingCount');
        const totalEl = document.getElementById('totalCount');
        const einkList = document.getElementById('einkList');
        const einkCount = document.getElementById('einkCount');
        const einkMaj = document.getElementById('einkMaj');
        const pageLabel = document.getElementById('pageLabel');
        const prevPageBtn = document.getElementById('prevPage');
        const nextPageBtn = document.getElementById('nextPage');

        let todos = [];
        let dragId = null;
        let previewPage = 0;

        /** Fake business list — screenshots / demo only */
        const DEMO_TODOS = [
            { id: 'demo01', text: 'Atlas project kickoff', done: false },
            { id: 'demo02', text: 'Sprint backlog review', done: false },
            { id: 'demo03', text: 'Northwind client sync', done: false },
            { id: 'demo04', text: 'KPI dashboard mockup', done: false },
            { id: 'demo05', text: 'VC demo prep', done: false },
            { id: 'demo07', text: 'iOS app QA', done: false },
            { id: 'demo08', text: 'Q3 campaign brief', done: true },
        ];

        const checkSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`;

        function setStatus(msg, isError = false) {
            statusEl.textContent = msg || '';
            statusEl.classList.toggle('error', isError);
        }

        function demoSnapshot() {
            return {
                todos: todos.map(t => ({ ...t })),
                updated_at: new Date().toISOString(),
            };
        }

        async function api(body = null) {
            if (DEMO_MODE) {
                if (!body) return demoSnapshot();
                applyDemoAction(body);
                return { ok: true, ...demoSnapshot() };
            }
            const headers = {};
            if (API_TOKEN) headers['X-Api-Token'] = API_TOKEN;
            const opts = body
                ? { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
                : { method: 'GET', headers };
            const res = await fetch(API, opts);
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'API error');
            return data;
        }

        function applyDemoAction(payload) {
            const action = payload.action;
            if (action === 'add') {
                const text = String(payload.text || '').trim();
                if (!text) throw new Error('Text required');
                todos.unshift({
                    id: 'demo' + Date.now().toString(36),
                    text: text.slice(0, 120),
                    done: false,
                    created_at: new Date().toISOString(),
                });
            } else if (action === 'toggle') {
                const t = todos.find(x => x.id === payload.id);
                if (t) t.done = !t.done;
            } else if (action === 'update') {
                const t = todos.find(x => x.id === payload.id);
                const text = String(payload.text || '').trim();
                if (t && text) t.text = text.slice(0, 120);
            } else if (action === 'delete') {
                todos = todos.filter(x => x.id !== payload.id);
            } else if (action === 'clear_done') {
                todos = todos.filter(x => !x.done);
            } else if (action === 'reorder' && Array.isArray(payload.ids)) {
                const byId = Object.fromEntries(todos.map(t => [t.id, t]));
                const next = [];
                for (const id of payload.ids) {
                    if (byId[id]) {
                        next.push(byId[id]);
                        delete byId[id];
                    }
                }
                todos = next.concat(Object.values(byId));
            }
        }

        function escapeHtml(str) {
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function indexOfId(id) {
            return todos.findIndex(t => t.id === id);
        }

        function placeCaretFromClick(el, e) {
            const sel = window.getSelection();
            sel.removeAllRanges();
            let range = null;
            if (document.caretRangeFromPoint) {
                range = document.caretRangeFromPoint(e.clientX, e.clientY);
            } else if (document.caretPositionFromPoint) {
                const pos = document.caretPositionFromPoint(e.clientX, e.clientY);
                if (pos) {
                    range = document.createRange();
                    range.setStart(pos.offsetNode, pos.offset);
                    range.collapse(true);
                }
            }
            if (range && el.contains(range.startContainer)) {
                sel.addRange(range);
                return;
            }
            range = document.createRange();
            range.selectNodeContents(el);
            range.collapse(false);
            sel.addRange(range);
        }

        /** Ordre d'affichage device : pending d'abord (comme le firmware) */
        function displayTodos() {
            return [...todos].sort((a, b) => Number(a.done) - Number(b.done));
        }

        function pageCount() {
            const n = displayTodos().length;
            return n > 0 ? Math.ceil(n / TODOS_PER_PAGE) : 1;
        }

        function renderPreview() {
            const sorted = displayTodos();
            const pending = sorted.filter(t => !t.done).length;
            const pages = pageCount();
            if (previewPage >= pages) previewPage = pages - 1;
            if (previewPage < 0) previewPage = 0;

            let countText = `${pending}/${sorted.length}`;
            if (pages > 1) countText += `  p${previewPage + 1}/${pages}`;
            einkCount.textContent = countText;
            pageLabel.textContent = `p${previewPage + 1}/${pages}`;
            prevPageBtn.disabled = previewPage <= 0;
            nextPageBtn.disabled = previewPage >= pages - 1;
            document.querySelector('.preview-nav').classList.toggle('is-visible', pages > 1);

            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            einkMaj.textContent = `Upd:${hh}:${mm} M`;

            if (!sorted.length) {
                einkList.style.removeProperty('--eink-count');
                einkList.innerHTML = `<li class="eink-empty">Empty list<br>Add tasks<br>on the web UI</li>`;
                return;
            }

            const start = previewPage * TODOS_PER_PAGE;
            const pageItems = sorted.slice(start, start + TODOS_PER_PAGE);
            einkList.style.setProperty('--eink-count', String(pageItems.length));
            const checkMark = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 12 10 18 20 6"/></svg>`;
            einkList.innerHTML = pageItems.map(todo => `
                <li class="eink-item ${todo.done ? 'done' : ''}">
                    <span class="eink-box">${todo.done ? checkMark : ''}</span>
                    <span class="eink-text">${escapeHtml(todo.text)}</span>
                </li>
            `).join('');
        }

        async function saveOrder() {
            await mutate({ action: 'reorder', ids: todos.map(t => t.id) });
        }

        async function moveTodo(id, toIndex) {
            const from = indexOfId(id);
            if (from < 0) return;
            const clamped = Math.max(0, Math.min(todos.length - 1, toIndex));
            if (from === clamped) return;
            const [item] = todos.splice(from, 1);
            todos.splice(clamped, 0, item);
            render();
            try {
                await saveOrder();
            } catch (err) {
                setStatus(err.message, true);
                await refresh();
            }
        }

        function render() {
            const pending = todos.filter(t => !t.done).length;
            pendingEl.textContent = String(pending);
            totalEl.textContent = String(todos.length);

            if (!todos.length) {
                listEl.innerHTML = `<li class="empty">No tasks yet.</li>`;
            } else {
                listEl.innerHTML = todos.map((todo, i) => `
                    <li class="item ${todo.done ? 'done' : ''}" data-id="${todo.id}" draggable="false">
                        <button class="grip" type="button" draggable="true" title="Drag to reorder" aria-label="Reorder">⋮⋮</button>
                        <button class="check" type="button" data-action="toggle" aria-label="Toggle">${checkSvg}</button>
                        <span class="text" data-action="edit">${escapeHtml(todo.text)}</span>
                        <div class="actions">
                            <button class="icon-btn" type="button" data-action="up" aria-label="Move up" title="Move up" ${i === 0 ? 'disabled' : ''}>↑</button>
                            <button class="icon-btn" type="button" data-action="down" aria-label="Move down" title="Move down" ${i === todos.length - 1 ? 'disabled' : ''}>↓</button>
                            <button class="icon-btn danger" type="button" data-action="delete" aria-label="Delete" title="Delete">✕</button>
                        </div>
                    </li>
                `).join('');
            }

            renderPreview();
        }

        async function refresh() {
            if (DEMO_MODE) {
                todos = DEMO_TODOS.map(t => ({ ...t }));
                render();
                setStatus('Demo mode — fake list (nothing is saved)');
                return;
            }
            const data = await api();
            todos = data.todos || [];
            render();
            if (data.updated_at) {
                const d = new Date(data.updated_at);
                setStatus(`Synced ${d.toLocaleString('en-US')}`);
            }
        }

        async function mutate(payload) {
            setStatus(DEMO_MODE ? 'Updating demo…' : 'Saving…');
            const data = await api(payload);
            todos = data.todos || [];
            render();
            setStatus(
                DEMO_MODE
                    ? 'Demo mode — local changes only'
                    : 'Saved — XTeInk will update on next fetch'
            );
        }

        document.getElementById('addForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = document.getElementById('newTodo');
            const text = input.value.trim();
            if (!text) return;
            try {
                previewPage = 0;
                await mutate({ action: 'add', text });
                input.value = '';
                input.focus();
            } catch (err) {
                setStatus(err.message, true);
            }
        });

        document.getElementById('clearDone').addEventListener('click', async () => {
            if (!todos.some(t => t.done)) return;
            try {
                await mutate({ action: 'clear_done' });
            } catch (err) {
                setStatus(err.message, true);
            }
        });

        prevPageBtn.addEventListener('click', () => {
            if (previewPage > 0) {
                previewPage--;
                renderPreview();
            }
        });

        nextPageBtn.addEventListener('click', () => {
            if (previewPage < pageCount() - 1) {
                previewPage++;
                renderPreview();
            }
        });

        listEl.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn || btn.disabled) return;
            const item = btn.closest('.item');
            if (!item) return;
            const id = item.dataset.id;
            const action = btn.dataset.action;
            const idx = indexOfId(id);

            try {
                if (action === 'toggle') {
                    await mutate({ action: 'toggle', id });
                } else if (action === 'delete') {
                    await mutate({ action: 'delete', id });
                } else if (action === 'up') {
                    await moveTodo(id, idx - 1);
                } else if (action === 'down') {
                    await moveTodo(id, idx + 1);
                } else if (action === 'edit') {
                    const span = item.querySelector('.text');
                    if (span.isContentEditable) return;
                    span.contentEditable = 'true';
                    span.focus();
                    placeCaretFromClick(span, e);

                    const finish = async (save) => {
                        span.contentEditable = 'false';
                        span.removeEventListener('blur', onBlur);
                        span.removeEventListener('keydown', onKey);
                        if (!save) {
                            render();
                            return;
                        }
                        const text = span.textContent.trim();
                        if (!text) {
                            render();
                            return;
                        }
                        await mutate({ action: 'update', id, text });
                    };
                    const onBlur = () => finish(true);
                    const onKey = (ev) => {
                        if (ev.key === 'Enter') {
                            ev.preventDefault();
                            finish(true);
                        } else if (ev.key === 'Escape') {
                            ev.preventDefault();
                            finish(false);
                        }
                    };
                    span.addEventListener('blur', onBlur);
                    span.addEventListener('keydown', onKey);
                }
            } catch (err) {
                setStatus(err.message, true);
            }
        });

        listEl.addEventListener('dragstart', (e) => {
            const grip = e.target.closest('.grip');
            if (!grip) {
                e.preventDefault();
                return;
            }
            const item = grip.closest('.item');
            if (!item) return;
            dragId = item.dataset.id;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragId);
            e.dataTransfer.setDragImage(item, 24, 24);
        });

        listEl.addEventListener('dragend', () => {
            dragId = null;
            listEl.querySelectorAll('.item').forEach(el => {
                el.classList.remove('dragging', 'drag-over');
            });
        });

        listEl.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const over = e.target.closest('.item');
            listEl.querySelectorAll('.item').forEach(el => el.classList.remove('drag-over'));
            if (over && over.dataset.id !== dragId) {
                over.classList.add('drag-over');
            }
        });

        listEl.addEventListener('drop', async (e) => {
            e.preventDefault();
            const over = e.target.closest('.item');
            const id = dragId || e.dataTransfer.getData('text/plain');
            listEl.querySelectorAll('.item').forEach(el => el.classList.remove('drag-over', 'dragging'));
            if (!over || !id || over.dataset.id === id) return;
            await moveTodo(id, indexOfId(over.dataset.id));
        });

        refresh().catch(err => setStatus(err.message, true));
    </script>
</body>
</html>
