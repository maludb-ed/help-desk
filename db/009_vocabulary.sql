-- 009: the TICKET VOCABULARY (the Phase 0 plan §2.2): statuses within fixed categories, the allowed-transitions table
-- with what each move requires, types, hierarchical categories with routing defaults, tags, custom field definitions.
-- Admin-editable reference data, seeded. The SAME transitions table drives the UI, the board's drop rules and the
-- ticket_transitions tool — there is no free status write.
BEGIN;

CREATE TABLE ticket_statuses (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key          text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    label        text NOT NULL,
    category     text NOT NULL CHECK (category IN ('new', 'open', 'pending', 'on_hold', 'solved', 'closed')),
    color        text NOT NULL DEFAULT 'secondary' CHECK (color IN ('primary', 'secondary', 'success', 'info', 'warning', 'danger', 'dark')),
    sort_order   integer NOT NULL DEFAULT 100,                 -- = the board's column order
    is_default   boolean NOT NULL DEFAULT false,               -- where a new ticket starts
    on_board     boolean NOT NULL DEFAULT true,
    clock_runs   boolean NOT NULL DEFAULT true,                -- the SLA clock runs (true) or pauses (false) in it
    is_system    boolean NOT NULL DEFAULT false,               -- the six seeded ones: renameable, never deleted
    archived_at  timestamptz,
    CHECK (NOT is_default OR category = 'new')
);
CREATE UNIQUE INDEX ticket_statuses_one_default ON ticket_statuses (is_default) WHERE is_default;
-- Custom statuses live inside open / pending / on_hold / solved only (never new or closed): the trigger below.
CREATE OR REPLACE FUNCTION hd_status_category_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT NEW.is_system AND NEW.category IN ('new', 'closed') THEN
        RAISE EXCEPTION 'A custom status may be open, pending, on hold or solved — never new or closed.' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.is_system AND NEW.category <> OLD.category THEN
        RAISE EXCEPTION 'A built-in status keeps its category.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER ticket_statuses_guard BEFORE INSERT OR UPDATE ON ticket_statuses FOR EACH ROW EXECUTE FUNCTION hd_status_category_guard();

INSERT INTO ticket_statuses (key, label, category, color, sort_order, is_default, on_board, clock_runs, is_system) VALUES
 ('new',     'New',     'new',     'primary',   10, true,  true,  true,  true),
 ('open',    'Open',    'open',    'info',      20, false, true,  true,  true),
 ('pending', 'Pending', 'pending', 'warning',   30, false, true,  false, true),
 ('on_hold', 'On hold', 'on_hold', 'secondary', 40, false, true,  false, true),
 ('solved',  'Solved',  'solved',  'success',   50, false, true,  false, true),
 ('closed',  'Closed',  'closed',  'dark',      60, false, false, false, true);

-- Allowed moves between CATEGORIES, with what each requires. Closed is terminal: a requester reply on a closed ticket
-- makes a follow-up ticket. `requires` names ticket fields the move must carry; `may_carry` the optional ones.
CREATE TABLE status_transitions (
    from_category  text NOT NULL CHECK (from_category IN ('new', 'open', 'pending', 'on_hold', 'solved', 'closed')),
    to_category    text NOT NULL CHECK (to_category IN ('new', 'open', 'pending', 'on_hold', 'solved', 'closed')),
    requires       text[] NOT NULL DEFAULT '{}',
    may_carry      text[] NOT NULL DEFAULT '{}',
    needs_right    text NOT NULL DEFAULT 'tickets.work',          -- tickets.work, or tickets.manage for an early close; a requester's own moves are PHP's (confirm, reopen)
    label          text NOT NULL,
    PRIMARY KEY (from_category, to_category),
    CHECK (from_category <> to_category)
);
INSERT INTO status_transitions (from_category, to_category, requires, may_carry, needs_right, label) VALUES
 ('new',     'open',    '{}', '{assignee_member_id}',           'tickets.work',   'Accept'),
 ('new',     'pending', '{}', '{pending_until}',                'tickets.work',   'Snooze'),
 ('new',     'on_hold', '{hold_reason}', '{}',                  'tickets.work',   'Put on hold'),
 ('new',     'solved',  '{resolution_code}', '{}',              'tickets.work',   'Decline or solve'),
 ('open',    'pending', '{}', '{pending_until}',                'tickets.work',   'Wait for the requester'),
 ('open',    'on_hold', '{hold_reason}', '{}',                  'tickets.work',   'Put on hold'),
 ('open',    'solved',  '{resolution_code}', '{}',              'tickets.work',   'Solve'),
 ('open',    'closed',  '{resolution_code}', '{}',              'tickets.manage', 'Close now'),
 ('pending', 'open',    '{}', '{}',                             'tickets.work',   'Back to open'),
 ('pending', 'on_hold', '{hold_reason}', '{}',                  'tickets.work',   'Put on hold'),
 ('pending', 'solved',  '{resolution_code}', '{}',              'tickets.work',   'Solve'),
 ('on_hold', 'open',    '{}', '{}',                             'tickets.work',   'Resume'),
 ('on_hold', 'pending', '{}', '{pending_until}',                'tickets.work',   'Wait for the requester'),
 ('on_hold', 'solved',  '{resolution_code}', '{}',              'tickets.work',   'Solve'),
 ('solved',  'open',    '{}', '{}',                             'tickets.work',   'Reopen'),
 ('solved',  'closed',  '{}', '{}',                             'tickets.manage', 'Close');

