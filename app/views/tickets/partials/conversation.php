<?php /** The conversation, oldest first. Data: conversation, internals, tz, here */ ?>
<div id="ticket-conversation">
    <?php if ($conversation === []): ?><div class="card"><div class="card-body text-muted" id="ticket-conversation-empty">No message yet.</div></div><?php endif; ?>
    <?php foreach ($conversation as $m): $id = (int) $m['message_id']; $note = $m['kind'] === 'internal_note'; $authorName = $m['author_member_id'] !== null ? $m['author_member_name'] : $m['author_requester_name']; ?>
        <div class="card mb-2 <?= $note ? 'border-warning bg-soft-warning' : '' ?>" id="message-<?= $id ?>">
            <div class="card-body py-2 px-3">
                <div class="d-flex flex-wrap align-items-center gap-2 fs-12 mb-1">
                    <span class="fw-semibold"><?= e($authorName ?? 'Someone') ?></span>
                    <?php if ($m['author_kind'] === 'agent'): ?><span class="badge bg-soft-info text-info"><i class="feather-cpu"></i> agent</span><?php elseif ($m['author_member_id'] === null): ?><span class="badge bg-soft-primary text-primary"><i class="feather-globe"></i> requester</span><?php endif; ?>
                    <?php if ($note): ?><span class="badge bg-warning text-dark">Internal</span><?php endif; ?>
                    <i class="feather-<?= ['email' => 'mail', 'portal' => 'link', 'form' => 'file-text', 'agent' => 'cpu', 'api' => 'code', 'assistant' => 'message-circle'][$m['channel']] ?? 'monitor' ?> text-muted" title="<?= e($m['channel']) ?>"></i>
                    <span class="text-muted ms-auto"><?= e(format_ts($m['created_at'], $tz, 'M j, Y g:i A')) ?></span>
                </div>
                <div class="message-body"><?= nl2br(e($m['body_text'])) ?></div>
                <?php if ($m['attachments'] !== []): ?><div class="mt-2 d-flex flex-wrap gap-1"><?php foreach ($m['attachments'] as $a): ?><a href="/tickets/attachments/get.php?id=<?= (int) $a['attachment_id'] ?>" class="badge bg-soft-secondary text-secondary text-decoration-none" id="attachment-<?= (int) $a['attachment_id'] ?>"><i class="feather-paperclip me-1"></i><?= e($a['filename']) ?> · <?= e(bytes_words((int) $a['byte_size'])) ?></a><?php endforeach; ?></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
