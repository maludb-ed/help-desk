<?php
declare(strict_types=1);
/**
 * /kb/ — the knowledge base (screen `kb-home`; the public door `kb-public` when nobody is signed in): sections as cards and a search (?q=). A visitor or an external
 * sees the bare layout and public published articles only; kb.read adds the internal ones. ?picker=1&ticket= answers the composer's article picker (work on the ticket; a partial, no log).
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
$pdo = db();
$me = current_member();
$filter = kb_visible_filter($me);
$q = mb_substr(trim(request_string('q')), 0, 120);
if ($me !== null && request_string('picker') === '1') {
    require_human();
    $ticket = request_integer('ticket') ?? refuse(422, 'Say which ticket.');
    require_can_work_ticket($ticket);
    $facts = ticket_facts($pdo, $ticket) ?? refuse(404, 'Ticket not found.');
    $rows = $q !== '' ? kb_search($pdo, $q, $filter, 10) : find_articles($pdo, $filter);
    header('Vary: HX-Request');
    echo view('kb/partials/article-picker.php', ['rows' => array_slice($rows, 0, 10), 'ticket' => $ticket, 'q' => $q, 'external' => $facts['requester_kind'] === 'external']);
    exit;
}
$public = !$filter['member'];
if ($public) {
    $GLOBALS['__public_door'] = 'portal';
}
$results = $q !== '' ? kb_search($pdo, $q, $filter) : [];
$flat = find_sections($pdo, $filter);
$byParent = [];
foreach ($flat as $s) {
    $byParent[$s['parent_id'] ?? 0][] = $s;
}
$sections = [];
foreach ($byParent[0] ?? [] as $s) {
    $s['children'] = $byParent[$s['section_id']] ?? [];
    $sections[] = $s;
}
foreach ($flat as $s) {                                                   // a child whose parent the caller may not see stands on its own
    if ($s['parent_id'] !== null && !isset(array_column($flat, null, 'section_id')[$s['parent_id']])) {
        $s['children'] = [];
        $sections[] = $s;
    }
}
$askUrl = $public ? '/request' : '/tickets/new';
$canEdit = $filter['drafts'];
log_activity($pdo, 'screen.view', null, null, ['screen' => 'kb-home'] + ($q !== '' ? ['after' => ['q' => mb_substr($q, 0, 80)]] : []));
if (!$public && wants_json()) {
    respond_screen(['q' => $q, 'sections' => array_map(static fn (array $s): array => present_section($s) + ['children' => array_map('present_section', $s['children'])], $sections), 'results' => array_map('present_article', $results), 'may' => ['edit' => $canEdit]]);
}
$html = view('kb/home.php', ['sections' => $sections, 'q' => $q, 'results' => $results, 'filter' => $filter, 'askUrl' => $askUrl, 'canEdit' => $canEdit, 'public' => $public]);
if ($public) {
    render_public('Knowledge base', $html, 'kb-home');
}
render_screen('Knowledge base', $html, ['activeNav' => 'kb', 'screen' => 'kb-home', 'entity' => 'article']);