CREATE TABLE ticket_types (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key         text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    label       text NOT NULL,
    description text,
    sort_order  integer NOT NULL DEFAULT 100,
    is_system   boolean NOT NULL DEFAULT false,
    archived_at timestamptz
);
INSERT INTO ticket_types (key, label, description, sort_order, is_system) VALUES
 ('question', 'Question', 'Something to know', 10, true),
 ('incident', 'Incident', 'Something is broken for someone', 20, true),
 ('problem',  'Problem',  'The cause behind incidents; solving it can solve its children', 30, true),
 ('task',     'Task',     'Something to do', 40, true);

-- A hierarchical topic (Billing › Refunds) with routing defaults (the osTicket "help topic" idea).
CREATE TABLE categories (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id           bigint REFERENCES categories(id) ON DELETE SET NULL,
    name                text NOT NULL,
    description         text,
    default_queue_id    bigint REFERENCES queues(id) ON DELETE SET NULL,
    default_type_id     bigint REFERENCES ticket_types(id) ON DELETE SET NULL,
    default_priority    text CHECK (default_priority IN ('low', 'normal', 'high', 'urgent')),
    sla_policy_id       bigint REFERENCES sla_policies(id) ON DELETE SET NULL,
    requester_visible   boolean NOT NULL DEFAULT true,          -- offered on the request form
    sort_order          integer NOT NULL DEFAULT 100,
    archived_at         timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX categories_name_parent_live ON categories (COALESCE(parent_id, 0), lower(name)) WHERE archived_at IS NULL;
CREATE TRIGGER categories_touch BEFORE UPDATE ON categories FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE tags (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug        text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9_-]{0,49}$'),
    label       text NOT NULL,
    usage_count integer NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE custom_field_defs (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key               text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    label             text NOT NULL,
    field_type        text NOT NULL CHECK (field_type IN ('text', 'number', 'date', 'select', 'multi_select', 'checkbox')),
    options           text[] NOT NULL DEFAULT '{}',              -- for select / multi_select
    applies_to        text NOT NULL CHECK (applies_to IN ('ticket', 'organization', 'requester')),
    type_ids          bigint[] NOT NULL DEFAULT '{}',            -- a ticket field shown for these types only; empty = all
    required_on_solve boolean NOT NULL DEFAULT false,
    requester_visible boolean NOT NULL DEFAULT false,
    sort_order        integer NOT NULL DEFAULT 100,
    archived_at       timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (applies_to, key)
);
CREATE TRIGGER custom_field_defs_touch BEFORE UPDATE ON custom_field_defs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- May a ticket move from one status to another? Answers the row (what it requires) or nothing.
CREATE OR REPLACE FUNCTION hd_transition(p_from_status_id bigint, p_to_status_id bigint)
    RETURNS TABLE (from_category text, to_category text, requires text[], may_carry text[], needs_right text, label text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT t.from_category, t.to_category, t.requires, t.may_carry, t.needs_right, t.label
          FROM ticket_statuses f, ticket_statuses s
          JOIN status_transitions t ON t.to_category = s.category
         WHERE f.id = p_from_status_id AND s.id = p_to_status_id AND t.from_category = f.category
           AND s.archived_at IS NULL
        UNION ALL
        -- a move inside one category (Open → a custom "Waiting on vendor" that is also open) is always allowed
        SELECT f.category, s.category, '{}'::text[], '{}'::text[], 'tickets.work', 'Change status'
          FROM ticket_statuses f, ticket_statuses s
         WHERE f.id = p_from_status_id AND s.id = p_to_status_id AND f.category = s.category AND f.id <> s.id
           AND s.archived_at IS NULL;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON ticket_statuses, status_transitions, ticket_types, categories, tags, custom_field_defs TO helpdesk_rw;
GRANT EXECUTE ON FUNCTION hd_transition(bigint, bigint) TO helpdesk_rw, helpdesk_records_ro;

COMMIT;
