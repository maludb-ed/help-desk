<?php
declare(strict_types=1);

/**
 * The custom values validator (slice 2; slice 3 calls it): the live definitions a record is shown, the cleaning of what a form or an agent sends,
 * and what a ticket lacks on solve. Reads the base table — every definition, visible to requesters or not, is a rule.
 */
require_once dirname(__DIR__, 2) . '/handler.php';          // pg_int_array(), when called outside the bootstrap (a test, a worker)
require_once dirname(__DIR__) . '/shell/nav.php';            // pg_text_array()
require_once __DIR__ . '/queries.php';

/** The live definitions for a kind of record — a ticket's narrowed to its type when the field names types. */
function fields_for(PDO $pdo, string $appliesTo, ?int $typeId = null): array
{
    $st = $pdo->prepare('SELECT id AS field_id, key, label, field_type, options, applies_to, type_ids, required_on_solve, requester_visible, sort_order, archived_at FROM custom_field_defs WHERE applies_to = :a AND archived_at IS NULL ORDER BY sort_order, id');
    $st->execute(['a' => $appliesTo]);
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $f = field_cast($f);
        if ($appliesTo === 'ticket' && $f['type_ids'] !== [] && ($typeId === null || !in_array($typeId, $f['type_ids'], true))) {
            continue;
        }
        $out[] = $f;
    }
    return $out;
}

/**
 * Clean the values sent for a record: ['values' => keyed by field key, 'errors' => key => sentence]. An unknown key is an error; an archived field is
 * dropped; a ticket field restricted to other types is dropped silently; number numeric; date YYYY-MM-DD; select one of the options; multi_select a
 * subset; checkbox true/false; text ≤ 2,000 characters. required_on_solve is not checked here (custom_values_missing_on_solve()).
 */
function custom_values_validate(PDO $pdo, string $appliesTo, array $values, ?int $typeId = null): array
{
    $all = $pdo->prepare('SELECT id AS field_id, key, label, field_type, options, applies_to, type_ids, required_on_solve, requester_visible, sort_order, archived_at FROM custom_field_defs WHERE applies_to = :a');
    $all->execute(['a' => $appliesTo]);
    $defs = [];
    foreach ($all->fetchAll() as $f) {
        $defs[$f['key']] = field_cast($f);
    }
    $shown = array_column(fields_for($pdo, $appliesTo, $typeId), null, 'key');
    $out = [];
    $errors = [];
    foreach ($values as $key => $raw) {
        $key = (string) $key;
        if (!isset($defs[$key])) {
            $errors[$key] = 'There is no field ' . $key . ' for ' . strtolower(FIELD_APPLIES[$appliesTo] ?? $appliesTo) . '.';
            continue;
        }
        if ($defs[$key]['archived_at'] !== null || !isset($shown[$key])) {
            continue;                                             // archived, or for other types: dropped
        }
        $f = $defs[$key];
        $v = is_array($raw) ? $raw : trim((string) $raw);
        if ($v === '' || $v === [] || $v === null) {
            $out[$key] = null;
            continue;
        }
        switch ($f['field_type']) {
            case 'text':
                if (is_array($v) || mb_strlen($v) > 2000) {
                    $errors[$key] = $f['label'] . ' is text of up to 2,000 characters.';
                } else {
                    $out[$key] = $v;
                }
                break;
            case 'number':
                if (is_array($v) || !is_numeric($v)) {
                    $errors[$key] = $f['label'] . ' is a number.';
                } else {
                    $out[$key] = (float) $v == (int) $v && !str_contains($v, '.') ? (int) $v : (float) $v;
                }
                break;
            case 'date':
                $d = is_array($v) ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    $errors[$key] = $f['label'] . ' is a date (YYYY-MM-DD).';
                } else {
                    $out[$key] = $v;
                }
                break;
            case 'select':
                if (is_array($v) || !in_array($v, $f['options'], true)) {
                    $errors[$key] = $f['label'] . ' is one of: ' . implode(', ', $f['options']) . '.';
                } else {
                    $out[$key] = $v;
                }
                break;
            case 'multi_select':
                $list = is_array($v) ? $v : explode(',', $v);
                $list = array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $list), static fn (string $x): bool => $x !== '')));
                $bad = array_diff($list, $f['options']);
                if ($bad !== []) {
                    $errors[$key] = $f['label'] . ' allows only: ' . implode(', ', $f['options']) . '.';
                } else {
                    $out[$key] = $list;
                }
                break;
            case 'checkbox':
                $s = is_array($v) ? '' : strtolower((string) $v);
                if (in_array($s, ['1', 'true', 'on', 'yes'], true)) {
                    $out[$key] = true;
                } elseif (in_array($s, ['0', 'false', 'off', 'no'], true)) {
                    $out[$key] = false;
                } else {
                    $errors[$key] = $f['label'] . ' is yes or no.';
                }
                break;
        }
    }
    return ['values' => $out, 'errors' => $errors];
}

/** The required-on-solve fields a ticket lacks a value for: [key => label]. */
function custom_values_missing_on_solve(PDO $pdo, int $ticketId): array
{
    $st = $pdo->prepare('SELECT type_id, custom_values FROM tickets WHERE id = :id');
    $st->execute(['id' => $ticketId]);
    $t = $st->fetch();
    if ($t === false) {
        return [];
    }
    $values = json_decode((string) $t['custom_values'], true) ?: [];
    $missing = [];
    foreach (fields_for($pdo, 'ticket', $t['type_id'] === null ? null : (int) $t['type_id']) as $f) {
        if (!$f['required_on_solve']) {
            continue;
        }
        $v = $values[$f['key']] ?? null;
        if ($v === null || $v === '' || $v === [] || ($f['field_type'] === 'checkbox' && $v === false)) {
            $missing[$f['key']] = $f['label'];
        }
    }
    return $missing;
}
