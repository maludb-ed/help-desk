-- 012: the REQUESTERS' DOORS (the public form's rate ledger, CSAT), EMAIL (mailboxes on MaluMail, every inbound
-- message kept raw until linked, every outbound recorded) and the KNOWLEDGE BASE.
BEGIN;

-- Every submission of the public /request form: the rate limit's ledger (per address and per IP), and the trail.
CREATE TABLE request_form_submissions (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email         citext NOT NULL,
    ip_address    inet,
    ticket_id     bigint REFERENCES tickets(id) ON DELETE SET NULL,
    outcome       text NOT NULL CHECK (outcome IN ('created', 'rate_limited', 'rejected', 'honeypot')),
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX request_form_submissions_email_idx ON request_form_submissions (email, created_at DESC);
CREATE INDEX request_form_submissions_ip_idx ON request_form_submissions (ip_address, created_at DESC);

CREATE TABLE csat_settings (
    id                  smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    enabled             boolean NOT NULL DEFAULT true,
    scale               text NOT NULL DEFAULT 'good_bad' CHECK (scale IN ('good_bad', 'five_point')),
    question            text NOT NULL DEFAULT 'How did we do?',
    send_delay_minutes  integer NOT NULL DEFAULT 60 CHECK (send_delay_minutes BETWEEN 0 AND 10080),
    response_window_days integer NOT NULL DEFAULT 14 CHECK (response_window_days BETWEEN 1 AND 90),
    bad_reasons         text[] NOT NULL DEFAULT '{"Took too long","Not resolved","Unhelpful answer","Other"}',
    queue_ids           bigint[] NOT NULL DEFAULT '{}',          -- empty = every queue
    updated_at          timestamptz NOT NULL DEFAULT now()
);
INSERT INTO csat_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER csat_settings_touch BEFORE UPDATE ON csat_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE satisfaction_ratings (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id     bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    requester_id  bigint NOT NULL REFERENCES requesters(id) ON DELETE CASCADE,
    score         smallint CHECK (score BETWEEN 1 AND 5),          -- good_bad: 1 = bad, 5 = good
    bad_reason    text,
    comment       text,
    survey_sent_at timestamptz NOT NULL DEFAULT now(),
    responded_at  timestamptz,
    token_hash    text NOT NULL UNIQUE,                           -- the one-time rating link, hashed
    expires_at    timestamptz NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX satisfaction_ratings_ticket_idx ON satisfaction_ratings (ticket_id);

-- A queue's inbound address on MaluMail (D4). The webhook secret and the API key are references into config/.env,
-- never values here. Inbound by MaluMail's signed new-mail webhook, with a poll of unread mail every minute as the
-- safety net; the message is read through MaluMail's mailbox MCP.
CREATE TABLE mailboxes (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    address            citext NOT NULL UNIQUE,
    display_name       text NOT NULL,
    queue_id           bigint NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
    provider           text NOT NULL DEFAULT 'malumail' CHECK (provider IN ('malumail', 'imap')),
    webhook_secret_ref text,                                      -- the env key holding the webhook secret (e.g. MAILBOX_SUPPORT_WEBHOOK_SECRET)
    signature          text,
    poll_enabled       boolean NOT NULL DEFAULT true,
    last_polled_at     timestamptz,
    last_uid           bigint,
    active             boolean NOT NULL DEFAULT true,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER mailboxes_touch BEFORE UPDATE ON mailboxes FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Nothing is thrown away before it is linked to a ticket and a message.
CREATE TABLE inbound_email (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id      bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    provider_uid    text,
    message_id      text,
    in_reply_to     text,
    references_ids  text[] NOT NULL DEFAULT '{}',
    from_address    citext,
    from_name       text,
    to_addresses    text[] NOT NULL DEFAULT '{}',
    cc_addresses    text[] NOT NULL DEFAULT '{}',
    subject         text,
    body_text       text,
    body_html       text,
    headers         jsonb,
    received_at     timestamptz NOT NULL DEFAULT now(),
    status          text NOT NULL DEFAULT 'received' CHECK (status IN ('received', 'processed', 'ignored', 'failed', 'bounce', 'auto_reply', 'suppressed')),
    error           text,
    ticket_id       bigint REFERENCES tickets(id) ON DELETE SET NULL,
    message_ref_id  bigint REFERENCES messages(id) ON DELETE SET NULL,
    processed_at    timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX inbound_email_uid_idx ON inbound_email (mailbox_id, provider_uid) WHERE provider_uid IS NOT NULL;
CREATE INDEX inbound_email_message_id_idx ON inbound_email (message_id);
CREATE INDEX inbound_email_status_idx ON inbound_email (status, received_at DESC);

CREATE TABLE outbound_email (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id       bigint REFERENCES tickets(id) ON DELETE SET NULL,
    message_ref_id  bigint REFERENCES messages(id) ON DELETE SET NULL,
    to_address      citext NOT NULL,
    subject         text NOT NULL,
    template        text,
    provider_message_id text,
    status          text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'failed', 'suppressed')),
    error           text,
    sent_at         timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX outbound_email_ticket_idx ON outbound_email (ticket_id);

CREATE TABLE kb_sections (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id   bigint REFERENCES kb_sections(id) ON DELETE SET NULL,
    name        text NOT NULL,
    slug        text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9-]{0,79}$'),
    description text,
    visibility  text NOT NULL DEFAULT 'internal' CHECK (visibility IN ('public', 'internal')),
    sort_order  integer NOT NULL DEFAULT 100,
    archived_at timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER kb_sections_touch BEFORE UPDATE ON kb_sections FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE articles (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    section_id      bigint REFERENCES kb_sections(id) ON DELETE SET NULL,
    title           text NOT NULL,
    slug            text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9-]{0,119}$'),
    body_text       text NOT NULL DEFAULT '',
    body_html       text,
    status          text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
    visibility      text NOT NULL DEFAULT 'internal' CHECK (visibility IN ('public', 'internal')),
    tags            text[] NOT NULL DEFAULT '{}',
    author_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    view_count      integer NOT NULL DEFAULT 0,
    helpful_count   integer NOT NULL DEFAULT 0,
    unhelpful_count integer NOT NULL DEFAULT 0,
    published_at    timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX articles_search_idx ON articles USING gin ((title || ' ' || body_text) gin_trgm_ops);
CREATE INDEX articles_status_idx ON articles (status, visibility);
CREATE TRIGGER articles_touch BEFORE UPDATE ON articles FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

ALTER TABLE attachments ADD CONSTRAINT attachments_article_fk FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE;

CREATE TABLE article_links (
    ticket_id   bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    article_id  bigint NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('suggested', 'sent', 'created_from')),
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (ticket_id, article_id, kind)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON request_form_submissions, satisfaction_ratings, mailboxes, inbound_email, outbound_email,
    kb_sections, articles, article_links TO helpdesk_rw;
GRANT SELECT, UPDATE ON csat_settings TO helpdesk_rw;

COMMIT;
