<?php /** A ticket you follow changed. Data: ticket, ctx, audience, marker */ ?>
<?= $marker ?>

<?= $ctx['text'] ?>

<?= $ticket['number'] !== '' ? $ticket['number'] . ' — ' . $ticket['subject'] . "\n" : '' ?>
