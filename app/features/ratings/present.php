<?php
declare(strict_types=1);

function present_rating(array $r): array
{
    return ['rating_id' => $r['rating_id'], 'ticket_id' => $r['ticket_id'], 'number' => $r['number'], 'subject' => $r['subject'], 'queue' => ['queue_id' => $r['queue_id'], 'name' => $r['queue_name']], 'score' => $r['score'], 'is_bad' => rating_is_bad($r['score']),
            'bad_reason' => $r['bad_reason'], 'comment' => $r['comment'], 'responded_at' => json_ts($r['responded_at']), 'assignee_name' => $r['assignee_name'], 'requester_name' => $r['requester_name']];
}

function present_csat_settings(array $cs): array
{
    return ['enabled' => $cs['enabled'], 'scale' => $cs['scale'], 'question' => $cs['question'], 'send_delay_minutes' => $cs['send_delay_minutes'], 'response_window_days' => $cs['response_window_days'], 'bad_reasons' => $cs['bad_reasons'], 'queue_ids' => $cs['queue_ids']];
}

/** The score's words: good_bad → Good / Bad; five_point → "4 of 5". */
function score_words(int $score, string $scale): string
{
    if ($scale === 'good_bad') {
        return $score >= 4 ? 'Good' : 'Bad';
    }
    return $score . ' of 5';
}

function score_color(int $score): string
{
    return $score >= 4 ? 'success' : ($score <= 2 ? 'danger' : 'warning');
}
