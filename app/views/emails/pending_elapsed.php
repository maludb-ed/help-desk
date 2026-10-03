<?php /** A pending ticket's wait ran out. Data: ticket, ctx, audience, marker */ ?>
<?= $marker ?>

<?= $ctx['text'] ?>

<?= $ticket['number'] !== '' ? $ticket['number'] . ' — ' . $ticket['subject'] . "\n" : '' ?>
