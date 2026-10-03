-- 016: what the Phase 1 documents (the slice specs' review, 2026-10-03) found the schema should hold — additive, as
-- Projects' and txtSchedules' Phase 1 additions were. Nothing is replaced except two trigger functions re-created whole.
--   * notifications.kind gains `created` (the requester's own ticket was filed for them), `cc_added`, `survey` — slice 3's and 6's notices;
--   * sla_targets.warned_at — the worker warns once per target (slice 8) without searching the activity log;
--   * agent_dispatches.attempts — the worker retries a failed dispatch at most three times (slice 8);
--   * tickets.first_reopened_at — the reopen report's "days to first reopen" (slice 10), stamped by the ticket trigger;
--   * app_settings.form_limit_per_email / form_limit_per_ip — the public form's rate limits are settings, not constants (slice 6);
--   * mcp_csat_settings and mcp_queue_presence — the CSAT scale and the per-member capacity were readable by the writer only.
BEGIN;

ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_kind_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check CHECK (kind IN ('assigned', 'replied', 'noted', 'mentioned', 'sla_warning', 'sla_breached', 'rated',
    'pending_elapsed', 'escalated', 'followed_changed', 'new_in_queue', 'created', 'cc_added', 'survey'));

ALTER TABLE sla_targets ADD COLUMN IF NOT EXISTS warned_at timestamptz;
CREATE INDEX IF NOT EXISTS sla_targets_warn_idx ON sla_targets (due_at) WHERE met_at IS NULL AND breached_at IS NULL AND warned_at IS NULL;

ALTER TABLE agent_dispatches ADD COLUMN IF NOT EXISTS attempts integer NOT NULL DEFAULT 1 CHECK (attempts BETWEEN 1 AND 10);

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS first_reopened_at timestamptz;

ALTER TABLE app_settings ADD COLUMN IF NOT EXISTS form_limit_per_email integer NOT NULL DEFAULT 5 CHECK (form_limit_per_email BETWEEN 1 AND 1000);
ALTER TABLE app_settings ADD COLUMN IF NOT EXISTS form_limit_per_ip integer NOT NULL DEFAULT 20 CHECK (form_limit_per_ip BETWEEN 1 AND 10000);

-- The ticket trigger, re-created whole: the one change is first_reopened_at on the first reopen.
CREATE OR REPLACE FUNCTION hd_ticket_before_update() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE oc text; nc text;
BEGIN
    NEW.updated_at := now();
    IF NEW.status_id IS DISTINCT FROM OLD.status_id THEN
        SELECT category INTO oc FROM ticket_statuses WHERE id = OLD.status_id;
        SELECT category INTO nc FROM ticket_statuses WHERE id = NEW.status_id;
        IF nc = 'solved' AND oc <> 'solved' THEN
            NEW.solved_at := now();
        END IF;
        IF nc = 'closed' THEN
            NEW.closed_at := COALESCE(NEW.closed_at, now());
            NEW.solved_at := COALESCE(NEW.solved_at, now());
        END IF;
        IF oc IN ('solved', 'closed') AND nc NOT IN ('solved', 'closed') THEN
            NEW.reopen_count := OLD.reopen_count + 1;
            NEW.first_reopened_at := COALESCE(OLD.first_reopened_at, now());
            NEW.solved_at := NULL; NEW.closed_at := NULL; NEW.resolution_code := NULL;
        END IF;
        IF nc <> 'pending' THEN NEW.pending_until := NULL; END IF;
        IF nc <> 'on_hold' THEN NEW.hold_reason := NULL; END IF;
        NEW.last_activity_at := now();
    END IF;
    IF NEW.priority IS DISTINCT FROM OLD.priority OR NEW.queue_id IS DISTINCT FROM OLD.queue_id
       OR NEW.category_id IS DISTINCT FROM OLD.category_id OR NEW.type_id IS DISTINCT FROM OLD.type_id
       OR NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN
        NEW.sla_policy_id := hd_match_sla_policy(NEW);
        NEW.last_activity_at := now();
    END IF;
    IF NEW.assignee_member_id IS DISTINCT FROM OLD.assignee_member_id THEN
        NEW.last_activity_at := now();
    END IF;
    RETURN NEW;
END$$;

CREATE OR REPLACE VIEW mcp_csat_settings WITH (security_barrier = true) AS
SELECT c.enabled, c.scale, c.question, c.send_delay_minutes, c.response_window_days, c.bad_reasons, c.queue_ids, c.updated_at
  FROM csat_settings c WHERE (SELECT hd_has_right('tickets.view_queue'));

CREATE OR REPLACE VIEW mcp_queue_presence WITH (security_barrier = true) AS
SELECT p.member_id, m.display_name, m.member_kind, p.availability, p.capacity, p.updated_at
  FROM queue_presence p JOIN members m ON m.id = p.member_id
 WHERE p.member_id = app_current_member_id()
    OR EXISTS (SELECT 1 FROM queue_members qm WHERE qm.member_id = p.member_id AND qm.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue')))
    OR (SELECT hd_has_right('tickets.view_all'));

-- mcp_tickets gains the new column LAST (the kernel's rule: append, never reorder), re-created with the same body plus first_reopened_at.
CREATE OR REPLACE VIEW mcp_tickets WITH (security_barrier = true) AS
SELECT t.id AS ticket_id, t.number, t.subject, t.requester_id, rq.display_name AS requester_name, rq.kind AS requester_kind,
       t.submitter_member_id, t.organization_id, o.name AS organization_name,
       t.queue_id, q.name AS queue_name, t.assignee_member_id, am.display_name AS assignee_name, am.member_kind AS assignee_kind,
       t.status_id, s.key AS status, s.label AS status_label, s.category AS status_category, s.color AS status_color,
       t.priority, t.type_id, y.key AS type, t.category_id, c.name AS category_name, t.channel, t.custom_values,
       t.due_at, t.pending_until, t.hold_reason, t.resolution_code, t.parent_id, t.merged_into_id, t.external_id, t.board_position,
       t.reopen_count, t.sla_policy_id,
       t.first_response_at, t.last_agent_reply_at, t.last_requester_reply_at, t.last_activity_at, t.solved_at, t.closed_at,
       t.created_by, t.created_at, t.updated_at,
       (t.last_requester_reply_at IS NOT NULL AND (t.last_agent_reply_at IS NULL OR t.last_requester_reply_at > t.last_agent_reply_at)
        AND s.category NOT IN ('solved', 'closed')) AS needs_reply,
       (SELECT array_agg(g.slug ORDER BY g.slug) FROM ticket_tags tt JOIN tags g ON g.id = tt.tag_id WHERE tt.ticket_id = t.id) AS tags,
       (SELECT count(*) FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'public_reply') AS reply_count,
       sla.state AS sla_state, sla.due_at AS sla_next_due_at, sla.metric AS sla_next_metric,
       t.first_reopened_at
  FROM tickets t
  JOIN ticket_statuses s ON s.id = t.status_id
  JOIN queues q ON q.id = t.queue_id
  JOIN requesters rq ON rq.id = t.requester_id
  LEFT JOIN organizations o ON o.id = t.organization_id
  LEFT JOIN members am ON am.id = t.assignee_member_id
  LEFT JOIN ticket_types y ON y.id = t.type_id
  LEFT JOIN categories c ON c.id = t.category_id
  LEFT JOIN LATERAL (
      SELECT g.metric, g.due_at,
             hd_sla_state(g.due_at, g.started_at, g.paused_at, g.met_at, g.breached_at, p.warning_percent) AS state
        FROM sla_targets g LEFT JOIN sla_policies p ON p.id = g.policy_id
       WHERE g.ticket_id = t.id AND g.met_at IS NULL
       ORDER BY CASE WHEN g.breached_at IS NOT NULL OR now() > g.due_at THEN 0 ELSE 1 END, g.due_at NULLS LAST
       LIMIT 1) sla ON true
 WHERE (SELECT hd_has_right('tickets.view_all'))
    OR t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue'))
    OR t.id IN (SELECT hd_own_ticket_ids());

GRANT SELECT ON mcp_csat_settings, mcp_queue_presence, mcp_tickets TO helpdesk_records_ro, helpdesk_rw;
GRANT SELECT ON mcp_tickets TO helpdesk_activity_ro;
COMMIT;
