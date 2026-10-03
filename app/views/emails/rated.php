<?php /** A requester rated a ticket. Data: ticket, ctx, audience, marker */ ?>
<?= $marker ?>

<?= $ctx['text'] ?>

<?= $ticket['number'] !== '' ? $ticket['number'] . ' — ' . $ticket['subject'] . "\n" : '' ?>
