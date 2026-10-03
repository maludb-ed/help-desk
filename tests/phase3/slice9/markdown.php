<?php
/** Proof — Markdown (spec "Proof", 2): a script tag, an onclick, a javascript: link and raw HTML render escaped; headings, lists, code, a safe link render; the preview equals the saved render. */
require __DIR__ . '/lib.php';
$owner = as_member(1);
echo "1. The renderer\n";
$h = md_render("<script>alert(1)</script>");
ok(str_contains($h, '&lt;script&gt;') && !str_contains($h, '<script'), 'a script tag is escaped text');
$h = md_render('<a onclick="x()">hi</a> and <img src=x onerror=alert(1)>');
ok(!str_contains($h, '<a') && !str_contains($h, '<img') && str_contains($h, '&lt;a onclick='), 'raw HTML with handlers is escaped');
$h = md_render('[bad](javascript:alert(1)) and [data](data:text/html,x)');
ok(!str_contains($h, 'href=') && str_contains($h, '[bad](javascript:alert(1))'), 'a javascript: or data: link is not a link');
$h = md_render("# Title\n\n## Second\n\n### Third\n\nSome **bold** and *italic* and `code`.\n\n- a\n- b\n\n1. one\n2. two\n\n> quoted\n\n```\nline <one>\n```\n\n---\n\n[site](https://example.com) [mail](mailto:a@b.co) ![img](/kb/attachment.php?id=3) ![bad](https://evil.example/x.png)");
ok(str_contains($h, '<h2>Title</h2>') && str_contains($h, '<h3>Second</h3>') && str_contains($h, '<h4>Third</h4>'), '#, ## and ### become h2, h3, h4');
ok(str_contains($h, '<strong>bold</strong>') && str_contains($h, '<em>italic</em>') && str_contains($h, '<code>code</code>'), 'bold, italic and inline code');
ok(str_contains($h, '<ul><li>a</li><li>b</li></ul>') && str_contains($h, '<ol><li>one</li><li>two</li></ol>'), 'lists, ordered and not');
ok(str_contains($h, '<blockquote><p>quoted</p></blockquote>') && str_contains($h, "<pre><code>line &lt;one&gt;</code></pre>") && str_contains($h, '<hr>'), 'a blockquote, a fenced block (escaped inside) and a rule');
ok(str_contains($h, '<a href="https://example.com" rel="noopener">site</a>') && str_contains($h, '<a href="mailto:a@b.co" rel="noopener">mail</a>'), 'http and mailto links, rel noopener');
ok(str_contains($h, '<img src="/kb/attachment.php?id=3" alt="img" class="img-fluid">') && !str_contains($h, 'evil.example/x.png"') && str_contains($h, '![bad](https://evil.example/x.png)'), "an image only from /kb/attachment.php?id= — any other stays text");
echo "2. Saved and previewed\n";
$src = "# SMOKE Heading\n\nText with <b>raw</b> and **bold**.\n\n```\nx < y\n```";
[$id, $slug] = mk_article($owner, 'SMOKE Markdown article', ['body' => $src]);
$row = article_row($id);
ok($row['body_text'] === $src && $row['body_html'] === md_render($src), 'body_text is the source; body_html the cached render');
$r = page($owner, '/kb/admin/' . $id . '/edit?preview=1&body=' . rawurlencode($src));
ok($r['code'] === 200 && str_contains($r['body'], $row['body_html']), 'the preview renders the same HTML and saves nothing');
$r = req('GET', '/kb/' . $slug);
ok(str_contains($r['body'], '&lt;b&gt;raw&lt;/b&gt;') && str_contains($r['body'], '<strong>bold</strong>') && !str_contains($r['body'], '<b>raw</b>'), 'the public page shows the raw tag as text and the bold as bold');
ok(str_contains($r['body'], 'id="article-body"') && str_contains($r['body'], '<pre><code>x &lt; y</code></pre>'), 'the code block is escaped on the page');
exit($GLOBALS['fails'] === 0 ? 0 : 1);
