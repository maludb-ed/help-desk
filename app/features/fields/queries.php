<?php
declare(strict_types=1);

/** Custom field definitions (slice 2): for tickets, organizations and requesters. Reads through mcp_custom_field_defs, writes to the base table. */

const FIELD_TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'select' => 'Select', 'multi_select' => 'Multi select', 'checkbox' => 'Checkbox'];
const FIELD_APPLIES = ['ticket' => 'Tickets', 'organization' => 'Organizations', 'requester' => 'Requesters'];

function field_cast(array $f): array
{
    $f['field_id'] = (int) $f['field_id'];
    $f['sort_order'] = (int) $f['sort_order'];
    $f['options'] = pg_text_array((string) $f['options']);
    $f['type_ids'] = pg_int_array((string) $f['type_ids']);
    $f['required_on_solve'] = (bool) $f['required_on_solve'];
    $f['requester_visible'] = (bool) $f['requester_visible'];
    return $f;
}

function find_fields(PDO $pdo, ?string $appliesTo = null, bool $includeArchived = false): array
{
    $where = [];
    $args = [];
    if ($appliesTo !== null) {
        $where[] = 'f.applies_to = :a';
        $args['a'] = $appliesTo;
    }
    if (!$includeArchived) {
        $where[] = 'f.archived_at IS NULL';
    }
    $st = $pdo->prepare('SELECT f.field_id, f.key, f.label, f.field_type, f.options, f.applies_to, f.type_ids, f.required_on_solve, f.requester_visible, f.sort_order, f.archived_at FROM mcp_custom_field_defs f'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY f.applies_to, f.sort_order, f.field_id');
    $st->execute($args);
    return array_map('field_cast', $st->fetchAll());
}

function find_field(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS field_id, key, label, field_type, options, applies_to, type_ids, required_on_solve, requester_visible, sort_order, archived_at FROM custom_field_defs WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : field_cast($r);
}

function field_log_state(array $f): array
{
    return array_intersect_key($f, array_flip(['key', 'label', 'field_type', 'options', 'applies_to', 'type_ids', 'required_on_solve', 'requester_visible', 'sort_order']));
}

/** Add ($id null) or change a field. $f: key (create), label, field_type, options[], applies_to (create), type_ids[], required_on_solve, requester_visible, sort_order. */
function save_field(PDO $pdo, ?int $id, array $f): int
{
    if ($id === null) {
        $dupe = $pdo->prepare('SELECT 1 FROM custom_field_defs WHERE applies_to = :a AND key = :k');
        $dupe->execute(['a' => $f['applies_to'], 'k' => $f['key']]);
        if ($dupe->fetchColumn() !== false) {
            throw new DomainException('A field with that key already exists for ' . strtolower(FIELD_APPLIES[$f['applies_to']]) . '.');
        }
        $st = $pdo->prepare('INSERT INTO custom_field_defs (key, label, field_type, options, applies_to, type_ids, required_on_solve, requester_visible, sort_order) VALUES (:k, :l, :t, CAST(:o AS text[]), :a, CAST(:ti AS bigint[]), :r, :v, :so) RETURNING id');
        $st->execute(['k' => $f['key'], 'l' => $f['label'], 't' => $f['field_type'], 'o' => pg_array_literal($f['options']), 'a' => $f['applies_to'], 'ti' => '{' . implode(',', $f['type_ids']) . '}', 'r' => $f['required_on_solve'] ? 't' : 'f', 'v' => $f['requester_visible'] ? 't' : 'f', 'so' => $f['sort_order']]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE custom_field_defs SET label = :l, field_type = :t, options = CAST(:o AS text[]), type_ids = CAST(:ti AS bigint[]), required_on_solve = :r, requester_visible = :v, sort_order = :so WHERE id = :id')
        ->execute(['l' => $f['label'], 't' => $f['field_type'], 'o' => pg_array_literal($f['options']), 'ti' => '{' . implode(',', $f['type_ids']) . '}', 'r' => $f['required_on_solve'] ? 't' : 'f', 'v' => $f['requester_visible'] ? 't' : 'f', 'so' => $f['sort_order'], 'id' => $id]);
    return $id;
}

function archive_field(PDO $pdo, int $id): void
{
    $f = find_field($pdo, $id) ?? throw new DomainException('Not found.');
    if ($f['archived_at'] !== null) {
        throw new DomainException($f['label'] . ' is already archived.');
    }
    $pdo->prepare('UPDATE custom_field_defs SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
}
