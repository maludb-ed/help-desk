<?php
/** Proof — rights and JSON mode (spec "Proof", 7–8): support → 403 on every screen; a lead may tag_save and not tag_delete; every handler answers {ok, location} to an action token and 422 {fields} on a bad form. */
require __DIR__ . '/lib.php';
$marco = as_member(27); $lena = as_member(28); $priya = as_member(26);
$admin = ['X-Action-Token: ' . person_token(1)];

echo "1. Rights\n";
$screens = ['/settings/statuses/', '/settings/statuses/new', '/settings/statuses/transitions', '/settings/vocabulary/', '/settings/vocabulary/?tab=categories', '/settings/vocabulary/categories/new', '/settings/fields/', '/settings/fields/new'];
$wrong = [];
foreach ($screens as $p) { $r = page($marco, $p); if ($r['code'] !== 403 || !str_contains(html_entity_decode($r['body'], ENT_QUOTES), "You may not change Help Desk's settings.")) { $wrong[] = "$p {$r['code']}"; } }
ok($wrong === [], count($screens) . ' screens → 403 for support staff in the right\'s sentence' . ($wrong ? ' — ' . implode('; ', $wrong) : ''));
ok(page($lena, '/settings/vocabulary/?tab=tags')['code'] === 200 && page($lena, '/settings/vocabulary/?tab=types')['code'] === 403, 'a lead reaches the tags tab (tickets.manage) but not the types tab');
ok(page($priya, '/settings/vocabulary/?tab=tags')['code'] === 403, 'a requester does not reach the tags tab');
$writes = ['/settings/statuses/save.php' => ['label' => 'x'], '/settings/statuses/archive.php' => ['status' => 1], '/settings/statuses/transition.php' => ['from_category' => 'open', 'to_category' => 'solved'],
    '/settings/vocabulary/type.php' => ['label' => 'x'], '/settings/vocabulary/type-archive.php' => ['type' => 1], '/settings/vocabulary/category.php' => ['name' => 'x'], '/settings/vocabulary/category-archive.php' => ['category' => 1],
    '/settings/vocabulary/tag.php' => ['label' => 'x'], '/settings/vocabulary/tag-delete.php' => ['tag' => 1], '/settings/fields/save.php' => ['label' => 'x', 'applies_to' => 'ticket'], '/settings/fields/archive.php' => ['field' => 1]];
$wrong = [];
foreach ($writes as $p => $f) { [$c] = act($marco, $p, $f); if ($c !== 403) { $wrong[] = "$p $c"; } }
ok($wrong === [], count($writes) . ' writes → 403 for support staff' . ($wrong ? ' — ' . implode('; ', $wrong) : ''));
[$c, $b] = act($lena, '/settings/vocabulary/tag.php', ['label' => 'SMOKE Lead tag']);
$lt = (int) ($b['record_id'] ?? 0);
[$c2, $b2] = act($lena, '/settings/vocabulary/tag-delete.php', ['tag' => $lt]);
ok($c === 200 && $lt > 0 && $c2 === 403, 'a lead may tag_save (200) and not tag_delete (403)');
$body = page($lena, '/settings/vocabulary/?tab=tags')['body'];
ok(str_contains($body, 'id="tag-chip-' . $lt . '-form"') && !str_contains($body, 'id="tag-chip-' . $lt . '-delete-btn"'), 'the lead\'s tag chip has the rename form and no Delete');

