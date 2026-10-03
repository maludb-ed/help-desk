<?php
/** Proof — JSON mode (spec "Proof", 6): board_move_card through an action token answers {ok, ticket_id, status}; the required-field case 422 {error:{code:'invalid', fields: resolution_code}}; the views handlers too. */
require __DIR__ . '/lib.php';
$W = world();
$marco = ['X-Action-Token: ' . person_token(27)]; $lena = ['X-Action-Token: ' . person_token(28)];
$t = (int) act_token('/tickets/save.php', ['subject' => 'SMOKE json drop', 'body' => 'x', 'queue' => $W['it']], $marco)[1]['record_id'];
[$c, $b] = act_token('/board/move.php', ['ticket' => $t, 'column' => 'open', 'position' => 2, 'view' => 'team_board'], $marco);
ok($c === 200 && ($b['ok'] ?? false) === true && ($b['ticket_id'] ?? 0) === $t && ($b['status'] ?? '') === 'open' && ($b['column'] ?? '') === 'open' && isset($b['location']), 'board_move_card: {ok, ticket_id, status: open, column, location}');
[$c, $b] = act_token('/board/move.php', ['ticket' => $t, 'column' => 'solved', 'view' => 'team_board'], $marco);
$f = fields($b);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && array_key_exists('resolution_code', $f) && $f['resolution_code'] === 'Solving needs a resolution code.', 'the required-field case: 422 invalid with fields.resolution_code');
[$c, $b] = act_token('/board/move.php', ['ticket' => $t, 'column' => 'solved', 'resolution_code' => 'workaround', 'view' => 'team_board'], $marco);
ok($c === 200 && ($b['status'] ?? '') === 'solved', 'with the code: solved');
[$c, $b] = act_token('/board/reorder.php', ['ticket' => $t, 'position' => 1], $marco);
ok($c === 200 && ($b['ok'] ?? false) && ($b['position'] ?? -1) === 1, 'board_reorder: {ok, position}');
[$c, $b] = act_token('/views/save.php', ['name' => 'SMOKE json view', 'scope' => 'personal', 'filters' => '{"status_category":["open"],"tags":["vpn"]}', 'sort_by' => 'priority', 'sort_dir' => 'desc', 'columns' => 'number,subject,sla', 'layout' => 'board', 'board' => '{"column_field":"priority","solved_days":1}'], $marco);
$v = (int) ($b['record_id'] ?? 0);
$row = view_row($v);
ok($c === 200 && $v > 0 && ($b['location'] ?? '') === '/views/' . $v . '/edit?notice=saved' && pg_text_array($row['columns']) === ['number', 'subject', 'sla'] && json_decode($row['board'], true)['column_field'] === 'priority', 'view_create by an agent: JSON filters, columns as a list, board JSON');
[$c, $b] = act_token('/views/save.php', ['view' => $v, 'name' => 'SMOKE json view 2'], $marco);
$fj = json_decode(view_row($v)['filters'], true); ksort($fj);
ok($c === 200 && view_row($v)['name'] === 'SMOKE json view 2' && $fj === ['status_category' => ['open'], 'tags' => ['vpn']], 'view_update keeps the filters it was not sent');
[$c, $b] = act_token('/views/save.php', ['view' => $v, 'filters' => 'not json'], $marco);
ok($c === 422 && isset(fields($b)['filters']), 'filters that are not JSON: 422');
[$c, $b] = act_token('/views/delete.php', ['view' => $v], $lena);
ok($c === 404, 'the lead cannot reach Marco\'s personal view: 404');
[$c, $b] = act_token('/views/delete.php', ['view' => $v], $marco);
ok($c === 200 && ($b['ok'] ?? false) && view_row($v) === null, 'view_delete: {ok}');
$r = req('GET', '/board?view=team_board', ['headers' => array_merge(JSONH, $marco)]);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && isset($d['board']['columns'][0]['cards']) && ($d['view']['system_key'] ?? '') === 'team_board', 'the board answers JSON under a token (board_snapshot\'s shape)');
$card = null;
foreach ($d['board']['columns'] as $col) { foreach ($col['cards'] as $x) { if ($x['ticket_id'] === $t) { $card = $x; } } }
ok($card !== null && isset($card['column'], $card['board_position'], $card['age_words'], $card['sla'], $card['status']['key']), 'a card carries column, board_position, age_words, sla and status');
finish();
