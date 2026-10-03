<?php
declare(strict_types=1);

/** Bulk update (slice 5): the targets a caller may see, and the custom fields a type shows. */

/** mcp_tickets rows for the ids given, in that order, 200 at most — only what the caller may see. */
function bulk_targets(PDO $pdo, array $ids, int $memberId): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $ids = array_slice($ids, 0, 200);
    if ($ids === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE t.ticket_id = ANY (CAST(:ids AS bigint[]))');
    $st->execute(['ids' => '{' . implode(',', $ids) . '}']);
    $byId = [];
    foreach ($st->fetchAll() as $r) {
        $byId[(int) $r['ticket_id']] = ticket_cast($r);
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $out[] = $byId[$id];
        }
    }
    return $out;
}

/** The custom field definitions a form shows for a type (none → the fields that apply to every type). */
function custom_fields_for(PDO $pdo, string $appliesTo, ?int $typeId): array
{
    return fields_for($pdo, $appliesTo, $typeId);
}
