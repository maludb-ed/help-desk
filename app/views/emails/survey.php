<?php /** The survey. Data: ticket, ctx, audience, marker */ ?>
<?= $marker ?>

<?= $ctx['text'] ?>

<?= !empty($ctx['signature']) ? "\n" . $ctx['signature'] . "\n" : '' ?>
