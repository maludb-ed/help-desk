-- Phase 0 proof of the schema (docs/helpdesk-design.md §10). Everything happens inside one transaction that is ROLLED
-- BACK: fixtures written as helpdesk_rw, reads as helpdesk_records_ro / _activity_ro, acting members set through
-- app.member_id exactly as PHP and the MCP servers set it. Run as postgres on the SCRATCH database:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d helpdesk_dev -f db/proof/phase0_proof.sql
-- Prints one line per check (ok / FAIL) and the count.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer
SET ROLE helpdesk_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid',  'super_admin', 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid',  'user', 'active', 'write', '{user}'),           -- a requester (an employee)
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid',  'user', 'active', 'write', '{support,user}'),   -- support staff in IT
 (28, 'human', 'SMOKE Sam',     'sam@example.invalid',    'user', 'active', 'write', '{collaborator,user}'),
 (29, 'human', 'SMOKE Lena',    'lena@example.invalid',   'user', 'active', 'write', '{lead,user}'),      -- a lead (view_all)
 (30, 'human', 'SMOKE Dana',    'dana@example.invalid',   'user', 'active', 'write', '{support,user}'),   -- support staff in Facilities, not IT
 (31, 'human', 'SMOKE Ext',     'ext@customer.invalid',   'user', 'active', 'write', '{user}'),           -- an invited external member
 (40, 'agent', 'SMOKE Frontline', NULL,                   'user', 'active', 'write', '{support,user}'),   -- an OS agent working the IT queue
 (41, 'agent', 'SMOKE Watcher', NULL,                     'user', 'active', NULL,    '{}');               -- an agent not admitted yet
UPDATE members SET is_external = true WHERE id = 31;
INSERT INTO departments (id, name, is_system, system_key) VALUES (5, 'IT', true, 'it'), (6, 'Facilities', false, NULL);
INSERT INTO department_members (member_id, department_id) VALUES (27, 5), (28, 5), (30, 6), (26, 6);
INSERT INTO queues (key, name, department_id, include_department) VALUES ('it', 'IT help', 5, true), ('facilities', 'Facilities', 6, false);
INSERT INTO queue_members (queue_id, member_id, is_lead) SELECT id, 30, true FROM queues WHERE key = 'facilities';
INSERT INTO queue_members (queue_id, member_id, is_lead) SELECT id, 40, false FROM queues WHERE key = 'it';
INSERT INTO organizations (name, members_see_each_other) VALUES ('SMOKE Customer Co', true);
INSERT INTO organization_domains (domain, organization_id) SELECT 'customer.invalid', id FROM organizations WHERE name = 'SMOKE Customer Co';
CREATE TEMP TABLE fx AS SELECT (SELECT id FROM queues WHERE key = 'it') AS it, (SELECT id FROM queues WHERE key = 'facilities') AS fac,
                               (SELECT id FROM queues WHERE key = 'general') AS gen, (SELECT id FROM organizations WHERE name = 'SMOKE Customer Co') AS org;
GRANT SELECT ON fx TO PUBLIC;
-- requesters: Priya (member), an external by email, the invited external member, the agent
SELECT hd_requester_for_member(26) AS priya_r \gset
SELECT hd_requester_for_email('Jo@Customer.Invalid', 'Jo Customer') AS jo_r \gset
SELECT hd_requester_for_member(31) AS ext_r \gset
SELECT hd_requester_for_member(40) AS agent_r \gset
-- tickets
INSERT INTO tickets (subject, requester_id, queue_id, priority, channel, created_by) VALUES ('SMOKE laptop fan is loud', :priya_r, (SELECT it FROM fx), 'normal', 'web', 26);
INSERT INTO tickets (subject, requester_id, queue_id, priority, channel) VALUES ('SMOKE cannot log in', :jo_r, (SELECT it FROM fx), 'urgent', 'email');
INSERT INTO tickets (subject, requester_id, queue_id, priority, channel) VALUES ('SMOKE broken chair', :priya_r, (SELECT fac FROM fx), 'low', 'form');
INSERT INTO tickets (subject, requester_id, queue_id, priority, channel, submitter_member_id) VALUES ('SMOKE disk nearly full on office VM', :agent_r, (SELECT it FROM fx), 'high', 'agent', 40);
INSERT INTO tickets (subject, requester_id, queue_id, priority, channel) VALUES ('SMOKE invoice question', :ext_r, (SELECT gen FROM fx), 'normal', 'web');
CREATE TEMP TABLE tk AS
SELECT (SELECT id FROM tickets WHERE subject = 'SMOKE laptop fan is loud') AS fan,
       (SELECT id FROM tickets WHERE subject = 'SMOKE cannot log in') AS login,
       (SELECT id FROM tickets WHERE subject = 'SMOKE broken chair') AS chair,
       (SELECT id FROM tickets WHERE subject = 'SMOKE disk nearly full on office VM') AS disk,
       (SELECT id FROM tickets WHERE subject = 'SMOKE invoice question') AS invoice;
