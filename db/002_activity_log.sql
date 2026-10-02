-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "helpdesk" first). A TICKET'S HISTORY IS THIS TABLE (D7): there is no ticket-events
-- table; `before`/`after` carry what the timeline shows (status from/to with the resolution code, assignee old/new,
-- priority old/new, the rule that fired). A message body is never here (a 200-character excerpt at most); a secure
-- token never.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    actor_requester_id bigint,                     -- an external requester acting through the secure link or the form (no FK: db/006 comes after)
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,
    queue_id        bigint,                        -- the queue the event happened in (no FK — history outlives a queue)
    ticket_id       bigint,                        -- denormalised for every ticket-scoped event (a message, a rating, a dispatch…)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX activity_log_actor_idx   ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx  ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx  ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx    ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx ON activity_log (request_id);
CREATE INDEX activity_log_queue_idx   ON activity_log (queue_id, occurred_at DESC);
CREATE INDEX activity_log_ticket_idx  ON activity_log (ticket_id, occurred_at DESC);

GRANT INSERT, SELECT ON activity_log TO helpdesk_rw;
REVOKE ALL ON activity_log FROM helpdesk_records_ro, helpdesk_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO helpdesk_rw;

COMMIT;
