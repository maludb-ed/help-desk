<?php /** The requester's confirmation (and a CC's). Data: ticket, ctx, audience, marker */ ?>
<?= $marker ?>

<?= $ctx['text'] ?>

<?= $ticket['number'] !== '' ? 'Your request: ' . $ticket['number'] . ' — ' . $ticket['subject'] . "\n" : '' ?><?= !empty($ctx['signature']) ? "\n" . $ctx['signature'] . "\n" : '' ?>
