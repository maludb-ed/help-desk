"""Help Desk — working faster, knowledge, service levels, reports, the caller's own things (tool surface, the last three tables). Reads the mcp_*
views only; the same definitions as the PHP reports (app/features/reports/queries.php) — a period is [from 00:00, to 24:00) in the CALLER's zone here."""
from __future__ import annotations

import datetime

from pydantic import Field

from hd_common import NEEDS_QUEUE, OPEN_CATEGORIES, RO, Where, _Base, caller_tz, dumps, err, has_right, lim, localize, period_bounds, q_alone, to_ts, word_start, words


class CannedIn(_Base):
    q: str | None = Field(None, description="Words in the title or body. `q` alone answers a plain list (canned_id, title)")
    folder: str | None = None
    scope: str | None = Field(None, description="personal, queue or global")
    limit: int = Field(25, ge=1, le=100)


class ArticlesIn(_Base):
    q: str | None = Field(None, description="Words in the title, body or tags. `q` alone answers a plain list (article_id, title)")
    article_id: int | None = Field(None, description="One article in full")
    slug: str | None = None
    section_id: int | None = None
    visibility: str | None = Field(None, description="public or internal")
    status: str | None = Field(None, description="draft, published or archived (kb.edit)")
    limit: int = Field(10, ge=1, le=50)


class PoliciesIn(_Base):
    q: str | None = Field(None, description="A policy's name. `q` alone answers a plain list (policy_id, name)")
    policy_id: int | None = None


class RulesIn(_Base):
    q: str | None = Field(None, description="A rule's name. `q` alone answers a plain list (rule_id, name)")
    kind: str | None = Field(None, description="trigger or time_based")
    active: bool | None = None
    with_stats: bool = Field(False, description="Add runs in the last 30 days by outcome")
    limit: int = Field(50, ge=1, le=100)


class RuleRunsIn(_Base):
    rule_id: int | None = None
    ticket_id: int | None = None
    outcome: str | None = Field(None, description="applied, skipped or error")
    since: str | None = None
    limit: int = Field(25, ge=1, le=100)


class MetricsIn(_Base):
    period: str | None = Field(None, description="today, week, month, last_week, last_month, last_7_days, last_30_days (default)")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    compare: bool = Field(True, description="Also the period before, of the same length")
    queue_id: int | None = None


class CsatIn(_Base):
    period: str | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    queue_id: int | None = None
    score: str = Field("all", description="bad, good or all")
    limit: int = Field(25, ge=1, le=100)


class TimeIn(_Base):
    ticket_id: int | None = None
    member_id: int | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    limit: int = Field(50, ge=1, le=100)


class DispatchIn(_Base):
    ticket_id: int | None = None
    agent_member_id: int | None = None
    since: str | None = None
    limit: int = Field(25, ge=1, le=100)


class MailboxIn(_Base):
    q: str | None = Field(None, description="An address. `q` alone answers a plain list (mailbox_id, address)")
    failures_only: bool = False
    since: str | None = None
    limit: int = Field(25, ge=1, le=100)


class NotifIn(_Base):
    unread_only: bool = False
    limit: int = Field(25, ge=1, le=100)


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over the mcp_* views (see below).")


def esc_like(s: str) -> str:
    return "%" + s.replace("%", "\\%").replace("_", "\\_") + "%"