GRANT SELECT ON tk TO PUBLIC;
UPDATE tickets SET assignee_member_id = 27 WHERE id = (SELECT fan FROM tk);
UPDATE tickets SET assignee_member_id = 40 WHERE id = (SELECT login FROM tk);       -- the agent holds a ticket
INSERT INTO messages (ticket_id, kind, author_member_id, body_text) VALUES ((SELECT fan FROM tk), 'internal_note', 27, 'SMOKE note: probably dust');
INSERT INTO ticket_followers (ticket_id, kind, member_id) VALUES ((SELECT chair FROM tk), 'follower', 28);
INSERT INTO tags (slug, label) VALUES ('hardware', 'Hardware');
INSERT INTO ticket_tags SELECT fan, id FROM tk, tags WHERE slug = 'hardware';
SELECT hd_secure_link_mint((SELECT login FROM tk)) AS jo_link \gset
RESET ROLE;

-- ---------------------------------------------------------------- 1. the catalogue, the seeds, the number
SELECT pg_temp.check((SELECT count(*) FROM hd_roles) = 5 AND (SELECT count(*) FROM hd_rights) = 14 AND (SELECT count(*) FROM hd_roles WHERE is_admin) = 1,
                     'five roles, fourteen rights, one admin role');
SELECT pg_temp.check((SELECT role_key FROM hd_roles ORDER BY sort_order LIMIT 1) = 'user', 'the base role is keyed user (the installer''s grant)');
SELECT pg_temp.check((SELECT count(*) FROM hd_role_rights WHERE role_key = 'admin') = 14, 'the admin role gives every right');
SELECT pg_temp.check((SELECT count(*) FROM ticket_statuses) = 6 AND (SELECT count(*) FROM status_transitions) = 16 AND (SELECT count(*) FROM ticket_types) = 4
                     AND (SELECT count(*) FROM saved_views WHERE is_system) = 8 AND (SELECT count(*) FROM sla_policies) = 1 AND (SELECT count(*) FROM business_hours) = 1,
                     'seeded: six statuses, sixteen transitions, four types, eight standard views, one policy, one schedule');
