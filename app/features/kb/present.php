<?php
declare(strict_types=1);

function present_section(array $s): array
{
    return ['section_id' => $s['section_id'], 'parent_id' => $s['parent_id'], 'name' => $s['name'], 'slug' => $s['slug'], 'description' => $s['description'], 'visibility' => $s['visibility'], 'sort_order' => $s['sort_order'], 'article_count' => $s['article_count'] ?? null];
}

function present_article(array $a, bool $withBody = false): array
{
    return ['article_id' => $a['article_id'], 'title' => $a['title'], 'slug' => $a['slug'], 'url' => '/kb/' . $a['slug'], 'section' => $a['section_id'] === null ? null : ['section_id' => $a['section_id'], 'name' => $a['section_name'], 'slug' => $a['section_slug']],
            'status' => $a['status'], 'visibility' => $a['visibility'], 'tags' => array_values($a['tags']), 'summary' => article_summary($a, 160), 'view_count' => $a['view_count'], 'helpful_count' => $a['helpful_count'], 'unhelpful_count' => $a['unhelpful_count'],
            'author' => $a['author_name'] ?? null, 'published_at' => json_ts($a['published_at']), 'updated_at' => json_ts($a['updated_at'])] + ($withBody ? ['body_text' => $a['body_text'], 'body_html' => $a['body_html']] : []);
}

/** "Updated 3 days ago". */
function updated_words(?string $ts): string
{
    return $ts === null ? '' : 'Updated ' . ago_words($ts);
}
