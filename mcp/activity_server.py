"""helpdesk_activity_mcp — Help Desk's activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/helpdesk-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides which rows they see: what they
did themselves, what happened in the queues they hold, everything with tickets.view_all, and the trail of their own tickets. The activity role reads
mcp_activity_log plus mcp_members, mcp_tickets and mcp_queues for names and ticket facts. A message body, a secure token, a token value and another
requester's address are never in a payload (the handlers' rule), so nothing here can return one.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from hd_common import RO, Where, _Base, caller_tz, dumps, err, has_right, lim, localize, period_bounds, to_ts

mcp = FastMCP("helpdesk_activity_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


COLS = "l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.actor_requester_id, l.source, l.action, l.entity_type, l.entity_id, l.ticket_id, l.queue_id, l.before, l.after, l.agent_run_id"
CONFIG_PREFIXES = ("queue.", "status.", "transition.", "type.", "category.", "tag.", "field.", "sla_policy.", "business_hours.", "rule.", "mailbox.", "settings.", "csat_settings.", "organization.",
                   "canned.create", "canned.update", "canned.delete", "section.", "article.create", "article.update", "article.publish", "article.archive", "article.delete", "view.", "token.")


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over mcp_activity_log and the three fact views (mcp_members, mcp_tickets, mcp_queues).")


class HistoryIn(_Base):
    ticket_id: int | None = None
    number: str | None = Field(None, description="HD-1042 (or the digits)")
    order: str = Field("oldest", description="oldest or newest first")
    limit: int = Field(100, ge=1, le=200)


class TouchedIn(_Base):
    ticket_id: int
    limit: int = Field(50, ge=1, le=200)


class TimelineIn(_Base):
    member_id: int | None = Field(None, description="A person or agent; omit for yourself")
    actor_kind: str | None = Field(None, description="human or agent — everyone of that kind (what every agent did yesterday)")
    period: str | None = Field("today", description="today, yesterday, week, last_week, month, last_month, last_7_days, last_30_days")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    action: str | None = Field(None, description="An action or a prefix, e.g. ticket.reply, screen.view, ticket.")
    ticket_id: int | None = None
    limit: int = Field(100, ge=1, le=200)


class QueueActivityIn(_Base):
    queue_id: int
    period: str | None = Field("week", description="today, yesterday, week, last_week, month, last_30_days")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    action: str | None = None
    limit: int = Field(100, ge=1, le=200)


class ConfigLogIn(_Base):
    period: str | None = Field("last_30_days")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    entity_type: str | None = Field(None, description="queue, status, category, rule, mailbox, … (the log's entity_type)")
    limit: int = Field(100, ge=1, le=200)


def number_of(s: str) -> str:
    n = s.strip().upper()
    return "HD-" + n if n.isdigit() else n


async def ticket_ok(ticket_id: int) -> dict | None:
    r = await fetch("SELECT ticket_id, number, subject, queue_id FROM mcp_tickets WHERE ticket_id = $1", ticket_id)
    return r[0] if r else None


def window(w: Where, tz: str, period, from_, to) -> tuple[str, str] | str:
    try:
        s, e, f, t = period_bounds(period, from_, to, tz)
    except ValueError as ex:
        return str(ex)
    w.add("l.occurred_at >= {}", s).add("l.occurred_at < {}", e)
    return f, t


@mcp.tool(name="ticket_history", annotations={"title": "A ticket's timeline", **RO})
async def ticket_history(params: HistoryIn) -> str:
    """A ticket's timeline in words and facts: created, replied, noted, status from → to with the code, assigned old → new, priority old → new, tags, links,
    merges, the rule that fired, who was dispatched, SLA warnings — oldest or newest first. By ticket_id or number. You must see the ticket; internal events
    (notes, rules, dispatches) are the queue's."""
    if params.ticket_id is None and not params.number:
        return err("Give ticket_id or number.")
    if params.ticket_id is not None:
        t = await ticket_ok(params.ticket_id)
    else:
        r = await fetch("SELECT ticket_id, number, subject, queue_id FROM mcp_tickets WHERE number = $1", number_of(params.number))
        t = r[0] if r else None
    if t is None:
        return err("No such ticket among those you may see.")
    internals = (await fetch("SELECT hd_can_see_internals($1) AS ok", t["ticket_id"]))[0]["ok"]
    w = Where().add("l.ticket_id = {}", t["ticket_id"]).raw("l.action <> 'screen.view'")
    if not internals:
        w.raw("l.action NOT IN ('ticket.note', 'rule.fire', 'ticket.dispatch', 'ticket.sla_warning', 'ticket.sla_breach', 'article.suggest', 'ticket.log_time', 'ticket.time_delete', 'ticket.follow', 'ticket.unfollow')")
    tz = await caller_tz(fetch)
    order = "DESC" if params.order == "newest" else "ASC"
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at {order}, l.activity_id {order} LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps({"ticket": {"ticket_id": t["ticket_id"], "number": t["number"], "subject": t["subject"]}, "timezone": tz, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="who_touched", annotations={"title": "Who touched a ticket", **RO})
async def who_touched(params: TouchedIn) -> str:
    """Who last touched a ticket and what they changed; or everyone who touched it, with the first and last time. You must see the ticket."""
    t = await ticket_ok(params.ticket_id)
    if t is None:
        return err("No such ticket among those you may see.")
    tz = await caller_tz(fetch)
    last = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE l.ticket_id = $1 AND l.action <> 'screen.view' ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT 1", t["ticket_id"])
    people = await fetch(f"""SELECT l.actor_member_id, l.actor_name, (l.actor_requester_id IS NOT NULL) AS is_requester, count(*) AS actions, min(l.occurred_at) AS first_at, max(l.occurred_at) AS last_at
         FROM mcp_activity_log l WHERE l.ticket_id = $1 AND l.action <> 'screen.view' GROUP BY 1, 2, 3 ORDER BY last_at DESC LIMIT {lim(params.limit, 50, 200)}""", t["ticket_id"])
    return dumps({"ticket": {"ticket_id": t["ticket_id"], "number": t["number"]}, "timezone": tz, "last": localize(last, tz, "occurred_at")[0] if last else None, "everyone": localize(people, tz, "first_at", "last_at")})


@mcp.tool(name="actor_timeline", annotations={"title": "What a person or agent did", **RO})
async def actor_timeline(params: TimelineIn) -> str:
    """What a person, an agent or a requester did over a period — including which tickets they looked at and did not act on (screen.view with a ticket) —
    and, with actor_kind = agent, what every agent did yesterday. Yourself by default; others' rows only where you hold the queue (or tickets.view_all)."""
    tz = await caller_tz(fetch)
    w = Where()
    res = window(w, tz, params.period, params.from_, params.to)
    if isinstance(res, str):
        return err(res)
    me = (await fetch("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m"))[0]["m"]
    if params.actor_kind in ("human", "agent"):
        w.raw(f"l.actor_member_id IN (SELECT member_id FROM mcp_members WHERE member_kind = {w.p(params.actor_kind)})")
    else:
        w.add("l.actor_member_id = {}", params.member_id if params.member_id is not None else me)
    if params.action:
        w.add("l.action LIKE {}", params.action.replace("%", "").replace("_", "\\_") + ("%" if params.action.endswith(".") else ""))
    if params.ticket_id is not None:
        w.add("l.ticket_id = {}", params.ticket_id)
    rows = await fetch(f"SELECT {COLS}, t.number AS ticket_number FROM mcp_activity_log l LEFT JOIN mcp_tickets t ON t.ticket_id = l.ticket_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    by = await fetch(f"SELECT l.action, count(*) AS n FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1 ORDER BY n DESC, 1 LIMIT 40", *w.args)
    return dumps({"timezone": tz, "period": {"from": res[0], "to": res[1]}, "by_action": by, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="queue_activity", annotations={"title": "A queue's activity feed", **RO})
async def queue_activity(params: QueueActivityIn) -> str:
    """A queue's activity feed for a period: the actions, by kind and by person, with counts (the lead's "what happened in IT this week"). Queue holders."""
    if not await has_right(fetch, "tickets.view_queue"):
        return err("This needs a queue's tickets.view_queue right.")
    tz = await caller_tz(fetch)
    w = Where().add("l.queue_id = {}", params.queue_id).raw("l.action <> 'screen.view'")
    res = window(w, tz, params.period, params.from_, params.to)
    if isinstance(res, str):
        return err(res)
    if params.action:
        w.add("l.action LIKE {}", params.action.replace("%", "").replace("_", "\\_") + ("%" if params.action.endswith(".") else ""))
    by_action = await fetch(f"SELECT l.action, count(*) AS n FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1 ORDER BY n DESC, 1", *w.args)
    by_person = await fetch(f"SELECT l.actor_member_id, l.actor_name, count(*) AS n FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1, 2 ORDER BY n DESC, 2 LIMIT 50", *w.args)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps({"queue_id": params.queue_id, "timezone": tz, "period": {"from": res[0], "to": res[1]}, "by_action": by_action, "by_person": by_person, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="config_change_log", annotations={"title": "What configuration changed", **RO})
async def config_change_log(params: ConfigLogIn) -> str:
    """What configuration changed in a period and by whom: queues, statuses, transitions, policies, hours, fields, rules, mailboxes, settings — before and after.
    Admins (settings.manage); a queue's holder the queue's own rows."""
    tz = await caller_tz(fetch)
    w = Where()
    res = window(w, tz, params.period, params.from_, params.to)
    if isinstance(res, str):
        return err(res)
    w.raw("(" + " OR ".join(f"l.action LIKE {w.p(p + ('%' if p.endswith('.') else ''))}" for p in CONFIG_PREFIXES) + ")")
    if params.entity_type:
        w.add("l.entity_type = {}", params.entity_type)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps({"timezone": tz, "period": {"from": res[0], "to": res[1]}, "changes": localize(rows, tz, "occurred_at")})


@mcp.tool(name="activity_search", annotations={"title": "Activity search (SQL)", **RO})
async def activity_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over mcp_activity_log (activity_id, occurred_at, actor_member_id, actor_name, actor_requester_id, source, action,
    entity_type, entity_id, ticket_id, queue_id, before, after, agent_run_id, request_id) and the fact views mcp_members, mcp_tickets, mcp_queues. Already
    scoped to what you did, your queues' rows and your own tickets'. One statement, 5 s timeout, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
