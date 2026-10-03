<?php
declare(strict_types=1);

/** The JSON shape of a canned response and the words of its side-effects. */
function present_canned(array $c): array
{
    return ['canned_id' => $c['canned_id'], 'title' => $c['title'], 'folder' => $c['folder'], 'scope' => $c['scope'], 'owner_member_id' => $c['owner_member_id'], 'queue_id' => $c['queue_id'],
            'queue_name' => $c['queue_name'] ?? null, 'body_text' => $c['body_text'], 'first_line' => canned_first_line($c['body_text']), 'side_effects' => (object) $c['side_effects'],
            'side_effect_words' => side_effect_words($c['side_effects']), 'usage_count' => $c['usage_count'], 'may_change' => canned_may_change($c), 'updated_at' => json_ts($c['updated_at'] ?? null)];
}

function canned_first_line(string $body): string
{
    $line = trim((string) strtok(trim($body), "\n"));
    return mb_strlen($line) > 120 ? mb_substr($line, 0, 117) . '…' : $line;
}

/** "→ Pending", "↑ High", "assign me", "+billing" — the chips. */
function side_effect_words(array $se): array
{
    $out = [];
    if (!empty($se['status'])) {
        $out[] = '→ ' . ucfirst(str_replace('_', ' ', (string) $se['status']));
    }
    if (!empty($se['priority'])) {
        $out[] = '↑ ' . ucfirst((string) $se['priority']);
    }
    if (!empty($se['assign'])) {
        $out[] = 'assign ' . ($se['assign'] === 'me' ? 'me' : ($se['assign'] === 'nobody' ? 'nobody' : '#' . $se['assign']));
    }
    foreach ($se['add_tags'] ?? [] as $g) {
        $out[] = '+' . $g;
    }
    return $out;
}
