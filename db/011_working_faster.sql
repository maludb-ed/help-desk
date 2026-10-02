-- 011: WORKING FASTER (the Phase 0 plan §2.5): canned responses with placeholders and side-effects, saved views (a
-- board IS a saved view with layout = board), automation rules and their runs, time entries.
BEGIN;

CREATE TABLE canned_responses (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title         text NOT NULL,
    folder        text,
    body_text     text NOT NULL,                                 -- placeholders: {{ticket.number}} {{requester.first_name}} {{agent.signature}} …
    body_html     text,
    scope         text NOT NULL DEFAULT 'personal' CHECK (scope IN ('personal', 'queue', 'global')),
    owner_member_id bigint REFERENCES members(id) ON DELETE CASCADE,  -- personal
    queue_id      bigint REFERENCES queues(id) ON DELETE CASCADE,     -- queue
    side_effects  jsonb NOT NULL DEFAULT '{}'::jsonb,            -- {"status": "pending", "priority": "high", "assign": "me", "add_tags": ["billing"]}
    usage_count   integer NOT NULL DEFAULT 0,
    archived_at   timestamptz,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((scope = 'personal') = (owner_member_id IS NOT NULL)),
    CHECK ((scope = 'queue') = (queue_id IS NOT NULL))
);
CREATE INDEX canned_responses_owner_idx ON canned_responses (owner_member_id);
CREATE INDEX canned_responses_title_trgm_idx ON canned_responses USING gin (title gin_trgm_ops);
CREATE TRIGGER canned_responses_touch BEFORE UPDATE ON canned_responses FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A saved view: a remembered filter, a sort, columns, and a layout. The standard queues (My tickets, Needs my reply,
-- Unassigned, Triage, At risk, Stale) are seeded as global views whose filters name the caller ("me").
CREATE TABLE saved_views (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           text NOT NULL,
    scope          text NOT NULL DEFAULT 'personal' CHECK (scope IN ('personal', 'queue', 'global')),
    owner_member_id bigint REFERENCES members(id) ON DELETE CASCADE,
    queue_id       bigint REFERENCES queues(id) ON DELETE CASCADE,
    -- a bounded vocabulary: {"status_category": ["open"], "assignee": "me"|"none"|<id>, "queue_id": [..], "priority": [..],
    --  "needs_reply": true, "sla": "at_risk"|"breached", "stale_days": 7, "requester": "me", "tags": [..], "type_id": [..], "text": "…"}
    filters        jsonb NOT NULL DEFAULT '{}'::jsonb,
    sort_by        text NOT NULL DEFAULT 'last_activity_at',
    sort_dir       text NOT NULL DEFAULT 'desc' CHECK (sort_dir IN ('asc', 'desc')),
    columns        text[] NOT NULL DEFAULT '{number,subject,requester,status,priority,assignee,updated}',
    layout         text NOT NULL DEFAULT 'list' CHECK (layout IN ('list', 'board')),
    -- board settings: {"column_field": "status", "swimlane": null, "wip": {"<status_id>": 10}, "solved_days": 3}
    board          jsonb NOT NULL DEFAULT '{}'::jsonb,
    is_system      boolean NOT NULL DEFAULT false,
    system_key     text UNIQUE,
    sort_order     integer NOT NULL DEFAULT 100,
    archived_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK ((scope = 'personal') = (owner_member_id IS NOT NULL)),
    CHECK ((scope = 'queue') = (queue_id IS NOT NULL))
);
CREATE TRIGGER saved_views_touch BEFORE UPDATE ON saved_views FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

