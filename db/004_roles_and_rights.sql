-- 004: Help Desk's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §6, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the
-- claims and the feed carry them back into members.roles. What a role lets its holder do is Help Desk's to enforce:
-- hd_has_right(right) answers from the catalogue and the mirror. Rights that say "in their queues" are narrowed by
-- queue membership (db/008 hd_queue_ids_with_right). The base role's KEY IS `user` on purpose: the kernel's installer
-- grants the standing departments the role keyed member/user/write, and would otherwise pick the wrong one.
BEGIN;

CREATE TABLE hd_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE hd_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX hd_roles_one_admin ON hd_roles (is_admin) WHERE is_admin;
CREATE TABLE hd_role_rights (
    role_key  text NOT NULL REFERENCES hd_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES hd_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO hd_rights (right_key, description, sort_order) VALUES
 ('tickets.create',     'File a ticket, for themself or on behalf of someone', 1),
 ('tickets.view_own',   'See the tickets they asked for, filed, or follow — and reply on them', 2),
 ('kb.read',            'Read the internal knowledge base', 3),
 ('tickets.view_queue', 'See every ticket of the queues they belong to, internal notes included', 4),
 ('tickets.note',       'Add internal notes and follow tickets in their queues', 5),
 ('tickets.work',       'Work tickets in their queues: reply, change status, assign, set fields and tags, link, log time, apply canned responses', 6),
 ('views.personal',     'Save personal views and boards', 7),
 ('tickets.view_all',   'See every ticket in every queue', 8),
 ('tickets.manage',     'Merge, mark as spam, bulk-update, reassign across queues, close before the auto-close window', 9),
 ('views.shared',       'Save and change shared views and boards', 10),
 ('kb.edit',            'Write and publish knowledge base articles', 11),
 ('reports.view',       'See the queue dashboards and reports', 12),
 ('queues.members',     'Name who belongs to a queue and who leads it', 13),
 ('settings.manage',    'Run Help Desk: queues, statuses and transitions, SLA policies, business hours, custom fields, canned folders, automation, mailboxes, CSAT', 14);

INSERT INTO hd_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('user',         'Requester',       'Files tickets and follows their own. Everyone who works here.', 'write', false, 1),
 ('collaborator', 'Collaborator',    'Reads the tickets of their queues and adds internal notes; never replies to a requester or owns a ticket.', 'write', false, 2),
 ('support',      'Support staff',   'Works the tickets of their queues: replies, notes, statuses, assignment, fields.', 'write', false, 3),
 ('lead',         'Lead',            'Watches every queue: workload, SLA risk, reports; reassigns, merges, keeps shared views and the knowledge base.', 'write', false, 4),
 ('admin',        'Help Desk admin', 'Runs Help Desk: everything a Lead does, plus the settings. A super-admin holds this role.', 'admin', true, 5);

INSERT INTO hd_role_rights (role_key, right_key)
SELECT 'user', r FROM unnest(ARRAY['tickets.create','tickets.view_own','kb.read']) r
UNION ALL SELECT 'collaborator', r FROM unnest(ARRAY['tickets.create','tickets.view_own','kb.read','tickets.view_queue','tickets.note']) r
UNION ALL SELECT 'support', r FROM unnest(ARRAY['tickets.create','tickets.view_own','kb.read','tickets.view_queue','tickets.note','tickets.work','views.personal']) r
UNION ALL SELECT 'lead', r FROM unnest(ARRAY['tickets.create','tickets.view_own','kb.read','tickets.view_queue','tickets.note','tickets.work','views.personal',
                                              'tickets.view_all','tickets.manage','views.shared','kb.edit','reports.view','queues.members']) r
UNION ALL SELECT 'admin', right_key FROM hd_rights;

-- The rule: a right the member's roles give. The member must be active and admitted. A member the kernel has not yet
-- sent roles for (roles = '{}') is read from the capability: admin = admin, anything else = user. A super-admin holds
-- every right (the kernel lists the admin role for them; this is the belt to that brace).
CREATE OR REPLACE FUNCTION hd_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM members m
          JOIN hd_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (m.roles)
                    OR (cardinality(m.roles) = 0 AND rr.role_key = CASE m.capability WHEN 'admin' THEN 'admin' ELSE 'user' END)
                    OR (m.member_kind = 'human' AND m.business_role = 'super_admin'))
         WHERE m.id = app_current_member_id() AND m.status = 'active' AND m.capability IS NOT NULL);
END$$;

-- Runs Help Desk: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION hd_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND hd_has_right('settings.manage'));
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM hd_role_rights rr
                   JOIN hd_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM hd_roles r;

GRANT SELECT ON hd_rights, hd_roles, hd_role_rights, mcp_app_roles TO helpdesk_rw, helpdesk_records_ro;
GRANT EXECUTE ON FUNCTION hd_has_right(text), hd_is_admin() TO helpdesk_rw, helpdesk_records_ro, helpdesk_activity_ro;

COMMIT;
