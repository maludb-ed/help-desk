-- 008: QUEUES — the Phase 0 plan's "teams", renamed so "team" stays the kernel's word (design §3, D3). A queue holds
-- tickets and the people who work them. It may be LINKED to a kernel department: the department's live members who
-- hold a Help Desk role are in the queue without being named. Membership is otherwise explicit (queue_members, with
-- a lead flag). A queue's assignment method, schedule, default policy, default assignee (a person or an AGENT) and
-- mailbox route what lands in it. A right that says "in their queues" is: hd_has_right(right) AND the queue is held.
BEGIN;

CREATE TABLE queues (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key                  text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name                 text NOT NULL,
    description          text,
    department_id        bigint REFERENCES departments(id) ON DELETE SET NULL,
    include_department   boolean NOT NULL DEFAULT true,         -- the linked department's members are in the queue
    assignment_method    text NOT NULL DEFAULT 'manual' CHECK (assignment_method IN ('manual', 'round_robin', 'least_loaded', 'default_assignee')),
    default_assignee_member_id bigint REFERENCES members(id) ON DELETE SET NULL,   -- a person or an agent
    business_hours_id    bigint REFERENCES business_hours(id) ON DELETE SET NULL,
    sla_policy_id        bigint REFERENCES sla_policies(id) ON DELETE SET NULL,
    email_address        citext,                                 -- the queue's own address (its mailbox is db/012)
    sort_order           integer NOT NULL DEFAULT 100,
    archived_at          timestamptz,
    created_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX queues_name_live ON queues (lower(name)) WHERE archived_at IS NULL;
CREATE TRIGGER queues_touch BEFORE UPDATE ON queues FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

ALTER TABLE app_settings ADD CONSTRAINT app_settings_default_queue_fk FOREIGN KEY (default_queue_id) REFERENCES queues(id) ON DELETE SET NULL;

CREATE TABLE queue_members (
    queue_id     bigint NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,   -- a person or an agent
    is_lead      boolean NOT NULL DEFAULT false,
    added_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (queue_id, member_id)
);
CREATE INDEX queue_members_member_idx ON queue_members (member_id);

-- Availability and capacity, for least-loaded assignment (UI deferred; the worker reads it).
CREATE TABLE queue_presence (
    member_id     bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    availability  text NOT NULL DEFAULT 'online' CHECK (availability IN ('online', 'away', 'offline')),
    capacity      integer CHECK (capacity IS NULL OR capacity BETWEEN 0 AND 500),   -- max open tickets; NULL = no limit
    updated_at    timestamptz NOT NULL DEFAULT now()
);

-- Seed: one general queue, the default. Linked to nothing: the installer's grants give every standing department
-- the Requester role; who WORKS the general queue is the admin's to name.
INSERT INTO queues (key, name, description, sort_order) VALUES ('general', 'General', 'Everything nobody else has claimed.', 1);
UPDATE app_settings SET default_queue_id = (SELECT id FROM queues WHERE key = 'general') WHERE id = 1;

-- The live queues the caller is in: named, or through a linked department. An uncorrelated set for the views.
CREATE OR REPLACE FUNCTION hd_held_queue_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT app_is_active_member() THEN
        RETURN;
    END IF;
    RETURN QUERY
        SELECT q.id FROM queues q WHERE q.archived_at IS NULL
           AND (EXISTS (SELECT 1 FROM queue_members qm WHERE qm.queue_id = q.id AND qm.member_id = app_current_member_id())
                OR (q.include_department AND q.department_id IN (SELECT app_member_department_ids())));
END$$;

-- The queues where the caller may exercise a right: every live queue with tickets.view_all (or a right that is not
-- queue-bound), else their own queues when their roles give the right. The views test this once per statement.
CREATE OR REPLACE FUNCTION hd_queue_ids_with_right(p_right text) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT hd_has_right(p_right) THEN
        RETURN;
    END IF;
    IF hd_has_right('tickets.view_all') THEN
        RETURN QUERY SELECT q.id FROM queues q WHERE q.archived_at IS NULL;
    ELSE
        RETURN QUERY SELECT * FROM hd_held_queue_ids();
    END IF;
END$$;

CREATE OR REPLACE FUNCTION hd_is_queue_lead(p_queue_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN hd_has_right('tickets.manage')
        OR EXISTS (SELECT 1 FROM queue_members qm WHERE qm.queue_id = p_queue_id AND qm.member_id = app_current_member_id() AND qm.is_lead);
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON queues, queue_members, queue_presence TO helpdesk_rw;
GRANT EXECUTE ON FUNCTION hd_held_queue_ids(), hd_queue_ids_with_right(text), hd_is_queue_lead(bigint)
    TO helpdesk_rw, helpdesk_records_ro, helpdesk_activity_ro;

COMMIT;
