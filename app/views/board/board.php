<?php /** The board (screen `board`). Data: view, board (columns/lanes), filters (assignee, priority[], q, swimlane), assignees, moves, expand, query, here, tz, notice, capped */
$viewParam = $view['system_key'] ?? (string) $view['view_id']; $regionUrl = '/board?' . http_build_query($query);
$colsHtml = static fn (array $cols, ?string $lane) => implode('', array_map(static fn (array $c): string => view('board/partials/column.php', ['col' => $c, 'lane' => $lane, 'field' => $board['column_field'], 'view' => $view, 'moves' => $moves, 'expand' => $expand, 'query' => $query, 'tz' => $tz, 'here' => $here]), $cols)); ?>
<?= view('shared/header.php', ['id' => 'board', 'title' => $view['name'], 'crumbs' => [['Home', '/'], ['Inbox', '/inbox'], [$view['name'], null]],
    'action' => '<div class="d-flex gap-1">' . ((has_right('views.personal') || has_right('views.shared')) ? hx_link('/views/new?layout=board&from=' . rawurlencode(http_build_query(array_diff_key($query, ['view' => 1]))) . '&view=' . rawurlencode($viewParam), '<i class="feather-save"></i><span class="d-none d-md-inline ms-1">Save as view</span>', 'btn btn-light btn-touch', 'id="board-save-view-link" title="Save as view" aria-label="Save as view"') : '')
                . hx_link('/inbox?view=' . rawurlencode($viewParam), '<i class="feather-list"></i><span class="d-none d-md-inline ms-1">List</span>', 'btn btn-light btn-touch', 'id="board-list-link" title="List" aria-label="List"') . '</div>']) ?>
