<?php
declare(strict_types=1);

/** The search (slice 9): pg_trgm over title and body (articles_search_idx), similarity first then newest; two characters at least; only what the caller may open. */
function kb_search(PDO $pdo, string $q, array $filter, int $limit = 20): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    [$w, $args] = kb_filter_sql($filter, 'a');
    $st = $pdo->prepare(ARTICLE_SELECT . ", similarity(a.title || ' ' || a.body_text, :q) AS sim WHERE $w AND ((a.title || ' ' || a.body_text) % :q OR a.title ILIKE :like OR a.body_text ILIKE :like)
                          ORDER BY sim DESC, a.published_at DESC NULLS LAST, a.id DESC LIMIT " . (int) $limit);
    $st->execute($args + ['q' => $q, 'like' => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%']);
    return array_map('article_cast', $st->fetchAll());
}
