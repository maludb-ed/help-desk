<?php
declare(strict_types=1);

function present_field(array $f): array
{
    return ['field_id' => $f['field_id'], 'key' => $f['key'], 'label' => $f['label'], 'field_type' => $f['field_type'], 'options' => $f['options'], 'applies_to' => $f['applies_to'], 'type_ids' => $f['type_ids'],
            'required_on_solve' => $f['required_on_solve'], 'requester_visible' => $f['requester_visible'], 'sort_order' => $f['sort_order'], 'archived' => $f['archived_at'] !== null];
}
