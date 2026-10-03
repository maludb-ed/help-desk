<?php
declare(strict_types=1);
/** /settings/statuses/transitions — the 6 × 6 matrix of allowed moves (screen `transitions`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$grid = find_transitions($pdo);
log_screen_view($pdo, 'transitions');
if (wants_json()) {
    $out = [];
    foreach ($grid as $f => $row) {
        foreach ($row as $t => $r) {
            if ($r !== null) {
                $out[] = present_transition($r) + ['words' => transition_words($r)];
            }
        }
    }
    respond_screen(['categories' => STATUS_CATEGORIES, 'transitions' => $out]);
}
render_screen('Transitions', view('statuses/transitions.php', ['grid' => $grid, 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the move.'], 'removed' => ['success', 'That move is no longer allowed.']])]),
    ['activeNav' => 'statuses', 'screen' => 'transitions', 'entity' => 'status_transition']);
