<?php
declare(strict_types=1);

function present_attachment(array $a): array
{
    return ['attachment_id' => (int) $a['attachment_id'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => (int) $a['byte_size']];
}
