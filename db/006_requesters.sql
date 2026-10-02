-- 006: REQUESTERS — anyone a ticket is for. Three kinds (design §2, D1):
--   member    an OS member (an employee, or an invited external): `member_id` is the kernel's id; made at first sign-on
--             or first ticket; name and email follow the mirror;
--   external  a person with no OS account, known by email alone; reaches their ticket by the secure link and by mail;
--   agent     an OS agent that filed a ticket for itself: `member_id` is the agent's.
-- Identity for members stays in the mirror; this table is the help desk's own facts about the person who asks.
BEGIN;

CREATE TABLE requesters (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind             text NOT NULL CHECK (kind IN ('member', 'external', 'agent')),
    member_id        bigint UNIQUE REFERENCES members(id) ON DELETE SET NULL,
    display_name     text NOT NULL,
    primary_email    citext,
    phone            text,                                      -- an external's; a member's phone is the kernel's (K6)
    organization_id  bigint REFERENCES organizations(id) ON DELETE SET NULL,
    locale           text,
    timezone         text,
    notes            text,                                      -- support staff's notes about the requester (never shown to them)
    custom_values    jsonb NOT NULL DEFAULT '{}'::jsonb,
    merged_into_id   bigint REFERENCES requesters(id) ON DELETE SET NULL,
    last_seen_at     timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'external') = (member_id IS NULL))
);
CREATE INDEX requesters_name_trgm_idx ON requesters USING gin (display_name gin_trgm_ops);
CREATE INDEX requesters_org_idx ON requesters (organization_id);
CREATE INDEX requesters_email_idx ON requesters (primary_email);
CREATE TRIGGER requesters_touch BEFORE UPDATE ON requesters FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Every address that maps to a requester (the primary among them). Inbound mail finds its requester here.
CREATE TABLE requester_emails (
    email            citext PRIMARY KEY CHECK (email ~* '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
    requester_id     bigint NOT NULL REFERENCES requesters(id) ON DELETE CASCADE,
    verified_at      timestamptz,                               -- an address that wrote to us, or a member's mirrored one
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX requester_emails_requester_idx ON requester_emails (requester_id);

-- The requester row of a member, made on first need from the mirror (the writer's own function; PHP calls it).
CREATE OR REPLACE FUNCTION hd_requester_for_member(p_member_id bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r bigint; m members%ROWTYPE;
BEGIN
    SELECT id INTO r FROM requesters WHERE member_id = p_member_id;
    IF r IS NOT NULL THEN
        RETURN r;
    END IF;
    SELECT * INTO m FROM members WHERE id = p_member_id;
    IF m.id IS NULL THEN
        RAISE EXCEPTION 'That member is not known here.' USING ERRCODE = 'P0001';
    END IF;
    INSERT INTO requesters (kind, member_id, display_name, primary_email, timezone, organization_id)
    VALUES (CASE m.member_kind WHEN 'agent' THEN 'agent' ELSE 'member' END, m.id, m.display_name, m.email, m.timezone,
            -- an invited external is claimed by the organization whose domain their address carries; an employee by none
            CASE WHEN m.is_external AND m.email IS NOT NULL
                 THEN (SELECT organization_id FROM organization_domains WHERE domain = split_part(m.email::text, '@', 2)) END)
    RETURNING id INTO r;
    IF m.email IS NOT NULL THEN
        INSERT INTO requester_emails (email, requester_id, verified_at) VALUES (m.email, r, now())
        ON CONFLICT (email) DO UPDATE SET requester_id = EXCLUDED.requester_id, verified_at = now();
    END IF;
    RETURN r;
END$$;

-- The requester row of an email address: a member's when the mirror knows the address, else the external who wrote
-- before, else a new external — claimed by an organization when its domain matches. Never shown to the writer.
CREATE OR REPLACE FUNCTION hd_requester_for_email(p_email text, p_name text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r bigint; mid bigint; org bigint; addr citext := lower(trim(p_email));
BEGIN
    IF addr !~* '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
        RAISE EXCEPTION 'That is not an email address.' USING ERRCODE = 'P0001';
    END IF;
    SELECT re.requester_id INTO r FROM requester_emails re JOIN requesters q ON q.id = re.requester_id WHERE re.email = addr;
    IF r IS NOT NULL THEN
        RETURN COALESCE((SELECT merged_into_id FROM requesters WHERE id = r), r);
    END IF;
    SELECT id INTO mid FROM members WHERE email = addr AND status = 'active';
    IF mid IS NOT NULL THEN
        RETURN hd_requester_for_member(mid);
    END IF;
    SELECT organization_id INTO org FROM organization_domains WHERE domain = split_part(addr, '@', 2);
    INSERT INTO requesters (kind, display_name, primary_email, organization_id)
    VALUES ('external', COALESCE(NULLIF(trim(p_name), ''), split_part(addr, '@', 1)), addr, org)
    RETURNING id INTO r;
    INSERT INTO requester_emails (email, requester_id, verified_at) VALUES (addr, r, NULL);
    RETURN r;
END$$;

-- The caller's own requester id (NULL for nobody, or a member who never asked anything).
CREATE OR REPLACE FUNCTION hd_my_requester_id() RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r bigint;
BEGIN
    SELECT id INTO r FROM requesters WHERE member_id = app_current_member_id();
    RETURN r;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON requesters, requester_emails TO helpdesk_rw;
REVOKE ALL ON FUNCTION hd_requester_for_member(bigint), hd_requester_for_email(text, text) FROM PUBLIC;   -- the writer's alone
GRANT EXECUTE ON FUNCTION hd_requester_for_member(bigint), hd_requester_for_email(text, text) TO helpdesk_rw;
GRANT EXECUTE ON FUNCTION hd_my_requester_id() TO helpdesk_rw, helpdesk_records_ro, helpdesk_activity_ro;

COMMIT;