-- (a sequence survives the rollback, so the proof checks the shape and the step, not the absolute number: HD-1001 is the sequence's START)
SELECT pg_temp.check((SELECT bool_and(number ~ '^HD-[0-9]{4,}$') FROM tickets)
                     AND (SELECT split_part(number, '-', 2)::int FROM tickets WHERE id = (SELECT invoice FROM tk)) - (SELECT split_part(number, '-', 2)::int FROM tickets WHERE id = (SELECT fan FROM tk)) = 4
                     AND (SELECT split_part(number, '-', 2)::int FROM tickets WHERE id = (SELECT fan FROM tk)) >= 1001,
                     'numbering HD-NNNN from one sequence starting at 1001, never reused (D9)');
SELECT pg_temp.check((SELECT status_id FROM tickets WHERE id = (SELECT fan FROM tk)) = (SELECT id FROM ticket_statuses WHERE key = 'new'), 'a new ticket starts in the default status');
SELECT pg_temp.check((SELECT organization_id FROM tickets WHERE id = (SELECT login FROM tk)) = (SELECT org FROM fx), 'an email from customer.invalid is claimed by the organization through its domain');
SELECT pg_temp.check((SELECT kind FROM requesters WHERE id = :jo_r) = 'external' AND (SELECT kind FROM requesters WHERE id = :agent_r) = 'agent' AND (SELECT kind FROM requesters WHERE id = :priya_r) = 'member',
                     'three kinds of requester: external, agent, member');
SELECT pg_temp.check(hd_requester_for_email('PRIYA@example.invalid') = :priya_r, 'a member''s address maps to the member''s requester row');
SELECT pg_temp.check((SELECT count(*) FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND met_at IS NULL) = 2, 'a new ticket has first-response and resolution targets');
SELECT pg_temp.check((SELECT target_minutes FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND metric = 'first_response') = 60, 'an urgent ticket gets the policy''s urgent target (60 min)');
SELECT pg_temp.check((SELECT due_at FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND metric = 'first_response') IS NOT NULL, 'the due time is computed on business hours');
SELECT pg_temp.check(hd_business_add_minutes('2026-10-02 16:30+00', 60, (SELECT id FROM business_hours WHERE is_default)) = '2026-10-05 09:30+00'::timestamptz,
                     'business time: 60 min from Friday 16:30 lands Monday 09:30 (Mon-Fri 09-17 UTC)');
SELECT pg_temp.check(hd_business_add_minutes('2026-10-02 16:30+00', 60, NULL) = '2026-10-02 17:30+00'::timestamptz, 'calendar time when the policy has no schedule');

-- ---------------------------------------------------------------- 2. the vocabulary's own rules
SET ROLE helpdesk_rw;
SELECT pg_temp.refused($$INSERT INTO ticket_statuses (key, label, category) VALUES ('custom_new', 'Custom', 'new')$$, 'never new or closed', 'a custom status cannot be new or closed');
SELECT pg_temp.refused($$UPDATE ticket_statuses SET category = 'open' WHERE key = 'closed'$$, 'keeps its category', 'a built-in status keeps its category');
INSERT INTO ticket_statuses (key, label, category, color, sort_order) VALUES ('waiting_vendor', 'Waiting on vendor', 'on_hold', 'secondary', 45);
SELECT pg_temp.check((SELECT requires FROM hd_transition((SELECT id FROM ticket_statuses WHERE key = 'open'), (SELECT id FROM ticket_statuses WHERE key = 'solved'))) = '{resolution_code}',
                     'open → solved requires a resolution code');
SELECT pg_temp.check((SELECT needs_right FROM hd_transition((SELECT id FROM ticket_statuses WHERE key = 'open'), (SELECT id FROM ticket_statuses WHERE key = 'closed'))) = 'tickets.manage',
                     'an early close needs tickets.manage');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM hd_transition((SELECT id FROM ticket_statuses WHERE key = 'closed'), (SELECT id FROM ticket_statuses WHERE key = 'open'))), 'closed is terminal');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM hd_transition((SELECT id FROM ticket_statuses WHERE key = 'on_hold'), (SELECT id FROM ticket_statuses WHERE key = 'waiting_vendor'))),
                     'a move inside one category (on hold → a custom on-hold status) is allowed');
RESET ROLE;

