<?php /** A reply on a ticket. Data: ticket, ctx, audience, marker */ ?>
<?= $marker ?>

<?= $ctx['text'] ?>

<?= $ticket['number'] !== '' ? $ticket['number'] . ' — ' . $ticket['subject'] . "\n" : '' ?><?= !empty($ctx['signature']) ? "\n" . $ctx['signature'] . "\n" : '' ?>
