-- 015: the mcp_* visibility views — the only thing the two read roles see (memory.md §1). Every WHERE clause IS the
-- row rule (db/014); the servers never filter in Python. Caller checks are scalar subqueries or uncorrelated sets,
-- tested once per statement. Plus the two functions the MCP servers need at first contact (Projects' db/012,
-- txtSchedules' db/016): mcp_admit_agent() and mcp_member_kind().
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO helpdesk_records_ro, helpdesk_activity_ro, helpdesk_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO helpdesk_records_ro, helpdesk_activity_ro, helpdesk_rw;

-- ---------------------------------------------------------------------------------------------
-- People. No email or phone but one's own and — for support staff — a requester's on the requester card.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.business_role, m.is_external, m.status, m.capability, m.roles,
       m.job_title, m.timezone,
       CASE WHEN m.id = app_current_member_id() THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE (SELECT app_is_active_member()) AND m.status = 'active';

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d
 WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_organizations WITH (security_barrier = true) AS
SELECT o.id AS organization_id, o.name, o.support_tier, o.tags, o.custom_values, o.sla_policy_id, o.members_see_each_other, o.active,
       CASE WHEN (SELECT hd_has_right('tickets.view_queue')) THEN o.notes END AS notes,
       (SELECT array_agg(d.domain::text ORDER BY d.domain) FROM organization_domains d WHERE d.organization_id = o.id) AS domains,
       o.created_at, o.updated_at
  FROM organizations o
 WHERE (SELECT hd_has_right('tickets.view_queue'))
    OR o.id IN (SELECT r.organization_id FROM requesters r WHERE r.member_id = app_current_member_id());

CREATE OR REPLACE VIEW mcp_requesters WITH (security_barrier = true) AS
SELECT r.id AS requester_id, r.kind, r.member_id, r.display_name, r.organization_id, o.name AS organization_name, r.locale, r.timezone,
       CASE WHEN r.member_id = app_current_member_id() OR (SELECT hd_has_right('tickets.view_queue')) THEN r.primary_email::text END AS email,
       CASE WHEN r.member_id = app_current_member_id() OR (SELECT hd_has_right('tickets.view_queue')) THEN r.phone END AS phone,
       CASE WHEN (SELECT hd_has_right('tickets.view_queue')) THEN r.notes END AS notes,
       r.custom_values, r.merged_into_id, r.last_seen_at, r.created_at,
       (SELECT count(*) FROM tickets t WHERE t.requester_id = r.id) AS ticket_count
  FROM requesters r
  LEFT JOIN organizations o ON o.id = r.organization_id
 WHERE r.member_id = app_current_member_id()
    OR (SELECT hd_has_right('tickets.view_queue'))
    OR r.id IN (SELECT t.requester_id FROM tickets t WHERE t.id IN (SELECT hd_own_ticket_ids()));

-- ---------------------------------------------------------------------------------------------
-- Queues and the vocabulary: every admitted member reads them (the create form needs them; an agent fills fields
-- from them — get_vocabulary). Queue members are shown to the queue's holders and leads.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_queues WITH (security_barrier = true) AS
SELECT q.id AS queue_id, q.key, q.name, q.description, q.department_id, d.name AS department_name, q.include_department,
       q.assignment_method, q.default_assignee_member_id, q.business_hours_id, q.sla_policy_id, q.email_address::text AS email_address,
       q.sort_order, q.archived_at,
       (q.id IN (SELECT hd_held_queue_ids())) AS mine,
       (SELECT hd_is_queue_lead(q.id)) AS i_lead,
       (SELECT count(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.queue_id = q.id AND s.category IN ('new', 'open', 'pending', 'on_hold')) AS open_count
  FROM queues q
  LEFT JOIN departments d ON d.id = q.department_id
 WHERE (SELECT app_is_active_member()) AND q.archived_at IS NULL;

CREATE OR REPLACE VIEW mcp_queue_members WITH (security_barrier = true) AS
SELECT qm.queue_id, qm.member_id, m.display_name, m.member_kind, qm.is_lead, 'named'::text AS how
  FROM queue_members qm JOIN members m ON m.id = qm.member_id AND m.status = 'active'
 WHERE qm.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue'))
UNION ALL
SELECT q.id, dm.member_id, m.display_name, m.member_kind, false, 'department'
  FROM queues q
  JOIN department_members dm ON dm.department_id = q.department_id AND dm.left_at IS NULL
  JOIN members m ON m.id = dm.member_id AND m.status = 'active' AND m.capability IS NOT NULL
 WHERE q.include_department AND q.archived_at IS NULL
   AND q.id IN (SELECT hd_queue_ids_with_right('tickets.view_queue'))
   AND NOT EXISTS (SELECT 1 FROM queue_members x WHERE x.queue_id = q.id AND x.member_id = dm.member_id);

CREATE OR REPLACE VIEW mcp_ticket_statuses WITH (security_barrier = true) AS
SELECT s.id AS status_id, s.key, s.label, s.category, s.color, s.sort_order, s.is_default, s.on_board, s.clock_runs, s.is_system, s.archived_at
  FROM ticket_statuses s WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_status_transitions WITH (security_barrier = true) AS
SELECT t.from_category, t.to_category, t.requires, t.may_carry, t.needs_right, t.label
  FROM status_transitions t WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_ticket_types WITH (security_barrier = true) AS
SELECT y.id AS type_id, y.key, y.label, y.description, y.sort_order, y.is_system, y.archived_at
  FROM ticket_types y WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_categories WITH (security_barrier = true) AS
SELECT c.id AS category_id, c.parent_id, c.name, c.description, c.default_queue_id, c.default_type_id, c.default_priority, c.sla_policy_id,
       c.requester_visible, c.sort_order, c.archived_at,
       (SELECT string_agg(p.name, ' › ' ORDER BY p.depth DESC) FROM (
            WITH RECURSIVE up AS (SELECT id, parent_id, name, 0 AS depth FROM categories WHERE id = c.id
                                  UNION ALL SELECT x.id, x.parent_id, x.name, up.depth + 1 FROM categories x JOIN up ON x.id = up.parent_id)
            SELECT name, depth FROM up) p) AS path
  FROM categories c WHERE (SELECT app_is_active_member()) AND (c.requester_visible OR (SELECT hd_has_right('tickets.view_queue')));

CREATE OR REPLACE VIEW mcp_tags WITH (security_barrier = true) AS
SELECT g.id AS tag_id, g.slug, g.label, g.usage_count FROM tags g WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_custom_field_defs WITH (security_barrier = true) AS
SELECT f.id AS field_id, f.key, f.label, f.field_type, f.options, f.applies_to, f.type_ids, f.required_on_solve, f.requester_visible, f.sort_order, f.archived_at
  FROM custom_field_defs f
 WHERE (SELECT app_is_active_member()) AND (f.requester_visible OR (SELECT hd_has_right('tickets.view_queue')));

CREATE OR REPLACE VIEW mcp_business_hours WITH (security_barrier = true) AS
SELECT b.id AS schedule_id, b.name, b.timezone, b.is_default,
       (SELECT jsonb_agg(jsonb_build_object('weekday', i.weekday, 'opens_at', i.opens_at, 'closes_at', i.closes_at) ORDER BY i.weekday, i.opens_at)
          FROM business_hours_intervals i WHERE i.schedule_id = b.id) AS intervals,
       (SELECT array_agg(h.holiday ORDER BY h.holiday) FROM business_hours_holidays h WHERE h.schedule_id = b.id AND h.holiday >= current_date - 365) AS holidays
  FROM business_hours b WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_sla_policies WITH (security_barrier = true) AS
SELECT p.id AS policy_id, p.name, p.description, p.sort_order, p.conditions, p.targets, p.business_hours_id, p.warning_percent, p.active, p.updated_at
  FROM sla_policies p WHERE (SELECT hd_has_right('tickets.view_queue'));

-- ---------------------------------------------------------------------------------------------
-- The ticket. The rule of db/014 as three uncorrelated sets. Internals (SLA detail, time, rules) ride on separate
-- views gated by hd_can_see_internals; the ticket row itself carries what a requester may see plus the derived facts.
-- ---------------------------------------------------------------------------------------------
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
       sla.state AS sla_state, sla.due_at AS sla_next_due_at, sla.metric AS sla_next_metric
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

-- The conversation: public replies to whoever sees the ticket; internal notes to the queue's holders alone.
CREATE OR REPLACE VIEW mcp_messages WITH (security_barrier = true) AS
SELECT m.id AS message_id, m.ticket_id, m.kind, m.author_member_id, am.display_name AS author_member_name, am.member_kind AS author_kind,
       m.author_requester_id, ar.display_name AS author_requester_name, m.body_text, m.channel, m.mentions, m.edited_at, m.created_at,
       (SELECT count(*) FROM attachments a WHERE a.message_id = m.id) AS attachment_count
  FROM messages m
  LEFT JOIN members am ON am.id = m.author_member_id
  LEFT JOIN requesters ar ON ar.id = m.author_requester_id
 WHERE m.ticket_id IN (SELECT ticket_id FROM mcp_tickets)
   AND (m.kind = 'public_reply'
        OR (SELECT hd_has_right('tickets.view_all'))
        OR m.ticket_id IN (SELECT t.id FROM tickets t WHERE t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue'))));

CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.ticket_id, a.message_id, a.article_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.created_at
  FROM attachments a
 WHERE (a.message_id IN (SELECT message_id FROM mcp_messages))
    OR (a.article_id IS NOT NULL AND a.ticket_id IS NULL AND (SELECT hd_has_right('kb.read')));

CREATE OR REPLACE VIEW mcp_ticket_followers WITH (security_barrier = true) AS
SELECT f.ticket_id, f.kind, f.member_id, m.display_name AS member_name, f.requester_id, r.display_name AS requester_name, f.created_at
  FROM ticket_followers f
  LEFT JOIN members m ON m.id = f.member_id
  LEFT JOIN requesters r ON r.id = f.requester_id
 WHERE f.ticket_id IN (SELECT ticket_id FROM mcp_tickets);

CREATE OR REPLACE VIEW mcp_ticket_links WITH (security_barrier = true) AS
SELECT l.id AS link_id, l.from_ticket_id, l.to_ticket_id, l.kind, t2.number AS to_number, t2.subject AS to_subject, l.created_at
  FROM ticket_links l JOIN tickets t2 ON t2.id = l.to_ticket_id
 WHERE l.from_ticket_id IN (SELECT ticket_id FROM mcp_tickets) AND l.to_ticket_id IN (SELECT ticket_id FROM mcp_tickets);

CREATE OR REPLACE VIEW mcp_ticket_viewers WITH (security_barrier = true) AS
SELECT v.ticket_id, v.member_id, m.display_name, v.composing, v.last_seen_at
  FROM ticket_viewers v JOIN members m ON m.id = v.member_id
 WHERE v.last_seen_at > now() - interval '2 minutes'
   AND v.ticket_id IN (SELECT t.ticket_id FROM mcp_tickets t WHERE (SELECT hd_can_see_internals(t.ticket_id)));

-- SLA targets, time entries, rule runs: the queue's internals.
CREATE OR REPLACE VIEW mcp_sla_targets WITH (security_barrier = true) AS
SELECT g.id AS target_id, g.ticket_id, g.metric, g.policy_id, p.name AS policy_name, g.target_minutes, g.started_at, g.due_at, g.paused_at,
       g.paused_total, g.met_at, g.breached_at,
       hd_sla_state(g.due_at, g.started_at, g.paused_at, g.met_at, g.breached_at, p.warning_percent) AS state
  FROM sla_targets g LEFT JOIN sla_policies p ON p.id = g.policy_id
 WHERE (SELECT hd_has_right('tickets.view_all'))
    OR g.ticket_id IN (SELECT t.id FROM tickets t WHERE t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue')));

CREATE OR REPLACE VIEW mcp_time_entries WITH (security_barrier = true) AS
SELECT e.id AS entry_id, e.ticket_id, e.member_id, m.display_name, e.minutes, e.billable, e.note, e.worked_on, e.created_at
  FROM time_entries e JOIN members m ON m.id = e.member_id
 WHERE e.member_id = app_current_member_id()
    OR (SELECT hd_has_right('tickets.view_all'))
    OR e.ticket_id IN (SELECT t.id FROM tickets t WHERE t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue')));

CREATE OR REPLACE VIEW mcp_canned_responses WITH (security_barrier = true) AS
SELECT c.id AS canned_id, c.title, c.folder, c.body_text, c.scope, c.owner_member_id, c.queue_id, c.side_effects, c.usage_count, c.archived_at, c.updated_at
  FROM canned_responses c
 WHERE c.archived_at IS NULL AND (SELECT hd_has_right('tickets.work'))
   AND (c.scope = 'global' OR c.owner_member_id = app_current_member_id() OR c.queue_id IN (SELECT hd_held_queue_ids()));

CREATE OR REPLACE VIEW mcp_saved_views WITH (security_barrier = true) AS
SELECT v.id AS view_id, v.name, v.scope, v.owner_member_id, v.queue_id, v.filters, v.sort_by, v.sort_dir, v.columns, v.layout, v.board,
       v.is_system, v.system_key, v.sort_order
  FROM saved_views v
 WHERE v.archived_at IS NULL AND (SELECT app_is_active_member())
   AND (v.scope = 'global' OR v.owner_member_id = app_current_member_id() OR v.queue_id IN (SELECT hd_held_queue_ids()));

CREATE OR REPLACE VIEW mcp_automation_rules WITH (security_barrier = true) AS
SELECT r.id AS rule_id, r.name, r.kind, r.event, r.conditions, r.actions, r.sort_order, r.active, r.run_count, r.last_run_at, r.updated_at
  FROM automation_rules r WHERE (SELECT hd_has_right('tickets.view_queue'));

CREATE OR REPLACE VIEW mcp_rule_runs WITH (security_barrier = true) AS
SELECT x.id AS run_id, x.rule_id, r.name AS rule_name, x.ticket_id, x.outcome, x.detail, x.ran_at
  FROM rule_runs x JOIN automation_rules r ON r.id = x.rule_id
 WHERE (SELECT hd_has_right('tickets.view_all'))
    OR x.ticket_id IN (SELECT t.id FROM tickets t WHERE t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue')));

-- Knowledge: public articles to everyone admitted; internal ones with kb.read; drafts to kb.edit.
CREATE OR REPLACE VIEW mcp_kb_sections WITH (security_barrier = true) AS
SELECT k.id AS section_id, k.parent_id, k.name, k.slug, k.description, k.visibility, k.sort_order
  FROM kb_sections k
 WHERE k.archived_at IS NULL AND (SELECT app_is_active_member()) AND (k.visibility = 'public' OR (SELECT hd_has_right('kb.read')));

CREATE OR REPLACE VIEW mcp_articles WITH (security_barrier = true) AS
SELECT a.id AS article_id, a.section_id, a.title, a.slug, a.body_text, a.status, a.visibility, a.tags, a.author_member_id,
       a.view_count, a.helpful_count, a.unhelpful_count, a.published_at, a.updated_at
  FROM articles a
 WHERE (SELECT app_is_active_member())
   AND (a.visibility = 'public' OR (SELECT hd_has_right('kb.read')))
   AND (a.status = 'published' OR (SELECT hd_has_right('kb.edit')));

CREATE OR REPLACE VIEW mcp_article_links WITH (security_barrier = true) AS
SELECT l.ticket_id, l.article_id, a.title, l.kind, l.created_at
  FROM article_links l JOIN articles a ON a.id = l.article_id
 WHERE l.ticket_id IN (SELECT ticket_id FROM mcp_tickets) AND l.article_id IN (SELECT article_id FROM mcp_articles);

CREATE OR REPLACE VIEW mcp_satisfaction_ratings WITH (security_barrier = true) AS
SELECT s.id AS rating_id, s.ticket_id, s.requester_id, s.score, s.bad_reason, s.comment, s.survey_sent_at, s.responded_at
  FROM satisfaction_ratings s
 WHERE (SELECT hd_has_right('tickets.view_all'))
    OR s.ticket_id IN (SELECT t.id FROM tickets t WHERE t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue')))
    OR s.requester_id = (SELECT hd_my_requester_id());

-- Mail, for the admin: never a body through MCP.
CREATE OR REPLACE VIEW mcp_mailboxes WITH (security_barrier = true) AS
SELECT b.id AS mailbox_id, b.address::text AS address, b.display_name, b.queue_id, b.provider, b.poll_enabled, b.last_polled_at, b.active
  FROM mailboxes b WHERE (SELECT hd_has_right('settings.manage'));

CREATE OR REPLACE VIEW mcp_inbound_email WITH (security_barrier = true) AS
SELECT e.id AS inbound_id, e.mailbox_id, e.message_id, e.from_address::text AS from_address, e.subject, e.received_at, e.status, e.error, e.ticket_id, e.processed_at
  FROM inbound_email e WHERE (SELECT hd_has_right('settings.manage'));

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.kind, n.ticket_id, n.title, n.body, n.read_at, n.created_at
  FROM notifications n WHERE n.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.ticket_id, d.agent_member_id, m.display_name AS agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.status,
       d.reply_excerpt, d.created_at, d.answered_at
  FROM agent_dispatches d JOIN members m ON m.id = d.agent_member_id
 WHERE d.agent_member_id = app_current_member_id()
    OR (SELECT hd_has_right('tickets.view_all'))
    OR d.ticket_id IN (SELECT t.id FROM tickets t WHERE t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue')));

CREATE OR REPLACE VIEW mcp_my_tokens WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.label, t.scope, t.last_used_at, t.expires_at, t.revoked_at, t.created_at
  FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id();

-- Activity: one's own rows; a queue's rows to its holders with view_queue; everything to view_all.
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS activity_id, l.occurred_at, l.actor_member_id, am.display_name AS actor_name, l.actor_requester_id, l.source, l.action,
       l.entity_type, l.entity_id, l.ticket_id, l.queue_id, l.before, l.after, l.agent_run_id, l.request_id
  FROM activity_log l
  LEFT JOIN members am ON am.id = l.actor_member_id
 WHERE l.actor_member_id = app_current_member_id()
    OR (SELECT hd_has_right('tickets.view_all'))
    OR l.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue'))
    OR l.ticket_id IN (SELECT hd_own_ticket_ids());

GRANT SELECT ON mcp_members, mcp_departments, mcp_organizations, mcp_requesters, mcp_queues, mcp_queue_members, mcp_ticket_statuses,
    mcp_status_transitions, mcp_ticket_types, mcp_categories, mcp_tags, mcp_custom_field_defs, mcp_business_hours, mcp_sla_policies,
    mcp_tickets, mcp_messages, mcp_attachments, mcp_ticket_followers, mcp_ticket_links, mcp_ticket_viewers, mcp_sla_targets, mcp_time_entries,
    mcp_canned_responses, mcp_saved_views, mcp_automation_rules, mcp_rule_runs, mcp_kb_sections, mcp_articles, mcp_article_links,
    mcp_satisfaction_ratings, mcp_mailboxes, mcp_inbound_email, mcp_notifications, mcp_agent_dispatches, mcp_my_tokens
    TO helpdesk_records_ro, helpdesk_rw;
GRANT SELECT ON mcp_activity_log TO helpdesk_activity_ro, helpdesk_rw;
GRANT SELECT ON mcp_members, mcp_tickets, mcp_queues TO helpdesk_activity_ro;   -- names and queue facts beside an activity row

COMMIT;