echo "2. JSON mode: creates with record_id in the location\n";
$creates = [
    ['/settings/statuses/save.php', ['label' => 'SMOKE Token status', 'category' => 'pending'], '#^/settings/statuses/\?notice=saved\#status-row-(\d+)$#'],
    ['/settings/vocabulary/type.php', ['label' => 'SMOKE Token type'], '#^/settings/vocabulary/\?tab=types&notice=type_saved\#type-row-(\d+)$#'],
    ['/settings/vocabulary/category.php', ['name' => 'SMOKE Token category'], '#^/settings/vocabulary/\?tab=categories&notice=category_saved\#category-row-(\d+)$#'],
    ['/settings/vocabulary/tag.php', ['label' => 'SMOKE Token tag'], '#^/settings/vocabulary/\?tab=tags&notice=tag_saved\#tag-chip-(\d+)$#'],
    ['/settings/fields/save.php', ['label' => 'SMOKE Token field', 'applies_to' => 'requester', 'field_type' => 'checkbox'], '#^/settings/fields/\?notice=saved\#field-row-(\d+)$#'],
];
$ids = [];
foreach ($creates as [$path, $form, $re]) {
    [$c, $b, $r] = act_token($path, $form, $admin);
    $m = [];
    $okc = $c === 200 && ($b['ok'] ?? false) && preg_match($re, (string) ($b['location'] ?? ''), $m) && (int) $m[1] === (int) ($b['record_id'] ?? -1) && !str_contains($r['headers'], 'Set-Cookie');
    ok($okc, "$path → {ok, record_id {$b['record_id']}, location}" . ($okc ? '' : " ($c " . msg($b) . ' ' . ($b['location'] ?? '') . ')'));
    $ids[$path] = (int) ($b['record_id'] ?? 0);
}
$writes = [
    ['/settings/statuses/save.php', ['status' => $ids['/settings/statuses/save.php'], 'color' => 'danger']],
    ['/settings/statuses/transition.php', ['from_category' => 'on_hold', 'to_category' => 'closed', 'allowed' => 'yes', 'needs_right' => 'tickets.manage']],
    ['/settings/statuses/transition.php', ['from_category' => 'on_hold', 'to_category' => 'closed', 'allowed' => 'no']],
    ['/settings/vocabulary/type.php', ['type' => $ids['/settings/vocabulary/type.php'], 'description' => 'SMOKE d']],
    ['/settings/vocabulary/category.php', ['category' => $ids['/settings/vocabulary/category.php'], 'requester_visible' => 'no']],
    ['/settings/vocabulary/tag.php', ['tag' => $ids['/settings/vocabulary/tag.php'], 'label' => 'SMOKE Token tag 2']],
    ['/settings/fields/save.php', ['field' => $ids['/settings/fields/save.php'], 'sort_order' => 7]],
    ['/settings/statuses/archive.php', ['status' => $ids['/settings/statuses/save.php']]],
    ['/settings/vocabulary/type-archive.php', ['type' => $ids['/settings/vocabulary/type.php']]],
    ['/settings/vocabulary/category-archive.php', ['category' => $ids['/settings/vocabulary/category.php']]],
    ['/settings/vocabulary/tag-delete.php', ['tag' => $ids['/settings/vocabulary/tag.php']]],
    ['/settings/fields/archive.php', ['field' => $ids['/settings/fields/save.php']]],
];
$wrong = [];
foreach ($writes as [$path, $form]) { [$c, $b] = act_token($path, $form, $admin); if ($c !== 200 || !($b['ok'] ?? false) || !isset($b['location'], $b['did'])) { $wrong[] = "$path $c " . msg($b); } }
ok($wrong === [], count($writes) . ' more handlers answer {ok, did, location}' . ($wrong ? ' — ' . implode('; ', $wrong) : ''));
[$c, $b] = act_token('/settings/statuses/save.php', ['label' => '', 'category' => 'magic', 'color' => 'pink'], $admin);
$f = fields($b);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($f['label'], $f['category'], $f['color']), 'a bad form: 422 with three field errors (' . implode(', ', array_keys($f)) . ')');
[$c, $b] = act_token('/settings/statuses/save.php', ['label' => 'SMOKE Via token', 'category' => 'new'], $admin);
ok($c === 422 && str_contains(msg($b), 'never new or closed'), 'the database\'s guard reaches a token caller in its sentence');
[$c, $b] = act_token('/settings/fields/save.php', ['label' => 'x', 'applies_to' => 'ticket'], ['X-Action-Token: ' . person_token(27)]);
ok($c === 403, 'the rights hold under a token (support staff: 403)');
finish();
