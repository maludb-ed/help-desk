-- 007: BUSINESS HOURS and SLA POLICIES (the Phase 0 plan §2.4). A schedule is weekly intervals in one time zone plus
-- holidays; a policy is ordered match conditions and per-priority targets in minutes for first response, next
-- response and resolution, counted in business or calendar hours, with a warning threshold. The targets themselves
-- (per ticket) are db/010 sla_targets; the clock arithmetic is hd_business_add_minutes() below.
BEGIN;

CREATE TABLE business_hours (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text NOT NULL UNIQUE,
    timezone    text NOT NULL DEFAULT 'UTC',
    is_default  boolean NOT NULL DEFAULT false,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX business_hours_one_default ON business_hours (is_default) WHERE is_default;
CREATE TRIGGER business_hours_touch BEFORE UPDATE ON business_hours FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE business_hours_intervals (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    schedule_id   bigint NOT NULL REFERENCES business_hours(id) ON DELETE CASCADE,
    weekday       smallint NOT NULL CHECK (weekday BETWEEN 0 AND 6),      -- 0 = Sunday (PostgreSQL's dow)
    opens_at      time NOT NULL,
    closes_at     time NOT NULL,
    CHECK (closes_at > opens_at)
);
CREATE INDEX business_hours_intervals_idx ON business_hours_intervals (schedule_id, weekday);

CREATE TABLE business_hours_holidays (
    schedule_id   bigint NOT NULL REFERENCES business_hours(id) ON DELETE CASCADE,
    holiday       date NOT NULL,
    name          text,
    PRIMARY KEY (schedule_id, holiday)
);

-- Seed: one default schedule, Monday–Friday 09:00–17:00 (the kernel's own seed, business_hours db/109, carried over).
INSERT INTO business_hours (name, timezone, is_default) VALUES ('Office hours', 'UTC', true);
INSERT INTO business_hours_intervals (schedule_id, weekday, opens_at, closes_at)
SELECT b.id, d, '09:00', '17:00' FROM business_hours b, generate_series(1, 5) d WHERE b.is_default;

-- The time p_minutes of business time after p_from, on a schedule; calendar time when the schedule is NULL.
-- Walks day by day in the schedule's zone, skipping holidays and closed days; capped at 400 days so a schedule with
-- no open hours cannot loop (it answers NULL then — no target).
CREATE OR REPLACE FUNCTION hd_business_add_minutes(p_from timestamptz, p_minutes integer, p_schedule_id bigint) RETURNS timestamptz
    LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_tz text; v_left numeric := p_minutes; v_day date; v_local timestamp; v_cursor timestamp; v_open timestamp; v_close timestamp;
    v_iv record; v_days integer := 0;
BEGIN
    IF p_minutes IS NULL OR p_from IS NULL THEN
        RETURN NULL;
    END IF;
    IF p_schedule_id IS NULL THEN
        RETURN p_from + make_interval(mins => p_minutes);
    END IF;
    SELECT timezone INTO v_tz FROM business_hours WHERE id = p_schedule_id;
    IF v_tz IS NULL THEN
        RETURN p_from + make_interval(mins => p_minutes);
    END IF;
    v_local := p_from AT TIME ZONE v_tz;
    v_day := v_local::date;
    v_cursor := v_local;
    WHILE v_days < 400 LOOP
        IF NOT EXISTS (SELECT 1 FROM business_hours_holidays h WHERE h.schedule_id = p_schedule_id AND h.holiday = v_day) THEN
            FOR v_iv IN SELECT opens_at, closes_at FROM business_hours_intervals
                         WHERE schedule_id = p_schedule_id AND weekday = EXTRACT(DOW FROM v_day)::int ORDER BY opens_at LOOP
                v_open := v_day + v_iv.opens_at;
                v_close := v_day + v_iv.closes_at;
                IF v_close <= v_cursor THEN
                    CONTINUE;
                END IF;
                IF v_cursor < v_open THEN
                    v_cursor := v_open;
                END IF;
                IF EXTRACT(EPOCH FROM (v_close - v_cursor)) / 60 >= v_left THEN
                    RETURN (v_cursor + make_interval(secs => v_left * 60)) AT TIME ZONE v_tz;
                END IF;
                v_left := v_left - EXTRACT(EPOCH FROM (v_close - v_cursor)) / 60;
                v_cursor := v_close;
            END LOOP;
        END IF;
        v_days := v_days + 1;
        v_day := v_day + 1;
        v_cursor := v_day::timestamp;
    END LOOP;
    RETURN NULL;
END$$;

CREATE TABLE sla_policies (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            text NOT NULL UNIQUE,
    description     text,
    sort_order      integer NOT NULL DEFAULT 100,                 -- the first matching policy applies
    -- match conditions: {"organization_ids": [..], "queue_ids": [..], "type_ids": [..], "priorities": ["urgent"], "tags": ["vip"]}
    -- an absent or empty list matches anything; every present list must match (AND across keys, OR within).
    conditions      jsonb NOT NULL DEFAULT '{}'::jsonb,
    -- per-priority targets in minutes: {"urgent": {"first_response": 15, "next_response": 30, "resolution": 240}, "high": {...}, ...}
    targets         jsonb NOT NULL DEFAULT '{}'::jsonb,
    business_hours_id bigint REFERENCES business_hours(id) ON DELETE SET NULL,  -- NULL = calendar hours
    warning_percent integer NOT NULL DEFAULT 80 CHECK (warning_percent BETWEEN 1 AND 99),
    active          boolean NOT NULL DEFAULT true,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER sla_policies_touch BEFORE UPDATE ON sla_policies FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Seed: a default policy for everything, on office hours.
INSERT INTO sla_policies (name, description, sort_order, conditions, targets, business_hours_id, warning_percent)
SELECT 'Standard', 'Applies when no other policy matches.', 1000, '{}'::jsonb,
       '{"urgent": {"first_response": 60, "next_response": 120, "resolution": 480},
         "high":   {"first_response": 120, "next_response": 240, "resolution": 1440},
         "normal": {"first_response": 480, "next_response": 480, "resolution": 2880},
         "low":    {"first_response": 960, "next_response": 960, "resolution": 5760}}'::jsonb,
       b.id, 80
  FROM business_hours b WHERE b.is_default;

ALTER TABLE organizations ADD CONSTRAINT organizations_sla_policy_fk FOREIGN KEY (sla_policy_id) REFERENCES sla_policies(id) ON DELETE SET NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON business_hours, business_hours_intervals, business_hours_holidays, sla_policies TO helpdesk_rw;
GRANT EXECUTE ON FUNCTION hd_business_add_minutes(timestamptz, integer, bigint) TO helpdesk_rw, helpdesk_records_ro;

COMMIT;