<div class="main-content" id="board-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/board" hx-get="/board" hx-target="#board-columns" hx-select="#board-columns" hx-swap="outerHTML" hx-push-url="true" hx-sync="#board-columns:replace" hx-trigger="submit, change from:#board-filter-swimlane, change from:#board-filter-assignee, change from:.board-priority-chip" class="card mb-3" id="board-filters"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="view" value="<?= e($viewParam) ?>">
        <input type="search" name="q" id="board-filter-q" class="form-control btn-touch flex-grow-1" placeholder="Number, subject or requester…" value="<?= e($filters['q']) ?>" maxlength="80" aria-label="Search">
        <select name="assignee" id="board-filter-assignee" class="form-select btn-touch w-auto" aria-label="Assignee"><option value="">Anyone</option><option value="me" <?= $filters['assignee'] === 'me' ? 'selected' : '' ?>>Me</option><option value="none" <?= $filters['assignee'] === 'none' ? 'selected' : '' ?>>Nobody</option><?php foreach ($assignees as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= $filters['assignee'] === (string) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?><?= $m['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select>
        <div class="d-flex gap-1" id="board-filter-priority"><?php foreach (VIEW_PRIORITIES as $p): ?><label class="btn btn-light btn-touch chip-check position-relative mb-0" for="board-filter-priority-<?= e($p) ?>"><input type="checkbox" class="board-priority-chip" name="priority[]" value="<?= e($p) ?>" id="board-filter-priority-<?= e($p) ?>" <?= in_array($p, $filters['priority'], true) ? 'checked' : '' ?>><?= e(ucfirst($p)) ?></label><?php endforeach; ?></div>
        <select name="swimlane" id="board-filter-swimlane" class="form-select btn-touch w-auto" aria-label="Swimlanes"><?php foreach (BOARD_SWIMLANES as $k => $l): ?><option value="<?= $k === '' ? 'none' : e($k) ?>" <?= ($filters['swimlane'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-light btn-touch" id="board-filter-btn" aria-label="Search"><i class="feather-search"></i></button>
    </div></form>
    <div id="board-columns" hx-get="<?= e($regionUrl) ?>" hx-select="#board-columns" hx-swap="outerHTML" hx-target="this" hx-trigger="every 30s [document.visibilityState=='visible' &amp;&amp; !document.body.hasAttribute('data-dragging')], ticketChanged from:body"
         data-view="<?= e($viewParam) ?>" data-column-field="<?= e($board['column_field']) ?>">
        <?php if ($capped): ?><div class="alert alert-warning fs-12 py-2" id="board-capped">The board shows the first 500 cards — narrow the view to see the rest.</div><?php endif; ?>
        <?php if ($board['lanes'] !== null): ?>
            <?php foreach ($board['lanes'] as $lane): ?>
                <div class="mb-3" id="board-lane-<?= e($lane['key']) ?>"><div class="fw-bold fs-12 mb-2 board-lane-header" id="board-lane-<?= e($lane['key']) ?>-header"><?= e($lane['label']) ?></div><div class="board-strip"><?= $colsHtml($lane['columns'], $lane['key']) ?></div></div>
            <?php endforeach; ?>
            <?php if ($board['lanes'] === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="board-empty">Nothing in this view.</div></div><?php endif; ?>
        <?php else: ?>
            <div class="board-strip"><?= $colsHtml($board['columns'], null) ?></div>
        <?php endif; ?>
    </div>
    <script>
    /* Drag-and-drop is an enhancement over each card's "Move to…" select (a request from inside the region must select .board-card — it inherits the region's hx-select otherwise and the card would vanish): a drop into another column POSTs board_move_card (the card is the target — a required field comes back as the
     * card expanded, a refusal springs it back and re-fetches the region); a drop within a column POSTs board_reorder. The 30 s refresh waits while a drag is in progress. */
    (function () {
        window.bindBoard = function () {
            if (!window.Sortable) return;
            document.querySelectorAll('[data-board-droppable]').forEach(function (col) {
                if (col.__hdSortable) return;
                col.__hdSortable = Sortable.create(col, {
                    group: 'board', animation: 120, draggable: '.board-card', filter: 'select, option, a, button, input, details, summary, form', preventOnFilter: false, delay: 120, delayOnTouchOnly: true,
                    onStart: function () { document.body.setAttribute('data-dragging', '1'); },
                    onEnd: function (evt) {
                        document.body.removeAttribute('data-dragging');
                        var card = evt.item, to = evt.to, from = evt.from, region = document.getElementById('board-columns');
                        var view = region ? region.dataset.view : '';
                        if (!window.htmx) return;
                        if (to !== from) {
                            card.__hdFrom = { col: from, index: evt.oldIndex };
                            htmx.ajax('POST', '/board/move.php', { source: card, target: '#' + card.id, swap: 'outerHTML', select: '.board-card', values: { ticket: card.dataset.ticketId, column: to.dataset.column, position: evt.newIndex, view: view } });
                        } else if (evt.oldIndex !== evt.newIndex) {
                            htmx.ajax('POST', '/board/reorder.php', { source: card, target: '#flash', swap: 'none', values: { ticket: card.dataset.ticketId, position: evt.newIndex, view: view } });
                        }
                    }
                });
            });
        };
        if (!window.__hdBoardListeners) {
            window.__hdBoardListeners = true;
            document.body.addEventListener('htmx:afterRequest', function (e) {
                var xhr = e.detail && e.detail.xhr, path = (e.detail && e.detail.pathInfo && e.detail.pathInfo.requestPath) || '';
                if (xhr && xhr.status >= 400 && /\/board\/move\.php/.test(path)) {
                    var card = e.detail.elt && e.detail.elt.closest ? e.detail.elt.closest('.board-card') : null;
                    if (card && card.__hdFrom) { var f = card.__hdFrom; f.col.insertBefore(card, f.col.children[f.index] || null); card.__hdFrom = null; }
                    var region = document.getElementById('board-columns');
                    if (region) { htmx.trigger(region, 'ticketChanged'); }
                }
            });
            document.body.addEventListener('htmx:beforeSwap', function (e) {
                if (e.detail.target && e.detail.target.id === 'board-columns' && document.body.hasAttribute('data-dragging')) { e.detail.shouldSwap = false; }
            });
            document.body.addEventListener('htmx:load', function () { if (window.bindBoard) { window.bindBoard(); } });
        }
        window.bindBoard();
    })();
    </script>
</div>
