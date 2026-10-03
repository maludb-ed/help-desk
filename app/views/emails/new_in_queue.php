<?php /** A ticket landed unassigned in your queue. Data: ticket, ctx, audience, marker */ ?>
<?= $marker ?>

<?= $ctx['text'] ?>

<?= $ticket['number'] !== '' ? $ticket['number'] . ' — ' . $ticket['subject'] . "\n" : '' ?>