-- ---------------------------------------------------------------- 3. who sees what, as the records role
SET ROLE helpdesk_records_ro;
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 5, 'app_roles readable by the records role');
SELECT set_config('app.member_id', '26', true);          -- Priya: Requester only
SELECT pg_temp.check((SELECT array_agg(ticket_id ORDER BY ticket_id) FROM mcp_tickets) = ARRAY[(SELECT fan FROM tk), (SELECT chair FROM tk)], 'Priya sees her own two tickets and nothing else');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_messages WHERE kind = 'internal_note'), 'Priya never sees an internal note on her own ticket');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_sla_targets) AND NOT EXISTS (SELECT 1 FROM mcp_sla_policies), 'Priya sees no SLA internals');
SELECT pg_temp.check((SELECT count(*) FROM mcp_requesters) = 1 AND (SELECT email FROM mcp_requesters) = 'priya@example.invalid', 'Priya sees one requester — herself, with her own email');
SELECT pg_temp.check((SELECT count(*) FROM mcp_queues) = 3 AND NOT EXISTS (SELECT 1 FROM mcp_queues WHERE mine), 'Priya sees the queues exist (to file) and holds none');
SELECT pg_temp.check((SELECT count(*) FROM mcp_ticket_statuses) = 7 AND (SELECT count(*) FROM mcp_status_transitions) = 16, 'the vocabulary is readable by a requester (the create form)');
SELECT set_config('app.member_id', '27', true);          -- Marco: support, in IT through the department
SELECT pg_temp.check((SELECT array_agg(ticket_id ORDER BY ticket_id) FROM mcp_tickets) = ARRAY[(SELECT fan FROM tk), (SELECT login FROM tk), (SELECT disk FROM tk)], 'Marco sees the IT queue''s three tickets (through his department) and not Facilities'' or General''s');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_messages WHERE kind = 'internal_note'), 'Marco sees the note');
SELECT pg_temp.check((SELECT email FROM mcp_requesters WHERE requester_id = :jo_r) = 'jo@customer.invalid', 'Marco sees the external requester''s email (the requester card)');
SELECT pg_temp.check((SELECT bool_and(mine) FROM mcp_queues WHERE key = 'it') AND NOT (SELECT bool_or(mine) FROM mcp_queues WHERE key <> 'it'), 'Marco holds IT only');
SELECT pg_temp.check((SELECT count(*) FROM mcp_queue_members WHERE queue_id = (SELECT it FROM fx)) = 3, 'IT''s members: Marco and Sam through the department, the agent named');
SELECT pg_temp.check((SELECT assignee_kind FROM mcp_tickets WHERE ticket_id = (SELECT login FROM tk)) = 'agent', 'an agent is an assignee like a person');
SELECT pg_temp.check((SELECT requester_kind FROM mcp_tickets WHERE ticket_id = (SELECT disk FROM tk)) = 'agent' AND (SELECT channel FROM mcp_tickets WHERE ticket_id = (SELECT disk FROM tk)) = 'agent', 'an agent files a ticket as a requester');
SELECT pg_temp.check((SELECT sla_state FROM mcp_tickets WHERE ticket_id = (SELECT login FROM tk)) IN ('on_track', 'at_risk', 'paused'), 'a fresh ticket''s SLA state is on track (or paused outside office hours)');
SELECT set_config('app.member_id', '28', true);          -- Sam: collaborator in IT, follows the chair
SELECT pg_temp.check((SELECT array_agg(ticket_id ORDER BY ticket_id) FROM mcp_tickets) = ARRAY[(SELECT fan FROM tk), (SELECT login FROM tk), (SELECT chair FROM tk), (SELECT disk FROM tk)], 'Sam sees IT''s tickets and the Facilities ticket he follows');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_messages WHERE kind = 'internal_note'), 'a collaborator sees notes');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_canned_responses), 'a collaborator has no canned responses (tickets.work)');
SELECT set_config('app.member_id', '30', true);          -- Dana: support in Facilities (named), not IT
SELECT pg_temp.check((SELECT array_agg(ticket_id) FROM mcp_tickets) = ARRAY[(SELECT chair FROM tk)], 'Dana sees Facilities only');
SELECT pg_temp.check((SELECT i_lead FROM mcp_queues WHERE key = 'facilities') AND NOT (SELECT i_lead FROM mcp_queues WHERE key = 'it'), 'Dana leads Facilities, not IT');
SELECT set_config('app.member_id', '29', true);          -- Lena: lead (view_all)
SELECT pg_temp.check((SELECT count(*) FROM mcp_tickets) = 5 AND (SELECT count(*) FROM mcp_sla_targets) >= 10, 'a lead sees every ticket and every target');
SELECT set_config('app.member_id', '1', true);           -- the owner
SELECT pg_temp.check((SELECT count(*) FROM mcp_tickets) = 5 AND (SELECT count(*) FROM mcp_mailboxes) = 0 AND (SELECT count(*) FROM mcp_automation_rules) = 2, 'the super-admin sees everything (two seeded rules)');
SELECT set_config('app.member_id', '31', true);          -- the invited external member (Customer Co)
SELECT pg_temp.check((SELECT array_agg(ticket_id ORDER BY ticket_id) FROM mcp_tickets) = ARRAY[(SELECT login FROM tk), (SELECT invoice FROM tk)], 'an external member sees their own ticket and their organization''s (the org allows it)');
SELECT pg_temp.check((SELECT email FROM mcp_requesters WHERE requester_id = :jo_r) IS NULL, 'but not the fellow requester''s email');
SELECT set_config('app.member_id', '40', true);          -- the agent
SELECT pg_temp.check((SELECT array_agg(ticket_id ORDER BY ticket_id) FROM mcp_tickets) = ARRAY[(SELECT fan FROM tk), (SELECT login FROM tk), (SELECT disk FROM tk)], 'the agent sees its queue''s tickets — the same rule as a person');
SELECT set_config('app.member_id', '41', true);          -- an agent not admitted
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_tickets) AND NOT EXISTS (SELECT 1 FROM mcp_queues), 'an agent with no capability sees nothing');
SELECT pg_temp.check(mcp_member_kind(41) IS NULL AND mcp_member_kind(40) = 'agent', 'mcp_member_kind answers only for an admitted member');
SELECT pg_temp.check(mcp_admit_agent(41), 'first contact admits an active agent');
SELECT pg_temp.check(mcp_member_kind(41) = 'agent', '…who is then admitted');
SELECT pg_temp.check(NOT mcp_admit_agent(41) AND NOT mcp_admit_agent(26), '…once, and never a human');
SELECT set_config('app.member_id', '999', true);         -- nobody the mirror knows
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_tickets) AND NOT EXISTS (SELECT 1 FROM mcp_members) AND NOT EXISTS (SELECT 1 FROM mcp_ticket_statuses), 'an unknown member sees nothing');
SELECT set_config('app.member_id', '', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_tickets), 'nobody (no acting member) sees nothing');
SELECT pg_temp.refused($$SELECT * FROM tickets$$, 'permission denied', 'the records role cannot read a base table');
SELECT pg_temp.refused($$SELECT * FROM activity_log$$, 'permission denied', 'the records role cannot read the activity log');
SELECT pg_temp.refused($$SELECT hd_secure_link_ticket('00')$$, 'permission denied', 'the records role cannot open a secure link');
RESET ROLE;

