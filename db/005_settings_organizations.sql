-- 005: the business's Help Desk settings (one row) and ORGANIZATIONS — the customer companies tickets come from
-- (design §2: a domain entity, not a tenant; nothing of a CRM). Employees need no organization: their departments
-- come from the mirror.
BEGIN;

CREATE TABLE app_settings (
    id                      smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    ticket_prefix           text NOT NULL DEFAULT 'HD' CHECK (ticket_prefix ~ '^[A-Z][A-Z0-9]{0,7}$'),   -- D9: HD-1001 onward
    auto_close_days         integer NOT NULL DEFAULT 4 CHECK (auto_close_days BETWEEN 0 AND 365),         -- solved → closed by the worker
    reopen_window_days      integer NOT NULL DEFAULT 14 CHECK (reopen_window_days BETWEEN 0 AND 365),     -- a requester reply on solved reopens within it
    secure_link_days        integer NOT NULL DEFAULT 90 CHECK (secure_link_days BETWEEN 1 AND 3650),       -- a secure link dies this long after close (D1)
    org_visibility_default  boolean NOT NULL DEFAULT false,                                                 -- may an organization's members see each other's tickets
    request_form_enabled    boolean NOT NULL DEFAULT true,                                                  -- the public /request door
    default_queue_id        bigint,                                                                         -- where a ticket lands when nothing routes it (FK added in db/008)
    timezone                text NOT NULL DEFAULT 'UTC',
    updated_at              timestamptz NOT NULL DEFAULT now()
);
INSERT INTO app_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER app_settings_touch BEFORE UPDATE ON app_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE organizations (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name             text NOT NULL,
    support_tier     text,                                         -- the business's own words (Gold, Standard …)
    notes            text,
    tags             text[] NOT NULL DEFAULT '{}',
    custom_values    jsonb NOT NULL DEFAULT '{}'::jsonb,           -- validated against custom_field_defs (applies_to organization)
    sla_policy_id    bigint,                                       -- an override of the matched policy (FK added in db/007)
    members_see_each_other boolean,                                -- NULL = the business default (app_settings)
    active           boolean NOT NULL DEFAULT true,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX organizations_name_live ON organizations (lower(name)) WHERE active;
CREATE INDEX organizations_name_trgm_idx ON organizations USING gin (name gin_trgm_ops);
CREATE TRIGGER organizations_touch BEFORE UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- An email domain claims new requesters for the organization (example.com → every @example.com writer).
CREATE TABLE organization_domains (
    domain           citext PRIMARY KEY CHECK (domain ~* '^[a-z0-9.-]+\.[a-z]{2,}$'),
    organization_id  bigint NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX organization_domains_org_idx ON organization_domains (organization_id);

GRANT SELECT, UPDATE ON app_settings TO helpdesk_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON organizations, organization_domains TO helpdesk_rw;

COMMIT;
