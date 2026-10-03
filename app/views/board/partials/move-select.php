<?php /** The card's "Move to…" (no JavaScript needed): the moves hd_transition() allows from its status that the caller may make, or the other columns of the field. Data: t, field, moves, viewParam */
$id = (int) $t['ticket_id'];
$options = [];
if ($field === 'status') {
    foreach ($moves[$t['status_id']] ?? [] as $m) {
        if (has_right($m['needs_right'])) {
            $options[$m['key']] = $m['move_label'] . ' (' . $m['label'] . ')';
        }
    }
} else {
    foreach ($moves['columns'] ?? [] as $c) {
        if ($c['key'] !== board_value_of($t, $field)) {
            $options[$c['key']] = $c['label'];
        }
    }
}
if ($options === []) { return; } ?>
<details class="mt-2" id="board-card-<?= $id ?>-move">
    <summary class="btn btn-light btn-touch w-100 fs-12" id="board-card-<?= $id ?>-move-summary">Move to…</summary>
    <form method="post" action="/board/move.php" hx-post="/board/move.php" hx-target="closest .board-card" hx-select=".board-card" hx-swap="outerHTML" class="d-flex gap-1 mt-1" id="board-card-<?= $id ?>-move-form">
        <?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="view" value="<?= e($viewParam) ?>">
        <select name="column" class="form-select btn-touch" id="board-card-<?= $id ?>-move-select" aria-label="Move to" required><option value="">Choose…</option><?php foreach ($options as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-primary btn-touch" id="board-card-<?= $id ?>-move-btn" aria-label="Move">Go</button>
    </form>
</details>
