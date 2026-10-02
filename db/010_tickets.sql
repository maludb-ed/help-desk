-- 010: THE TICKET and its conversation (the Phase 0 plan §2.2–2.4; design §7). One request: who asked (a requester of
-- any kind), who filed it, which queue holds it, who holds it (a person OR an agent), its status (a row of
-- ticket_statuses; moves only through status_transitions), priority, type, category, channel, tags, custom values,
-- the dates the SLA and the reports need. Numbering: `HD-1001` onward from one sequence that never reuses a number
-- (D9). Its history is the activity log (D7). Its SLA targets are rows here (sla_targets), the clock paused by a
-- status whose clock_runs is false. Every ticket mints a SECURE LINK (D1): the external requester's door.
BEGIN;

CREATE SEQUENCE ticket_number_seq START 1001;

CREATE TABLE tickets (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number                 text NOT NULL UNIQUE,                 -- HD-1042, set by trigger
    subject                text NOT NULL CHECK (length(subject) BETWEEN 1 AND 300),
    requester_id           bigint NOT NULL REFERENCES requesters(id),
    submitter_member_id    bigint REFERENCES members(id) ON DELETE SET NULL,     -- who filed it when not the requester (an agent on behalf, support staff)
    organization_id        bigint REFERENCES organizations(id) ON DELETE SET NULL,
    queue_id               bigint NOT NULL REFERENCES queues(id),
    assignee_member_id     bigint REFERENCES members(id) ON DELETE SET NULL,     -- a person or an agent
    status_id              bigint NOT NULL REFERENCES ticket_statuses(id),
    priority               text NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    type_id                bigint REFERENCES ticket_types(id) ON DELETE SET NULL,
    category_id            bigint REFERENCES categories(id) ON DELETE SET NULL,
    channel                text NOT NULL DEFAULT 'web' CHECK (channel IN ('web', 'email', 'form', 'agent', 'api', 'phone', 'chat', 'assistant')),
    custom_values          jsonb NOT NULL DEFAULT '{}'::jsonb,
    due_at                 timestamptz,                          -- a manual due date
    pending_until          timestamptz,                          -- a snooze: when it passes, pending → open (the worker)
    hold_reason            text CHECK (hold_reason IN ('awaiting_requester', 'awaiting_third_party', 'awaiting_internal', 'awaiting_problem')),
    resolution_code        text CHECK (resolution_code IN ('resolved', 'workaround', 'no_action', 'duplicate', 'spam', 'cannot_reproduce')),
    parent_id              bigint REFERENCES tickets(id) ON DELETE SET NULL,     -- a problem's incident
    merged_into_id         bigint REFERENCES tickets(id) ON DELETE SET NULL,
    external_id            text,                                 -- a monitoring agent's own key (idempotent create)
    board_position         integer,                              -- manual rank within a board column
    reopen_count           integer NOT NULL DEFAULT 0,
    sla_policy_id          bigint REFERENCES sla_policies(id) ON DELETE SET NULL,
    first_response_at      timestamptz,
    last_agent_reply_at    timestamptz,                          -- the last PUBLIC reply by support (a person or an agent)
    last_requester_reply_at timestamptz,
    last_activity_at       timestamptz NOT NULL DEFAULT now(),
    solved_at              timestamptz,
    closed_at              timestamptz,
    created_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX tickets_queue_status_idx ON tickets (queue_id, status_id, priority);
CREATE INDEX tickets_assignee_idx ON tickets (assignee_member_id) WHERE closed_at IS NULL;
CREATE INDEX tickets_requester_idx ON tickets (requester_id);
CREATE INDEX tickets_org_idx ON tickets (organization_id);
CREATE INDEX tickets_activity_idx ON tickets (last_activity_at DESC);
CREATE INDEX tickets_pending_idx ON tickets (pending_until) WHERE pending_until IS NOT NULL;
CREATE INDEX tickets_subject_trgm_idx ON tickets USING gin (subject gin_trgm_ops);
CREATE UNIQUE INDEX tickets_external_id_idx ON tickets (external_id) WHERE external_id IS NOT NULL;
CREATE INDEX tickets_parent_idx ON tickets (parent_id) WHERE parent_id IS NOT NULL;

CREATE TABLE ticket_tags (
    ticket_id  bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    tag_id     bigint NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (ticket_id, tag_id)
);
CREATE INDEX ticket_tags_tag_idx ON ticket_tags (tag_id);

-- cc = a requester-side person who receives public replies; follower = an insider told of changes.
CREATE TABLE ticket_followers (
    ticket_id     bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    kind          text NOT NULL CHECK (kind IN ('cc', 'follower')),
    member_id     bigint REFERENCES members(id) ON DELETE CASCADE,       -- a follower, or a cc who is a member
    requester_id  bigint REFERENCES requesters(id) ON DELETE CASCADE,    -- an external cc
    added_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((member_id IS NULL) <> (requester_id IS NULL)),
    CHECK (kind = 'cc' OR member_id IS NOT NULL)
);
CREATE UNIQUE INDEX ticket_followers_member_idx ON ticket_followers (ticket_id, member_id) WHERE member_id IS NOT NULL;
CREATE UNIQUE INDEX ticket_followers_requester_idx ON ticket_followers (ticket_id, requester_id) WHERE requester_id IS NOT NULL;
CREATE INDEX ticket_followers_by_member_idx ON ticket_followers (member_id);

CREATE TABLE ticket_links (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    from_ticket_id bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    to_ticket_id   bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    kind           text NOT NULL CHECK (kind IN ('parent_child', 'related', 'duplicate_of', 'merged_into', 'blocks', 'follow_up_of')),
    created_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (from_ticket_id, to_ticket_id, kind),
    CHECK (from_ticket_id <> to_ticket_id)
);
CREATE INDEX ticket_links_to_idx ON ticket_links (to_ticket_id);

-- Who has the ticket open right now (collision warnings). Rows expire; nothing historical lives here.
CREATE TABLE ticket_viewers (
    ticket_id   bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    composing   boolean NOT NULL DEFAULT false,
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (ticket_id, member_id)
);

-- One entry in the conversation. A public reply is mailed to the requester and the CCs; an internal note never
-- leaves the queue. Author: a member (support staff, an agent, a member requester) OR a requester (an external, by
-- the secure link or by mail). `body_html` is sanitised on the way in; `body_text` is what tools and mail quote.
CREATE TABLE messages (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id       bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    kind            text NOT NULL CHECK (kind IN ('public_reply', 'internal_note')),
    author_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    author_requester_id bigint REFERENCES requesters(id) ON DELETE SET NULL,
    body_text       text NOT NULL,
    body_html       text,
    channel         text NOT NULL DEFAULT 'web' CHECK (channel IN ('web', 'email', 'form', 'agent', 'api', 'portal', 'assistant')),
    email_message_id text,                                      -- Message-ID when it came or went by mail
    in_reply_to     text,
    mentions        bigint[] NOT NULL DEFAULT '{}',              -- member ids named with @ in a note
    edited_at       timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (author_member_id IS NOT NULL OR author_requester_id IS NOT NULL)
);
CREATE INDEX messages_ticket_idx ON messages (ticket_id, created_at);
CREATE INDEX messages_email_id_idx ON messages (email_message_id) WHERE email_message_id IS NOT NULL;

CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id     bigint REFERENCES tickets(id) ON DELETE CASCADE,
    message_id    bigint REFERENCES messages(id) ON DELETE CASCADE,
    article_id    bigint,                                       -- FK added in db/012
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,                                -- under storage/attachments/, served through the gate
    uploaded_by_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    uploaded_by_requester_id bigint REFERENCES requesters(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_ticket_idx ON attachments (ticket_id);
CREATE INDEX attachments_message_idx ON attachments (message_id);

-- The external requester's door (D1): /t/<48 hex>. Only the hash is stored; rotating makes a new row and dates the
-- old; the link dies secure_link_days after the ticket closes (the worker sets expires_at at close).
CREATE TABLE ticket_links_secure (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id     bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    token_hash    text NOT NULL UNIQUE,                         -- sha256(raw)
    expires_at    timestamptz,
    rotated_at    timestamptz,
    last_used_at  timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ticket_links_secure_ticket_idx ON ticket_links_secure (ticket_id) WHERE rotated_at IS NULL;

-- One row per metric per ticket. The clock: `started_at` → `due_at`, paused while the status does not run it
-- (paused_at set; on resume due_at moves by the pause). met_at when the metric was satisfied; breached_at when due_at
-- passed unmet. next_response restarts at each requester reply.
CREATE TABLE sla_targets (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id     bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    metric        text NOT NULL CHECK (metric IN ('first_response', 'next_response', 'resolution')),
    policy_id     bigint REFERENCES sla_policies(id) ON DELETE SET NULL,
    target_minutes integer NOT NULL,
    started_at    timestamptz NOT NULL DEFAULT now(),
    due_at        timestamptz,
    paused_at     timestamptz,
    paused_total  interval NOT NULL DEFAULT '0',
    met_at        timestamptz,
    breached_at   timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX sla_targets_open_metric ON sla_targets (ticket_id, metric) WHERE met_at IS NULL;
CREATE INDEX sla_targets_due_idx ON sla_targets (due_at) WHERE met_at IS NULL AND breached_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- The policy that applies: the organization's override, else the category's, else the first active policy whose
-- conditions match (sort_order), else the queue's default, else NULL.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION hd_match_sla_policy(p_ticket tickets) RETURNS bigint
    LANGUAGE plpgsql STABLE AS $$
DECLARE v bigint; p record; v_tags text[];
BEGIN
    SELECT sla_policy_id INTO v FROM organizations WHERE id = p_ticket.organization_id AND sla_policy_id IS NOT NULL;
    IF v IS NOT NULL THEN RETURN v; END IF;
    SELECT sla_policy_id INTO v FROM categories WHERE id = p_ticket.category_id AND sla_policy_id IS NOT NULL;
    IF v IS NOT NULL THEN RETURN v; END IF;
    SELECT COALESCE(array_agg(t.slug), '{}') INTO v_tags FROM ticket_tags tt JOIN tags t ON t.id = tt.tag_id WHERE tt.ticket_id = p_ticket.id;
    FOR p IN SELECT id, conditions FROM sla_policies WHERE active ORDER BY sort_order, id LOOP
        IF (NOT (p.conditions ? 'organization_ids') OR jsonb_array_length(p.conditions->'organization_ids') = 0
                OR p.conditions->'organization_ids' @> to_jsonb(p_ticket.organization_id))
           AND (NOT (p.conditions ? 'queue_ids') OR jsonb_array_length(p.conditions->'queue_ids') = 0
                OR p.conditions->'queue_ids' @> to_jsonb(p_ticket.queue_id))
           AND (NOT (p.conditions ? 'type_ids') OR jsonb_array_length(p.conditions->'type_ids') = 0
                OR p.conditions->'type_ids' @> to_jsonb(p_ticket.type_id))
           AND (NOT (p.conditions ? 'priorities') OR jsonb_array_length(p.conditions->'priorities') = 0
                OR p.conditions->'priorities' @> to_jsonb(p_ticket.priority))
           AND (NOT (p.conditions ? 'tags') OR jsonb_array_length(p.conditions->'tags') = 0
                OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(p.conditions->'tags') x WHERE x = ANY (v_tags)))
        THEN
            RETURN p.id;
        END IF;
    END LOOP;
    SELECT sla_policy_id INTO v FROM queues WHERE id = p_ticket.queue_id;
    RETURN v;
END$$;

-- Start (or restart) one metric's target from the policy; nothing when the policy has no target for the priority.
CREATE OR REPLACE FUNCTION hd_sla_start(p_ticket_id bigint, p_metric text, p_from timestamptz DEFAULT now()) RETURNS void
    LANGUAGE plpgsql AS $$
DECLARE t tickets%ROWTYPE; pol sla_policies%ROWTYPE; mins integer; st ticket_statuses%ROWTYPE;
BEGIN
    SELECT * INTO t FROM tickets WHERE id = p_ticket_id;
    IF t.sla_policy_id IS NULL THEN RETURN; END IF;
    SELECT * INTO pol FROM sla_policies WHERE id = t.sla_policy_id AND active;
    IF pol.id IS NULL THEN RETURN; END IF;
    mins := (pol.targets -> t.priority ->> p_metric)::integer;
    IF mins IS NULL THEN RETURN; END IF;
    SELECT * INTO st FROM ticket_statuses WHERE id = t.status_id;
    DELETE FROM sla_targets WHERE ticket_id = p_ticket_id AND metric = p_metric AND met_at IS NULL;
    INSERT INTO sla_targets (ticket_id, metric, policy_id, target_minutes, started_at, due_at, paused_at)
    VALUES (p_ticket_id, p_metric, pol.id, mins, p_from, hd_business_add_minutes(p_from, mins, pol.business_hours_id),
            CASE WHEN st.clock_runs THEN NULL ELSE p_from END);
END$$;

CREATE OR REPLACE FUNCTION hd_sla_meet(p_ticket_id bigint, p_metric text, p_at timestamptz DEFAULT now()) RETURNS void
    LANGUAGE plpgsql AS $$
BEGIN
    UPDATE sla_targets SET met_at = p_at, breached_at = CASE WHEN due_at IS NOT NULL AND p_at > due_at THEN COALESCE(breached_at, due_at) ELSE breached_at END
     WHERE ticket_id = p_ticket_id AND metric = p_metric AND met_at IS NULL;
END$$;

-- Pause or resume every open target of a ticket (a status whose clock does not run, then one that does).
CREATE OR REPLACE FUNCTION hd_sla_set_running(p_ticket_id bigint, p_running boolean) RETURNS void
    LANGUAGE plpgsql AS $$
BEGIN
    IF p_running THEN
        UPDATE sla_targets
           SET due_at = CASE WHEN due_at IS NULL THEN NULL ELSE due_at + (now() - paused_at) END,
               paused_total = paused_total + (now() - paused_at), paused_at = NULL
         WHERE ticket_id = p_ticket_id AND met_at IS NULL AND paused_at IS NOT NULL;
    ELSE
        UPDATE sla_targets SET paused_at = now() WHERE ticket_id = p_ticket_id AND met_at IS NULL AND paused_at IS NULL;
    END IF;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Triggers on the ticket: the number, the organization from the requester, the policy, the status dates, the clock,
-- the secure link. Writers (PHP, the worker) change status through UPDATE; the table keeps the dates right.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION hd_ticket_before_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pfx text;
BEGIN
    SELECT ticket_prefix INTO pfx FROM app_settings WHERE id = 1;
    NEW.number := COALESCE(pfx, 'HD') || '-' || nextval('ticket_number_seq');
    IF NEW.organization_id IS NULL THEN
        SELECT organization_id INTO NEW.organization_id FROM requesters WHERE id = NEW.requester_id;
    END IF;
    IF NEW.status_id IS NULL THEN
        SELECT id INTO NEW.status_id FROM ticket_statuses WHERE is_default;
    END IF;
    IF NEW.queue_id IS NULL THEN
        SELECT default_queue_id INTO NEW.queue_id FROM app_settings WHERE id = 1;
    END IF;
    NEW.sla_policy_id := COALESCE(NEW.sla_policy_id, hd_match_sla_policy(NEW));
    NEW.last_activity_at := COALESCE(NEW.last_activity_at, now());
    RETURN NEW;
END$$;
CREATE TRIGGER tickets_before_insert BEFORE INSERT ON tickets FOR EACH ROW EXECUTE FUNCTION hd_ticket_before_insert();

CREATE OR REPLACE FUNCTION hd_ticket_after_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM hd_sla_start(NEW.id, 'first_response', NEW.created_at);
    PERFORM hd_sla_start(NEW.id, 'resolution', NEW.created_at);
    RETURN NEW;
END$$;
CREATE TRIGGER tickets_after_insert AFTER INSERT ON tickets FOR EACH ROW EXECUTE FUNCTION hd_ticket_after_insert();

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
CREATE TRIGGER tickets_before_update BEFORE UPDATE ON tickets FOR EACH ROW EXECUTE FUNCTION hd_ticket_before_update();

CREATE OR REPLACE FUNCTION hd_ticket_after_update() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o_runs boolean; n_runs boolean; nc text; oc text;
BEGIN
    IF NEW.status_id IS DISTINCT FROM OLD.status_id THEN
        SELECT clock_runs, category INTO o_runs, oc FROM ticket_statuses WHERE id = OLD.status_id;
        SELECT clock_runs, category INTO n_runs, nc FROM ticket_statuses WHERE id = NEW.status_id;
        IF nc IN ('solved', 'closed') AND oc NOT IN ('solved', 'closed') THEN
            -- a solved ticket has no running clock: resolution is met, and a response owed is owed no more
            PERFORM hd_sla_meet(NEW.id, 'resolution', now());
            PERFORM hd_sla_meet(NEW.id, 'next_response', now());
            PERFORM hd_sla_meet(NEW.id, 'first_response', now());
        ELSIF oc IN ('solved', 'closed') AND nc NOT IN ('solved', 'closed') THEN
            PERFORM hd_sla_start(NEW.id, 'resolution', now());
        END IF;
        IF o_runs AND NOT n_runs THEN
            PERFORM hd_sla_set_running(NEW.id, false);
        ELSIF NOT o_runs AND n_runs THEN
            PERFORM hd_sla_set_running(NEW.id, true);
        END IF;
        IF nc = 'closed' AND oc <> 'closed' THEN
            UPDATE ticket_links_secure SET expires_at = now() + make_interval(days => (SELECT secure_link_days FROM app_settings WHERE id = 1))
             WHERE ticket_id = NEW.id AND rotated_at IS NULL;
        ELSIF oc = 'closed' AND nc <> 'closed' THEN
            UPDATE ticket_links_secure SET expires_at = NULL WHERE ticket_id = NEW.id AND rotated_at IS NULL;
        END IF;
    END IF;
    IF (NEW.priority IS DISTINCT FROM OLD.priority OR NEW.sla_policy_id IS DISTINCT FROM OLD.sla_policy_id) THEN
        -- re-aim the open targets from their own start; what was met stays met
        PERFORM hd_sla_start(NEW.id, m, (SELECT started_at FROM sla_targets WHERE ticket_id = NEW.id AND metric = m AND met_at IS NULL))
          FROM (SELECT DISTINCT metric AS m FROM sla_targets WHERE ticket_id = NEW.id AND met_at IS NULL) x;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER tickets_after_update AFTER UPDATE ON tickets FOR EACH ROW EXECUTE FUNCTION hd_ticket_after_update();

-- A message moves the ticket's dates and the clocks: support's public reply meets first/next response; a requester's
-- reply starts next_response and pulls a pending or solved (within the window) ticket back to open.
CREATE OR REPLACE FUNCTION hd_message_after_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t tickets%ROWTYPE; cat text; is_requester boolean; open_id bigint; win integer;
BEGIN
    SELECT * INTO t FROM tickets WHERE id = NEW.ticket_id;
    SELECT category INTO cat FROM ticket_statuses WHERE id = t.status_id;
    -- the requester side: the requester themself (by requester row or by member id) or a cc. Every clause is a plain
    -- boolean: an external requester has no member id, and a NULL here once made a support reply count for nothing.
    is_requester := COALESCE(NEW.author_requester_id = t.requester_id, false)
                 OR COALESCE(NEW.author_member_id = (SELECT q.member_id FROM requesters q WHERE q.id = t.requester_id), false)
                 OR EXISTS (SELECT 1 FROM ticket_followers f WHERE f.ticket_id = t.id AND f.kind = 'cc'
                              AND (f.member_id IS NOT DISTINCT FROM NEW.author_member_id AND NEW.author_member_id IS NOT NULL
                                   OR f.requester_id IS NOT DISTINCT FROM NEW.author_requester_id AND NEW.author_requester_id IS NOT NULL));
    IF NEW.kind = 'public_reply' AND NOT is_requester AND NEW.author_member_id IS NOT NULL THEN
        UPDATE tickets SET first_response_at = COALESCE(first_response_at, NEW.created_at), last_agent_reply_at = NEW.created_at,
                           last_activity_at = NEW.created_at WHERE id = t.id;
        PERFORM hd_sla_meet(t.id, 'first_response', NEW.created_at);
        PERFORM hd_sla_meet(t.id, 'next_response', NEW.created_at);
    ELSIF NEW.kind = 'public_reply' AND is_requester THEN
        UPDATE tickets SET last_requester_reply_at = NEW.created_at, last_activity_at = NEW.created_at WHERE id = t.id;
        PERFORM hd_sla_start(t.id, 'next_response', NEW.created_at);
        SELECT reopen_window_days INTO win FROM app_settings WHERE id = 1;
        IF cat = 'pending' OR (cat = 'solved' AND t.solved_at >= now() - make_interval(days => win)) THEN
            SELECT id INTO open_id FROM ticket_statuses WHERE key = 'open';
            UPDATE tickets SET status_id = open_id WHERE id = t.id;
        END IF;
    ELSE
        UPDATE tickets SET last_activity_at = NEW.created_at WHERE id = t.id;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER messages_after_insert AFTER INSERT ON messages FOR EACH ROW EXECUTE FUNCTION hd_message_after_insert();

-- Tag usage counts follow the join table.
CREATE OR REPLACE FUNCTION hd_ticket_tags_count() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        UPDATE tags SET usage_count = usage_count + 1 WHERE id = NEW.tag_id;
    ELSE
        UPDATE tags SET usage_count = GREATEST(usage_count - 1, 0) WHERE id = OLD.tag_id;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER ticket_tags_count AFTER INSERT OR DELETE ON ticket_tags FOR EACH ROW EXECUTE FUNCTION hd_ticket_tags_count();

-- Mint a secure link (the raw token is returned ONCE to the writer, who mails it; only its hash is kept).
CREATE OR REPLACE FUNCTION hd_secure_link_mint(p_ticket_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE raw text;
BEGIN
    raw := encode(gen_random_bytes(24), 'hex');
    UPDATE ticket_links_secure SET rotated_at = now() WHERE ticket_id = p_ticket_id AND rotated_at IS NULL;
    INSERT INTO ticket_links_secure (ticket_id, token_hash) VALUES (p_ticket_id, encode(sha256(raw::bytea), 'hex'));
    RETURN raw;
END$$;

-- The ticket a presented secure token opens: live, not rotated, not expired; stamps last_used_at. NULL otherwise —
-- the same nothing for every reason.
CREATE OR REPLACE FUNCTION hd_secure_link_ticket(p_raw text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE tid bigint;
BEGIN
    IF p_raw !~ '^[a-f0-9]{48}$' THEN RETURN NULL; END IF;
    UPDATE ticket_links_secure SET last_used_at = now()
     WHERE token_hash = encode(sha256(p_raw::bytea), 'hex') AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now())
    RETURNING ticket_id INTO tid;
    RETURN tid;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON tickets, ticket_tags, ticket_followers, ticket_links, ticket_viewers, messages, attachments,
    ticket_links_secure, sla_targets TO helpdesk_rw;
GRANT USAGE, SELECT ON SEQUENCE ticket_number_seq TO helpdesk_rw;
-- The writer's alone — a function is PUBLIC-executable by default, and the secure link's opener must not be.
REVOKE ALL ON FUNCTION hd_match_sla_policy(tickets), hd_sla_start(bigint, text, timestamptz), hd_sla_meet(bigint, text, timestamptz),
    hd_sla_set_running(bigint, boolean), hd_secure_link_mint(bigint), hd_secure_link_ticket(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION hd_match_sla_policy(tickets), hd_sla_start(bigint, text, timestamptz), hd_sla_meet(bigint, text, timestamptz),
    hd_sla_set_running(bigint, boolean), hd_secure_link_mint(bigint), hd_secure_link_ticket(text) TO helpdesk_rw;

COMMIT;
