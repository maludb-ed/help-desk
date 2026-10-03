<?php
/** The browser proof's fixture: a public article with a long code line, printed as KB_SLUG=…, and the ticket id. */
require __DIR__ . '/lib.php';
$W = world();
[$id, $slug] = mk_article(as_member(1), 'SMOKE Browser article', ['body' => "# Long code\n\nSMOKE zebra words.\n\n```\n" . str_repeat('very-long-token-', 20) . "\n```\n\nDone."]);
echo "KB_SLUG=$slug KB_TICKET={$W['t_jo']}\n";