-- ---------------------------------------------------------------- 4. the life of a ticket, as the writer
SET ROLE helpdesk_rw;
-- support replies: first response met
INSERT INTO messages (ticket_id, kind, author_member_id, body_text) VALUES ((SELECT login FROM tk), 'public_reply', 40, 'SMOKE reply: please try a password reset');
SELECT pg_temp.check((SELECT met_at IS NOT NULL FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND metric = 'first_response'), 'the agent''s public reply meets first response');
SELECT pg_temp.check((SELECT first_response_at IS NOT NULL AND last_agent_reply_at IS NOT NULL FROM tickets WHERE id = (SELECT login FROM tk)), 'first_response_at and last_agent_reply_at are stamped');
-- pending pauses the clock
UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE key = 'pending'), pending_until = now() + interval '2 days' WHERE id = (SELECT login FROM tk);
SELECT pg_temp.check((SELECT bool_and(paused_at IS NOT NULL) FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND met_at IS NULL), 'pending pauses every open target');
-- the requester writes back: next_response starts, pending → open, clock resumes
-- (one transaction = one now(): the requester's reply is dated a second later, as it would be)
INSERT INTO messages (ticket_id, kind, author_requester_id, body_text, channel, created_at) VALUES ((SELECT login FROM tk), 'public_reply', :jo_r, 'SMOKE: still cannot log in', 'portal', now() + interval '1 second');
SELECT pg_temp.check((SELECT key FROM ticket_statuses WHERE id = (SELECT status_id FROM tickets WHERE id = (SELECT login FROM tk))) = 'open', 'a requester''s reply on pending reopens the ticket');
SELECT pg_temp.check((SELECT pending_until IS NULL FROM tickets WHERE id = (SELECT login FROM tk)), 'leaving pending clears pending_until');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND metric = 'next_response' AND met_at IS NULL AND paused_at IS NULL), 'next response is running');
SELECT pg_temp.check((SELECT bool_and(paused_at IS NULL) FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND met_at IS NULL), 'open resumes the clock');
SET ROLE helpdesk_records_ro; SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check((SELECT needs_reply FROM mcp_tickets WHERE ticket_id = (SELECT login FROM tk)), 'needs_reply: the requester spoke last');
RESET ROLE; SET ROLE helpdesk_rw;
-- solve, reopen, close
UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE key = 'solved'), resolution_code = 'resolved' WHERE id = (SELECT login FROM tk);
SELECT pg_temp.check((SELECT solved_at IS NOT NULL FROM tickets WHERE id = (SELECT login FROM tk)) AND NOT EXISTS (SELECT 1 FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND met_at IS NULL), 'solving meets the open targets and stamps solved_at');
UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE key = 'open') WHERE id = (SELECT login FROM tk);
SELECT pg_temp.check((SELECT reopen_count = 1 AND solved_at IS NULL AND resolution_code IS NULL FROM tickets WHERE id = (SELECT login FROM tk)), 'reopening counts, clears solved_at and the code');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM sla_targets WHERE ticket_id = (SELECT login FROM tk) AND metric = 'resolution' AND met_at IS NULL), 'reopening restarts resolution');
UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE key = 'solved'), resolution_code = 'resolved' WHERE id = (SELECT login FROM tk);
UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE key = 'closed') WHERE id = (SELECT login FROM tk);
SELECT pg_temp.check((SELECT closed_at IS NOT NULL FROM tickets WHERE id = (SELECT login FROM tk)), 'closing stamps closed_at');
SELECT pg_temp.check((SELECT expires_at IS NOT NULL AND expires_at > now() + interval '89 days' FROM ticket_links_secure WHERE ticket_id = (SELECT login FROM tk) AND rotated_at IS NULL), 'closing dates the secure link (90 days)');
-- priority change re-aims open targets
SELECT pg_temp.check((SELECT target_minutes FROM sla_targets WHERE ticket_id = (SELECT fan FROM tk) AND metric = 'first_response') = 480, 'normal: first response 480');
UPDATE tickets SET priority = 'urgent' WHERE id = (SELECT fan FROM tk);
SELECT pg_temp.check((SELECT target_minutes FROM sla_targets WHERE ticket_id = (SELECT fan FROM tk) AND metric = 'first_response' AND met_at IS NULL) = 60, 'raising to urgent re-aims first response to 60');
-- tags count
SELECT pg_temp.check((SELECT usage_count FROM tags WHERE slug = 'hardware') = 1, 'tag usage follows the join table');
DELETE FROM ticket_tags WHERE tag_id = (SELECT id FROM tags WHERE slug = 'hardware');
SELECT pg_temp.check((SELECT usage_count FROM tags WHERE slug = 'hardware') = 0, '…down as well');

