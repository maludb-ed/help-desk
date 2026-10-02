-- 014: WHO SEES WHAT — the one rule (design §6), as uncorrelated sets the views test once per statement and as one
-- function PHP's gates ask per ticket. Both read the same facts, so a screen and a tool can never disagree.
--
--   a ticket is seen by: its requester (by member id), its submitter, its CCs and followers (members);
--                        a collaborator / support member / lead OF ITS QUEUE (named, or through the linked department);
--                        anyone with tickets.view_all; a super-admin;
--                        a member of its organization when the organization lets its members see each other's.
--   notes, time, SLA internals, automation, other requesters' details: a queue holder or view_all — never a requester-only caller.
--   an external by the secure link is NOT a caller here: PHP serves them from base tables with hd_secure_link_ticket().
--   an agent is a member: the same rule, no special case.
BEGIN;

-- The organizations whose tickets the caller may see as a fellow member (the org allows it, or the business default).
CREATE OR REPLACE FUNCTION hd_visible_org_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT o.id FROM organizations o
          JOIN requesters r ON r.organization_id = o.id AND r.member_id = app_current_member_id()
          JOIN app_settings s ON s.id = 1
         WHERE o.active AND COALESCE(o.members_see_each_other, s.org_visibility_default);
END$$;

-- The tickets the caller is personally on: asked for, filed, cc'd or following.
CREATE OR REPLACE FUNCTION hd_own_ticket_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT app_is_active_member() THEN
        RETURN;
    END IF;
    RETURN QUERY
        SELECT t.id FROM tickets t WHERE t.requester_id = hd_my_requester_id()
        UNION SELECT t.id FROM tickets t WHERE t.submitter_member_id = app_current_member_id()
        UNION SELECT f.ticket_id FROM ticket_followers f WHERE f.member_id = app_current_member_id()
        UNION SELECT t.id FROM tickets t WHERE t.organization_id IN (SELECT hd_visible_org_ids());
END$$;

-- One ticket, for PHP's gate (require_can_see_ticket) and the partial-update prefill.
CREATE OR REPLACE FUNCTION hd_can_see_ticket(p_ticket_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE t tickets%ROWTYPE;
BEGIN
    IF NOT app_is_active_member() THEN
        RETURN false;
    END IF;
    SELECT * INTO t FROM tickets WHERE id = p_ticket_id;
    IF t.id IS NULL THEN
        RETURN false;
    END IF;
    RETURN hd_has_right('tickets.view_all')
        OR t.queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue'))
        OR p_ticket_id IN (SELECT hd_own_ticket_ids());
END$$;

-- May the caller WORK this ticket (reply, transition, assign, fields): tickets.work in its queue, or tickets.manage.
CREATE OR REPLACE FUNCTION hd_can_work_ticket(p_ticket_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q bigint;
BEGIN
    SELECT queue_id INTO q FROM tickets WHERE id = p_ticket_id;
    RETURN q IS NOT NULL AND (hd_has_right('tickets.manage') OR q IN (SELECT hd_queue_ids_with_right('tickets.work')));
END$$;

-- Sees the queue's internals of this ticket (notes, time, SLA, rules): a holder of the queue with view_queue, or view_all.
CREATE OR REPLACE FUNCTION hd_can_see_internals(p_ticket_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q bigint;
BEGIN
    SELECT queue_id INTO q FROM tickets WHERE id = p_ticket_id;
    RETURN q IS NOT NULL AND (hd_has_right('tickets.view_all') OR q IN (SELECT hd_queue_ids_with_right('tickets.view_queue')));
END$$;

-- The SLA risk state of one target, for the views and the cards: on_track | at_risk | breached | met | paused | n/a.
CREATE OR REPLACE FUNCTION hd_sla_state(p_due timestamptz, p_started timestamptz, p_paused timestamptz, p_met timestamptz, p_breached timestamptz, p_warning integer)
    RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE
        WHEN p_met IS NOT NULL THEN 'met'
        WHEN p_due IS NULL THEN 'n/a'
        WHEN p_breached IS NOT NULL OR now() > p_due THEN 'breached'
        WHEN p_paused IS NOT NULL THEN 'paused'
        WHEN EXTRACT(EPOCH FROM (now() - p_started)) >= EXTRACT(EPOCH FROM (p_due - p_started)) * COALESCE(p_warning, 80) / 100.0 THEN 'at_risk'
        ELSE 'on_track' END;
$$;

GRANT EXECUTE ON FUNCTION hd_visible_org_ids(), hd_own_ticket_ids(), hd_can_see_ticket(bigint), hd_can_work_ticket(bigint),
    hd_can_see_internals(bigint), hd_sla_state(timestamptz, timestamptz, timestamptz, timestamptz, timestamptz, integer)
    TO helpdesk_rw, helpdesk_records_ro, helpdesk_activity_ro;

COMMIT;
