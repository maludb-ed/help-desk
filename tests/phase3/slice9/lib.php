<?php
/** Helpers for the slice 9 proofs (docs/build-specs/knowledge-base.md, "Proof"): slice 6's library (the world, members, the visitor and public posts) plus articles and sections made through the handlers. */
require dirname(__DIR__) . '/slice6/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';

/** A section through section_save: its id. */
function mk_section(string $jar, string $name, string $visibility = 'public', array $extra = []): int
{
    [$c, $b] = act($jar, '/kb/admin/section.php', $extra + ['name' => $name, 'visibility' => $visibility, 'sort_order' => 10]);
    if ($c !== 200) { fwrite(STDERR, "section $name: $c " . json_encode($b) . "\n"); }
    return (int) ($b['record_id'] ?? 0);
}
/** An article through article_create: [id, slug]. Defaults: public, published, a plain body. */
function mk_article(string $jar, string $title, array $f = []): array
{
    [$c, $b] = act($jar, '/kb/admin/save.php', $f + ['title' => $title, 'body' => 'SMOKE body of ' . $title . '. Plug it in, then turn it on.', 'visibility' => 'public', 'status' => 'published']);
    if ($c !== 200) { fwrite(STDERR, "article $title: $c " . json_encode($b) . "\n"); }
    return [(int) ($b['record_id'] ?? 0), (string) ($b['slug'] ?? '')];
}
function article_row(int $id): array { return q('SELECT * FROM articles WHERE id = :a', ['a' => $id])[0] ?? []; }
function links_of(int $ticket, ?string $kind = null): array { return q('SELECT * FROM article_links WHERE ticket_id = :t' . ($kind !== null ? ' AND kind = :k' : '') . ' ORDER BY created_at', ['t' => $ticket] + ($kind !== null ? ['k' => $kind] : [])); }
/** The text of a page with its tags and entities gone. */
function words(string $html): string { return html_entity_decode(strip_tags($html), ENT_QUOTES); }
/** The bare layout: no sidebar, no command bar, no link into the application. */
function is_bare(string $html): bool { return !str_contains($html, 'id="nav-kb"') && !str_contains($html, 'nxl-navigation') && !str_contains($html, 'id="command-bar'); }