def register(mcp, fetch) -> None:
    @mcp.tool(name="search_canned_responses", annotations={"title": "Canned responses", **RO})
    async def search_canned_responses(params: CannedIn) -> str:
        """A canned response that fits: by words in title or body, folder, scope; the text with its placeholders and side-effects. Call before canned_apply.
        `q` alone answers a plain list (canned_id, title). Support staff (tickets.work)."""
        w = Where().raw("c.archived_at IS NULL")
        for x in words(params.q):
            n = w.p(word_start(x))
            w.raw(f"(c.title ~* {n} OR c.body_text ~* {n})")
        if params.folder:
            w.add("c.folder = {}", params.folder)
        if params.scope in ("personal", "queue", "global"):
            w.add("c.scope = {}", params.scope)
        rows = await fetch(f"SELECT c.canned_id, c.title, c.folder, c.body_text, c.scope, c.queue_id, c.side_effects, c.usage_count FROM mcp_canned_responses c WHERE {w.sql()} ORDER BY c.usage_count DESC, lower(c.title) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"canned_id": r["canned_id"], "title": r["title"]} for r in rows])
        return dumps({"responses": rows})

    @mcp.tool(name="search_articles", annotations={"title": "Knowledge base", **RO})
    async def search_articles(params: ArticlesIn) -> str:
        """Knowledge base articles by words, section, tag, visibility; a published article in full by article_id or slug; the sections. Call before
        replying. `q` alone answers a plain list (article_id, title). Public articles to every member, internal ones with kb.read, drafts with kb.edit."""
        if params.article_id is not None or params.slug:
            w = Where()
            if params.article_id is not None:
                w.add("a.article_id = {}", params.article_id)
            else:
                w.add("a.slug = {}", params.slug)
            rows = await fetch(f"SELECT a.article_id, a.section_id, a.title, a.slug, a.body_text, a.status, a.visibility, a.tags, a.helpful_count, a.unhelpful_count, a.published_at, a.updated_at FROM mcp_articles a WHERE {w.sql()}", *w.args)
            return dumps({"article": rows[0]}) if rows else err("Article not found.")
        w = Where()
        if not (await has_right(fetch, "kb.edit") and params.status):
            w.raw("a.status = 'published'")
        else:
            w.add("a.status = {}", params.status)
        for x in words(params.q):
            n = w.p(word_start(x))
            w.raw(f"(a.title ~* {n} OR a.body_text ~* {n} OR EXISTS (SELECT 1 FROM unnest(a.tags) g WHERE g ~* {n}))")
        if params.section_id is not None:
            w.add("a.section_id = {}", params.section_id)
        if params.visibility in ("public", "internal"):
            w.add("a.visibility = {}", params.visibility)
        rows = await fetch(f"SELECT a.article_id, a.section_id, a.title, a.slug, left(a.body_text, 240) AS excerpt, a.status, a.visibility, a.tags, a.helpful_count, a.unhelpful_count, a.updated_at FROM mcp_articles a WHERE {w.sql()} ORDER BY a.helpful_count DESC, a.updated_at DESC LIMIT {lim(params.limit, 10, 50)}", *w.args)
        if q_alone(params):
            return dumps([{"article_id": r["article_id"], "title": r["title"]} for r in rows])
        secs = await fetch("SELECT section_id, parent_id, name, slug, description, visibility FROM mcp_kb_sections ORDER BY sort_order, lower(name)")
        return dumps({"articles": rows, "sections": secs})

    @mcp.tool(name="find_sla_policies", annotations={"title": "SLA policies and business hours", **RO})
    async def find_sla_policies(params: PoliciesIn) -> str:
        """The SLA policies in match order with their conditions, per-priority targets and warning threshold, and the business-hours schedules with intervals
        and holidays. `q` alone answers a plain list (policy_id, name). Queue holders."""
        w = Where()
        if params.policy_id is not None:
            w.add("p.policy_id = {}", params.policy_id)
        for x in words(params.q):
            w.add("p.name ~* {}", word_start(x))
        pol = await fetch(f"SELECT p.policy_id, p.name, p.description, p.sort_order, p.conditions, p.targets, p.business_hours_id, p.warning_percent, p.active FROM mcp_sla_policies p WHERE {w.sql()} ORDER BY p.sort_order, p.policy_id", *w.args)
        if q_alone(params):
            return dumps([{"policy_id": r["policy_id"], "name": r["name"]} for r in pol])
        hours = await fetch("SELECT schedule_id, name, timezone, is_default, intervals, holidays FROM mcp_business_hours ORDER BY is_default DESC, name")
        return dumps({"policies": pol, "business_hours": hours})

    @mcp.tool(name="find_rules", annotations={"title": "Automation rules", **RO})
    async def find_rules(params: RulesIn) -> str:
        """The automation rules — kind, event, conditions, actions, active, run count, last run — which fire most and which never. `q` alone answers a plain
        list (rule_id, name). Queue holders."""
        w = Where()
        for x in words(params.q):
            w.add("r.name ~* {}", word_start(x))
        if params.kind in ("trigger", "time_based"):
            w.add("r.kind = {}", params.kind)
        if params.active is not None:
            w.add("r.active = {}", params.active)
        rows = await fetch(f"SELECT r.rule_id, r.name, r.kind, r.event, r.conditions, r.actions, r.sort_order, r.active, r.run_count, r.last_run_at FROM mcp_automation_rules r WHERE {w.sql()} ORDER BY r.kind, r.sort_order, r.rule_id LIMIT {lim(params.limit, 50)}", *w.args)
        if q_alone(params):
            return dumps([{"rule_id": r["rule_id"], "name": r["name"]} for r in rows])
        if params.with_stats:
            st = await fetch("SELECT rule_id, outcome, count(*) AS n FROM mcp_rule_runs WHERE ran_at > now() - interval '30 days' GROUP BY 1, 2")
            for r in rows:
                r["last_30_days"] = {s["outcome"]: s["n"] for s in st if s["rule_id"] == r["rule_id"]}
        return dumps({"rules": rows})

    @mcp.tool(name="rule_runs", annotations={"title": "What rules did", **RO})
    async def rule_runs(params: RuleRunsIn) -> str:
        """What a rule did, or every rule that touched a ticket: outcome and detail per run. Queue holders."""
        w = Where()
        if params.rule_id is not None:
            w.add("r.rule_id = {}", params.rule_id)
        if params.ticket_id is not None:
            w.add("r.ticket_id = {}", params.ticket_id)
        if params.outcome:
            w.add("r.outcome = {}", params.outcome)
        if params.since:
            ts = to_ts(params.since)
            if ts is None:
                return err("since is an ISO date or date-time.")
            w.add("r.ran_at > {}", ts)
        tz = await caller_tz(fetch)
        rows = await fetch(f"SELECT r.run_id, r.rule_id, r.rule_name, r.ticket_id, r.outcome, r.detail, r.ran_at FROM mcp_rule_runs r WHERE {w.sql()} ORDER BY r.ran_at DESC, r.run_id DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"timezone": tz, "runs": localize(rows, tz, "ran_at")})

    # ---- metrics -------------------------------------------------------------------------------------------------------------
    async def metrics(s: datetime.datetime, e: datetime.datetime, queue_id: int | None, by_queue: bool) -> dict | list:
        w = Where().raw("t.merged_into_id IS NULL")
        a, b = w.p(s), w.p(e)
        if queue_id is not None:
            w.add("t.queue_id = {}", queue_id)
        grp, sel = ("t.queue_id, t.queue_name,", "t.queue_id, t.queue_name,") if by_queue else ("", "")
        gb = "GROUP BY t.queue_id, t.queue_name ORDER BY t.queue_name" if by_queue else ""
        vol = await fetch(f"""SELECT {sel} count(*) FILTER (WHERE t.created_at >= {a} AND t.created_at < {b}) AS created,
               count(*) FILTER (WHERE t.solved_at >= {a} AND t.solved_at < {b}) AS solved, count(*) FILTER (WHERE t.closed_at >= {a} AND t.closed_at < {b}) AS closed,
               count(*) FILTER (WHERE t.reopen_count > 0 AND t.solved_at >= {a} AND t.solved_at < {b}) AS reopened,
               percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM (t.solved_at - t.created_at)) / 3600) FILTER (WHERE t.solved_at >= {a} AND t.solved_at < {b}) AS resolution_median_hours,
               percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM (t.first_response_at - t.created_at)) / 60) FILTER (WHERE t.created_at >= {a} AND t.created_at < {b} AND t.first_response_at IS NOT NULL) AS first_response_median_minutes,
               count(*) FILTER (WHERE t.created_at >= {a} AND t.created_at < {b} AND t.first_response_at IS NOT NULL) AS responded
             FROM mcp_tickets t WHERE {w.sql()} {gb}""", *w.args)
        w2 = Where().raw("t.merged_into_id IS NULL").raw("g.due_at IS NOT NULL")
        a2, b2 = w2.p(s), w2.p(e)
        w2.raw(f"t.created_at >= {a2} AND t.created_at < {b2}")
        if queue_id is not None:
            w2.add("t.queue_id = {}", queue_id)
        breached = "(g.breached_at IS NOT NULL OR (g.met_at IS NULL AND now() > g.due_at) OR (g.met_at IS NOT NULL AND g.met_at > g.due_at))"
        sla = await fetch(f"""SELECT {sel} g.metric, count(*) AS due, count(*) FILTER (WHERE g.met_at IS NOT NULL AND g.met_at <= g.due_at) AS met, count(*) FILTER (WHERE {breached}) AS breached
             FROM mcp_sla_targets g JOIN mcp_tickets t ON t.ticket_id = g.ticket_id WHERE {w2.sql()} GROUP BY {grp} g.metric""", *w2.args)
        cs = (await fetch("SELECT scale FROM mcp_csat_settings LIMIT 1")) or [{"scale": "good_bad"}]
        good = 4 if cs[0]["scale"] == "five_point" else 5
        w3 = Where().raw("r.survey_sent_at IS NOT NULL")
        a3, b3 = w3.p(s), w3.p(e)
        w3.raw(f"r.survey_sent_at >= {a3} AND r.survey_sent_at < {b3}")
        if queue_id is not None:
            w3.add("t.queue_id = {}", queue_id)
        cr = await fetch(f"""SELECT {sel} count(*) AS sent, count(r.responded_at) AS answered, count(*) FILTER (WHERE r.responded_at IS NOT NULL AND r.score >= {good}) AS good,
               count(*) FILTER (WHERE r.responded_at IS NOT NULL AND r.score < {good}) AS bad, avg(r.score) FILTER (WHERE r.responded_at IS NOT NULL) AS mean_score
             FROM mcp_satisfaction_ratings r JOIN mcp_tickets t ON t.ticket_id = r.ticket_id WHERE {w3.sql()} {gb}""", *w3.args)

        def shape(v: dict, sl: list[dict], c: dict | None) -> dict:
            out = {"created": v["created"], "solved": v["solved"], "closed": v["closed"], "reopened": v["reopened"], "responded": v["responded"],
                   "first_response_median_minutes": None if v["first_response_median_minutes"] is None else round(float(v["first_response_median_minutes"]), 1),
                   "resolution_median_hours": None if v["resolution_median_hours"] is None else round(float(v["resolution_median_hours"]), 2),
                   "reopen_rate_pct": round(v["reopened"] * 100 / v["solved"], 1) if v["solved"] else None, "sla": {}}
            for x in sl:
                out["sla"][x["metric"]] = {"due": x["due"], "met": x["met"], "breached": x["breached"], "attained_pct": round(x["met"] * 100 / x["due"], 1) if x["due"] else None}
            c = c or {"sent": 0, "answered": 0, "good": 0, "bad": 0, "mean_score": None}
            out["csat"] = {"sent": c["sent"], "answered": c["answered"], "good": c["good"], "bad": c["bad"], "good_pct": round(c["good"] * 100 / c["answered"], 1) if c["answered"] else None,
                           "mean": None if c["mean_score"] is None or cs[0]["scale"] != "five_point" else round(float(c["mean_score"]), 2)}
            return out

        if not by_queue:
            return shape(vol[0], sla, cr[0] if cr else None)
        return [{"queue_id": v["queue_id"], "queue_name": v["queue_name"], **shape(v, [x for x in sla if x["queue_id"] == v["queue_id"]], next((c for c in cr if c["queue_id"] == v["queue_id"]), None))} for v in vol]

    @mcp.tool(name="metrics_summary", annotations={"title": "The period's numbers", **RO})
    async def metrics_summary(params: MetricsIn) -> str:
        """The period's numbers against the one before: volume, first-response and resolution medians, SLA attainment per metric, reopen rate, CSAT, by queue.
        A period is [from 00:00, to 24:00) in your time zone. tickets.view_all or reports.view sees every queue; a queue's holder their queues."""
        if not (await has_right(fetch, "reports.view") or await has_right(fetch, "tickets.view_queue")):
            return err("You may not see reports.")
        tz = await caller_tz(fetch)
        try:
            s, e, f, t = period_bounds(params.period, params.from_, params.to, tz)
        except ValueError as ex:
            return err(str(ex))
        out = {"timezone": tz, "period": {"from": f, "to": t}, "queue_id": params.queue_id, "summary": await metrics(s, e, params.queue_id, False), "by_queue": await metrics(s, e, params.queue_id, True)}
        if params.compare:
            ps, pe = s - (e - s), s
            out["previous"] = {"period": {"from": (ps.astimezone(__import__("zoneinfo").ZoneInfo(tz))).date().isoformat(), "to": (pe.astimezone(__import__("zoneinfo").ZoneInfo(tz)) - datetime.timedelta(days=1)).date().isoformat()},
                               "summary": await metrics(ps, pe, params.queue_id, False)}
        return dumps(out)

    @mcp.tool(name="csat_summary", annotations={"title": "CSAT trend and ratings", **RO})
    async def csat_summary(params: CsatIn) -> str:
        """The CSAT trend and the ratings themselves — bad ones with their reason and comment. Queue holders; your own rating."""
        tz = await caller_tz(fetch)
        try:
            s, e, f, t = period_bounds(params.period, params.from_, params.to, tz)
        except ValueError as ex:
            return err(str(ex))
        cs = (await fetch("SELECT scale, bad_reasons FROM mcp_csat_settings LIMIT 1")) or [{"scale": "good_bad", "bad_reasons": []}]
        good = 4 if cs[0]["scale"] == "five_point" else 5
        w = Where()
        a, b = w.p(s), w.p(e)
        w.raw(f"r.survey_sent_at >= {a} AND r.survey_sent_at < {b}")
        if params.queue_id is not None:
            w.add("t.queue_id = {}", params.queue_id)
        agg = (await fetch(f"""SELECT count(*) AS sent, count(r.responded_at) AS answered, count(*) FILTER (WHERE r.responded_at IS NOT NULL AND r.score >= {good}) AS good,
               count(*) FILTER (WHERE r.responded_at IS NOT NULL AND r.score < {good}) AS bad, avg(r.score) FILTER (WHERE r.responded_at IS NOT NULL) AS mean
             FROM mcp_satisfaction_ratings r JOIN mcp_tickets t ON t.ticket_id = r.ticket_id WHERE {w.sql()}""", *w.args))[0]
        trend = await fetch(f"""SELECT to_char(date_trunc('week', r.survey_sent_at AT TIME ZONE '{tz}'), 'YYYY-MM-DD') AS week, count(r.responded_at) AS answered,
               count(*) FILTER (WHERE r.responded_at IS NOT NULL AND r.score >= {good}) AS good FROM mcp_satisfaction_ratings r JOIN mcp_tickets t ON t.ticket_id = r.ticket_id WHERE {w.sql()} GROUP BY 1 ORDER BY 1""", *w.args)
        w.raw("r.responded_at IS NOT NULL")
        if params.score == "bad":
            w.raw(f"r.score < {good}")
        elif params.score == "good":
            w.raw(f"r.score >= {good}")
        rows = await fetch(f"""SELECT r.rating_id, r.ticket_id, t.number, t.subject, t.queue_name, r.score, r.bad_reason, r.comment, r.responded_at
             FROM mcp_satisfaction_ratings r JOIN mcp_tickets t ON t.ticket_id = r.ticket_id WHERE {w.sql()} ORDER BY r.responded_at DESC LIMIT {lim(params.limit)}""", *w.args)
        return dumps({"timezone": tz, "scale": cs[0]["scale"], "period": {"from": f, "to": t},
                      "totals": {"sent": agg["sent"], "answered": agg["answered"], "good": agg["good"], "bad": agg["bad"], "good_pct": round(agg["good"] * 100 / agg["answered"], 1) if agg["answered"] else None,
                                 "mean": None if agg["mean"] is None or cs[0]["scale"] != "five_point" else round(float(agg["mean"]), 2)},
                      "trend": trend, "ratings": localize(rows, tz, "responded_at")})

    @mcp.tool(name="time_entries", annotations={"title": "Time logged", **RO})
    async def time_entries(params: TimeIn) -> str:
        """Time logged on a ticket, or by a person over a period, billable split. Your own entries; queue holders' queues."""
        w = Where()
        if params.ticket_id is not None:
            w.add("e.ticket_id = {}", params.ticket_id)
        if params.member_id is not None:
            w.add("e.member_id = {}", params.member_id)
        for v, op in ((params.from_, ">="), (params.to, "<=")):
            if v:
                try:
                    w.add(f"e.worked_on {op} {{}}", datetime.date.fromisoformat(v[:10]))
                except ValueError:
                    return err("from and to are YYYY-MM-DD dates.")
        rows = await fetch(f"SELECT e.entry_id, e.ticket_id, e.member_id, e.display_name, e.minutes, e.billable, e.note, e.worked_on FROM mcp_time_entries e WHERE {w.sql()} ORDER BY e.worked_on DESC, e.entry_id DESC LIMIT {lim(params.limit, 50)}", *w.args)
        return dumps({"entries": rows, "minutes": sum(r["minutes"] for r in rows), "billable_minutes": sum(r["minutes"] for r in rows if r["billable"])})

    @mcp.tool(name="agent_dispatches", annotations={"title": "What was handed to which agent", **RO})
    async def agent_dispatches(params: DispatchIn) -> str:
        """What was handed to which agent for a ticket — when, by whom, how (chat, wake, duty), the run and what came back. The agent's own; queue holders."""
        w = Where()
        if params.ticket_id is not None:
            w.add("d.ticket_id = {}", params.ticket_id)
        if params.agent_member_id is not None:
            w.add("d.agent_member_id = {}", params.agent_member_id)
        if params.since:
            ts = to_ts(params.since)
            if ts is None:
                return err("since is an ISO date or date-time.")
            w.add("d.created_at > {}", ts)
        tz = await caller_tz(fetch)
        rows = await fetch(f"SELECT d.dispatch_id, d.ticket_id, d.agent_member_id, d.agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.status, d.reply_excerpt, d.created_at, d.answered_at FROM mcp_agent_dispatches d WHERE {w.sql()} ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"timezone": tz, "dispatches": localize(rows, tz, "created_at", "answered_at")})

    @mcp.tool(name="find_mailboxes", annotations={"title": "Mailboxes and failed mail", **RO})
    async def find_mailboxes(params: MailboxIn) -> str:
        """The queues' mailboxes and the inbound mail that failed, bounced or was ignored, with the error. `q` alone answers a plain list (mailbox_id, address).
        Admins (settings.manage)."""
        w = Where()
        for x in words(params.q):
            w.add("m.address ~* {}", word_start(x))
        boxes = await fetch(f"SELECT m.mailbox_id, m.address, m.display_name, m.queue_id, m.provider, m.poll_enabled, m.last_polled_at, m.active FROM mcp_mailboxes m WHERE {w.sql()} ORDER BY m.address LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"mailbox_id": r["mailbox_id"], "address": r["address"]} for r in boxes])
        wi = Where()
        if params.failures_only:
            wi.raw("i.status IN ('failed', 'error', 'ignored', 'bounced')")
        if params.since:
            ts = to_ts(params.since)
            if ts is None:
                return err("since is an ISO date or date-time.")
            wi.add("i.received_at > {}", ts)
        inbound = await fetch(f"SELECT i.inbound_id, i.mailbox_id, i.message_id, i.from_address, i.subject, i.received_at, i.status, i.error, i.ticket_id FROM mcp_inbound_email i WHERE {wi.sql()} ORDER BY i.received_at DESC LIMIT {lim(params.limit)}", *wi.args)
        return dumps({"mailboxes": boxes, "inbound": inbound})

    @mcp.tool(name="my_notifications", annotations={"title": "My notifications", **RO})
    async def my_notifications(params: NotifIn) -> str:
        """Your own in-app notifications, unread first."""
        w = Where()
        if params.unread_only:
            w.raw("n.read_at IS NULL")
        tz = await caller_tz(fetch)
        rows = await fetch(f"SELECT n.notification_id, n.kind, n.ticket_id, n.title, n.body, n.read_at, n.created_at FROM mcp_notifications n WHERE {w.sql()} ORDER BY (n.read_at IS NULL) DESC, n.created_at DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"timezone": tz, "notifications": localize(rows, tz, "created_at")})

    @mcp.tool(name="my_tokens", annotations={"title": "My MCP tokens", **RO})
    async def my_tokens() -> str:
        """Your own MCP tokens: label, scope, last used, expiry — never the value."""
        return dumps({"tokens": await fetch("SELECT token_id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_my_tokens ORDER BY created_at DESC")})