-- ---------------------------------------------------------------- 5. the external requester's door (D1)
SELECT pg_temp.check(hd_secure_link_ticket(:'jo_link') = (SELECT login FROM tk), 'the mailed secure link opens Jo''s ticket');
SELECT pg_temp.check(hd_secure_link_ticket('deadbeef') IS NULL AND hd_secure_link_ticket(repeat('0', 48)) IS NULL, 'a malformed or unknown token opens nothing');
SELECT hd_secure_link_mint((SELECT login FROM tk)) AS jo_link2 \gset
SELECT pg_temp.check(hd_secure_link_ticket(:'jo_link') IS NULL AND hd_secure_link_ticket(:'jo_link2') = (SELECT login FROM tk), 'rotating kills the old link and the new one works');
UPDATE ticket_links_secure SET expires_at = now() - interval '1 second' WHERE rotated_at IS NULL AND ticket_id = (SELECT login FROM tk);
SELECT pg_temp.check(hd_secure_link_ticket(:'jo_link2') IS NULL, 'an expired link opens nothing');
SELECT pg_temp.check((SELECT count(*) FROM ticket_links_secure WHERE token_hash IN (:'jo_link', :'jo_link2')) = 0, 'only hashes are stored, never the token');

-- ---------------------------------------------------------------- 6. the activity view, as the activity role
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, ticket_id, queue_id, after)
VALUES (27, 'web', 'ticket.note', 'ticket', (SELECT fan FROM tk), (SELECT fan FROM tk), (SELECT it FROM fx), '{"excerpt": "probably dust"}'),
       (30, 'web', 'ticket.assign', 'ticket', (SELECT chair FROM tk), (SELECT chair FROM tk), (SELECT fac FROM fx), '{"assignee": 30}'),
       (NULL, 'cron', 'directory.sync', NULL, NULL, NULL, NULL, '{"members": 9}');
RESET ROLE;
SET ROLE helpdesk_activity_ro;
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check((SELECT array_agg(action ORDER BY action) FROM mcp_activity_log) = '{ticket.assign,ticket.note}', 'Priya sees the history of her own tickets (both queues) and not the sync');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check((SELECT array_agg(action) FROM mcp_activity_log) = '{ticket.note}', 'Marco sees IT''s history, not Facilities''');
SELECT set_config('app.member_id', '29', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 3, 'a lead sees every row');
SELECT pg_temp.refused($$SELECT * FROM mcp_sla_policies$$, 'permission denied', 'the activity role has no records view but names and queues');
RESET ROLE;

-- ---------------------------------------------------------------- the verdict
\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label AS result FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed, of ' || count(*) AS summary FROM proof;
ROLLBACK;
