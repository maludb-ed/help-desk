<?php
declare(strict_types=1);

/**
 * Markdown for articles (slice 9), a whitelist renderer with no library: #–### headings, paragraphs, **bold**, *italic*, `code`, fenced code blocks, ordered and unordered lists,
 * blockquotes, links (http, https, mailto only; rel="noopener"), images only from /kb/attachment.php?id=, horizontal rules. Everything else is escaped text; raw HTML in the source is escaped.
 */
function md_render(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $out = [];
    $i = 0;
    $n = count($lines);
    $flush = static function (array &$para) use (&$out): void {
        if ($para !== []) {
            $out[] = '<p>' . md_inline(implode("\n", $para)) . '</p>';
            $para = [];
        }
    };
    $para = [];
    while ($i < $n) {
        $line = $lines[$i];
        $t = rtrim($line);
        if (preg_match('/^```/', $t)) {
            $flush($para);
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', rtrim($lines[$i]))) {
                $code[] = $lines[$i];
                $i++;
            }
            $i++;
            $out[] = '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $t, $m)) {
            $flush($para);
            $out[] = '<h' . (strlen($m[1]) + 1) . '>' . md_inline($m[2]) . '</h' . (strlen($m[1]) + 1) . '>';
            $i++;
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $t)) {
            $flush($para);
            $out[] = '<hr>';
            $i++;
            continue;
        }
        if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $t, $m)) {
            $flush($para);
            $ordered = ctype_digit($m[2][0]);
            $items = [];
            while ($i < $n && preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(.*)$/', rtrim($lines[$i]), $mm)) {
                $items[] = '<li>' . md_inline($mm[1]) . '</li>';
                $i++;
            }
            $out[] = ($ordered ? '<ol>' : '<ul>') . implode('', $items) . ($ordered ? '</ol>' : '</ul>');
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $t)) {
            $flush($para);
            $q = [];
            while ($i < $n && preg_match('/^>\s?(.*)$/', rtrim($lines[$i]), $mm)) {
                $q[] = $mm[1];
                $i++;
            }
            $out[] = '<blockquote>' . md_render(implode("\n", $q)) . '</blockquote>';
            continue;
        }
        if ($t === '') {
            $flush($para);
            $i++;
            continue;
        }
        $para[] = $t;
        $i++;
    }
    $flush($para);
    return implode("\n", $out);
}

/** Inline marks over escaped text: code, bold, italic, links, images. */
function md_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $m): string {
        $url = html_entity_decode($m[2], ENT_QUOTES);
        return preg_match('#^/kb/attachment\.php\?id=\d+$#', $url) ? '<img src="' . e($url) . '" alt="' . $m[1] . '" class="img-fluid">' : $m[0];
    }, $s) ?? $s;
    $s = preg_replace_callback('/(?<!!)\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
        $url = html_entity_decode($m[2], ENT_QUOTES);
        return preg_match('#^(https?://|mailto:)#i', $url) || str_starts_with($url, '/kb/') ? '<a href="' . e($url) . '" rel="noopener">' . $m[1] . '</a>' : $m[0];
    }, $s) ?? $s;
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $s) ?? $s;
    return nl2br($s, false);
}

/** The first $chars characters of a body as plain words, cut at a word. */
function article_summary(array $article, int $chars = 300): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/[`#*>_\[\]()!-]+/', ' ', (string) ($article['body_text'] ?? '')))));
    if (mb_strlen($text) <= $chars) {
        return $text;
    }
    $cut = mb_substr($text, 0, $chars);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp !== false && $sp > $chars * 0.6 ? mb_substr($cut, 0, $sp) : $cut) . '…';
}