INSERT INTO saved_views (name, scope, filters, is_system, system_key, sort_order) VALUES
 ('My tickets',       'global', '{"assignee": "me", "status_category": ["new", "open", "pending", "on_hold"]}', true, 'mine', 10),
 ('Needs my reply',   'global', '{"assignee": "me", "needs_reply": true}', true, 'needs_reply', 20),
 ('Unassigned',       'global', '{"assignee": "none", "status_category": ["new", "open"]}', true, 'unassigned', 30),
 ('Triage',           'global', '{"status_category": ["new"]}', true, 'triage', 40),
 ('At risk',          'global', '{"sla": "at_risk", "status_category": ["new", "open", "pending", "on_hold"]}', true, 'at_risk', 50),
 ('Stale',            'global', '{"stale_days": 7, "status_category": ["open", "pending", "on_hold"]}', true, 'stale', 60),
 ('My requests',      'global', '{"requester": "me"}', true, 'my_requests', 70),
 ('Team board',       'global', '{"status_category": ["new", "open", "pending", "on_hold", "solved"]}', true, 'team_board', 80);
UPDATE saved_views SET layout = 'board', board = '{"column_field": "status", "swimlane": null, "wip": {}, "solved_days": 3}' WHERE system_key = 'team_board';

-- Automation: a trigger (on create / update), a time-based rule (the worker, every minute), or an assignment rule.
-- Conditions and actions are JSON over a bounded vocabulary the Phase 1 spec fixes; the worker and the handlers
-- share one evaluator in PHP. run_count and last_run_at say which rules earn their keep.
CREATE TABLE automation_rules (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name          text NOT NULL,
    kind          text NOT NULL CHECK (kind IN ('trigger', 'time_based', 'assignment')),
    event         text CHECK (event IN ('ticket.create', 'ticket.update', 'ticket.reply', 'ticket.note')),   -- triggers
    -- conditions: {"all": [{"field": "priority", "op": "eq", "value": "urgent"}, {"field": "hours_since", "args": "solved_at", "op": "gte", "value": 96}]}
    conditions    jsonb NOT NULL DEFAULT '{}'::jsonb,
    -- actions: [{"set": {"priority": "urgent"}}, {"assign": {"queue_id": 3}}, {"notify": {"who": "queue_leads", "kind": "sla_warning"}}, {"transition": "closed"}]
    actions       jsonb NOT NULL DEFAULT '[]'::jsonb,
    sort_order    integer NOT NULL DEFAULT 100,
    active        boolean NOT NULL DEFAULT true,
    run_count     bigint NOT NULL DEFAULT 0,
    last_run_at   timestamptz,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER automation_rules_touch BEFORE UPDATE ON automation_rules FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Seed: the two housekeeping rules every desk has (time-based; the worker runs them).
INSERT INTO automation_rules (name, kind, conditions, actions, sort_order) VALUES
 ('Close solved tickets after the window', 'time_based',
  '{"all": [{"field": "status_category", "op": "eq", "value": "solved"}, {"field": "days_since", "args": "solved_at", "op": "gte", "value": "setting:auto_close_days"}]}',
  '[{"transition": "closed"}]', 10),
 ('Reopen when the snooze passes', 'time_based',
  '{"all": [{"field": "status_category", "op": "eq", "value": "pending"}, {"field": "pending_until_passed", "op": "eq", "value": true}]}',
  '[{"transition": "open"}, {"notify": {"who": "assignee", "kind": "pending_elapsed"}}]', 20);

CREATE TABLE rule_runs (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    rule_id     bigint NOT NULL REFERENCES automation_rules(id) ON DELETE CASCADE,
    ticket_id   bigint REFERENCES tickets(id) ON DELETE CASCADE,
    outcome     text NOT NULL CHECK (outcome IN ('applied', 'skipped', 'error')),
    detail      jsonb,
    ran_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX rule_runs_rule_idx ON rule_runs (rule_id, ran_at DESC);
CREATE INDEX rule_runs_ticket_idx ON rule_runs (ticket_id);

CREATE TABLE time_entries (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id   bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    minutes     integer NOT NULL CHECK (minutes BETWEEN 1 AND 1440),
    billable    boolean NOT NULL DEFAULT false,
    note        text,
    worked_on   date NOT NULL DEFAULT current_date,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX time_entries_ticket_idx ON time_entries (ticket_id);
CREATE INDEX time_entries_member_idx ON time_entries (member_id, worked_on);

GRANT SELECT, INSERT, UPDATE, DELETE ON canned_responses, saved_views, automation_rules, rule_runs, time_entries TO helpdesk_rw;

COMMIT;
