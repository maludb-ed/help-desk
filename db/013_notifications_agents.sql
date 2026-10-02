-- 013: TALKING to people (the in-app bell, preferences, the outbox — email through MaluMail and texts through the
-- kernel's K6 as independent rows, txtSchedules' pattern) and DISPATCHING to agents (design §5: what was handed to
-- which agent, when, and what came back).
BEGIN;

CREATE TABLE notifications (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('assigned', 'replied', 'noted', 'mentioned', 'sla_warning', 'sla_breached', 'rated',
                                              'pending_elapsed', 'escalated', 'followed_changed', 'new_in_queue')),
    ticket_id   bigint REFERENCES tickets(id) ON DELETE CASCADE,
    title       text NOT NULL,
    body        text,
    read_at     timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,                -- K6: the kernel keeps the opt-out; this is the person's choice here
    kinds          text[] NOT NULL DEFAULT '{assigned,replied,mentioned,sla_warning,sla_breached,pending_elapsed,escalated}',
    text_kinds     text[] NOT NULL DEFAULT '{assigned,sla_breached,escalated}',
    digest         boolean NOT NULL DEFAULT false,
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per recipient AND channel; a K6 refusal is a SKIP with the code and the email still goes.
CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint REFERENCES members(id) ON DELETE CASCADE,       -- an insider
    requester_id   bigint REFERENCES requesters(id) ON DELETE CASCADE,    -- an external (email only)
    kind           text NOT NULL,
    ticket_id      bigint REFERENCES tickets(id) ON DELETE CASCADE,
    message_ref_id bigint REFERENCES messages(id) ON DELETE SET NULL,
    dedupe_key     text,                                                   -- one notice per event, person and channel
    subject        text,
    body           text NOT NULL,                                          -- never a note, a secret, another person's details
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    CHECK ((member_id IS NULL) <> (requester_id IS NULL)),
    CHECK (channel = 'email' OR member_id IS NOT NULL)
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';

-- What was handed to an agent (design §5): a duty's pick-up is the agent's own; a dispatch is the help desk calling
-- the kernel's chat endpoint for one turn (acting member = the person who assigned), or — K8, owed — the wake call.
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id        bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('assigned', 'replied', 'sla_warning', 'mentioned', 'escalation')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,                                         -- the kernel's run id, when it answered one
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,                                           -- at most 200 characters
    detail           text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz
);
CREATE INDEX agent_dispatches_ticket_idx ON agent_dispatches (ticket_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON notifications, notification_prefs, notification_outbox, agent_dispatches TO helpdesk_rw;

COMMIT;
