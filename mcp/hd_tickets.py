"""Help Desk — tickets (tool surface, "Tickets"). Everything reads mcp_tickets and its satellites; the view decides the rows — your own tickets,
the ones in queues you hold, all with tickets.view_all — and the internals (notes, SLA targets, time, followers' details) only for queue holders."""
from __future__ import annotations

from pydantic import Field

from hd_common import (NEEDS_QUEUE, OPEN_CATEGORIES, RO, SORTS, TICKET_COLS, Where, _Base, caller_tz, dumps, err, has_right, lim, localize, period_bounds, q_alone, ticket_where, to_ts, words)

LOCAL_FIELDS = ("created_at", "last_activity_at", "sla_next_due_at")


class FindTicketsIn(_Base):
    q: str | None = Field(None, description="Words in the subject or requester, or a number (HD-1042). `q` alone answers a plain list (ticket_id, label)")
    status_category: list[str] | None = Field(None, description="new, open, pending, on_hold, solved, closed")
    status: str | None = Field(None, description="A status key")
    queue_id: int | None = None
    assignee: str | None = Field(None, description="A member id, 'me' or 'none'")
    requester_id: int | None = None
    organization_id: int | None = None
    priority: list[str] | None = None
    type_id: int | None = None
    category_id: int | None = None
    tag: str | None = None
    needs_reply: bool | None = None
    sla_state: str | None = Field(None, description="at_risk, breached, paused, on_track")
    channel: str | None = None
    created_from: str | None = Field(None, description="ISO date or date-time")
    created_to: str | None = None
    solved_from: str | None = None
    solved_to: str | None = None
    reopened: bool | None = None
    sort: str | None = Field(None, description="last_activity (default), created, oldest, priority, sla_due, number")
    limit: int = Field(25, ge=1, le=100)


class TicketRef(_Base):
    ticket_id: int | None = None
    number: str | None = Field(None, description="HD-1042 (or the digits)")


class ConversationIn(_Base):
    ticket_id: int
    kind: str | None = Field(None, description="public_reply or internal_note")
    since: str | None = Field(None, description="ISO date-time: only messages after it")
    limit: int = Field(50, ge=1, le=100)


class OnlyTicketIn(_Base):
    ticket_id: int


class MyQueueIn(_Base):
    include_unassigned: bool = True
    limit: int = Field(25, ge=1, le=100)


class QueueLimitIn(_Base):
    queue_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class SlaRiskIn(_Base):
    queue_id: int | None = None
    assignee: str | None = Field(None, description="'me' or a member id")
    state: str = Field("both", description="at_risk, breached or both")
    within_minutes: int | None = Field(None, ge=1, description="Only tickets due within this many minutes")
    limit: int = Field(25, ge=1, le=100)


class StaleIn(_Base):
    days: int = Field(7, ge=1, le=365)
    queue_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class SimilarIn(_Base):
    ticket_id: int | None = None
    text: str | None = None
    open_only: bool = False
    limit: int = Field(10, ge=1, le=50)


class ChangedIn(_Base):
    since: str = Field(..., description="ISO date-time")
    queue_id: int | None = None
    limit: int = Field(50, ge=1, le=100)


COUNT_GROUPS = {
    "status": "t.status_label", "status_category": "t.status_category", "priority": "t.priority", "queue": "t.queue_name", "assignee": "COALESCE(t.assignee_name, 'Unassigned')",
    "type": "COALESCE(t.type, 'No type')", "category": "COALESCE(t.category_name, 'No category')", "organization": "COALESCE(t.organization_name, 'No organization')",
    "channel": "t.channel", "sla_state": "COALESCE(t.sla_state, 'none')",
}


class CountsIn(_Base):
    group_by: str = Field(..., description="status, status_category, priority, queue, assignee, type, category, organization, channel or sla_state")
    queue_id: int | None = None
    assignee: str | None = None
    period: str | None = Field(None, description="today, week, month, last_week, last_month, last_7_days, last_30_days — by created date; or from/to")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    open_only: bool = False


class AgingIn(_Base):
    queue_id: int | None = None


async def me_id(fetch) -> int | None:
    return (await fetch("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m"))[0]["m"]


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_tickets", annotations={"title": "Find tickets", **RO})
    async def find_tickets(params: FindTicketsIn) -> str:
        """Any list of tickets by words, number, status category or status, queue, assignee (an id, 'me', 'none'), requester, organization, priority, type,
        category, tag, needs-reply, SLA state, channel, dates. Each row: number, subject, requester, queue, assignee (person or agent), status, priority,
        needs_reply, sla_state, next due, tags, last activity. `q` alone answers a plain list (ticket_id, label = "HD-1042 — subject") for the resolver.
        Your own tickets; a queue's holder sees the queue's; tickets.view_all sees all."""
        me = await me_id(fetch)
        f = {"q": params.q, "status_category": params.status_category, "status": params.status, "queue_id": params.queue_id, "assignee": params.assignee,
             "requester_id": params.requester_id, "organization_id": params.organization_id, "priority": params.priority, "type_id": params.type_id, "category_id": params.category_id,
             "tag": params.tag, "needs_reply": params.needs_reply, "sla": params.sla_state, "channel": params.channel, "created_from": params.created_from, "created_to": params.created_to,
             "solved_from": params.solved_from, "solved_to": params.solved_to, "reopened": params.reopened}
        w = ticket_where(f, Where(), me)
        order = SORTS.get(params.sort or "last_activity", SORTS["last_activity"]) + ", t.last_activity_at DESC, t.ticket_id DESC"
        rows = await fetch(f"SELECT {TICKET_COLS} FROM mcp_tickets t WHERE {w.sql()} ORDER BY {order} LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"ticket_id": r["ticket_id"], "label": r["label"]} for r in rows])
        tz = await caller_tz(fetch)
        return dumps({"timezone": tz, "tickets": localize(rows, tz, *LOCAL_FIELDS)})

    @mcp.tool(name="get_ticket", annotations={"title": "One ticket in full", **RO})
    async def get_ticket(params: TicketRef) -> str:
        """One ticket in full: every field, requester with organization, assignee, status with category, SLA targets with state and due (queue holders), tags,
        custom values, followers and CCs, links (parent, children, duplicates, follow-ups), time logged (queue holders), the last message's excerpt, counts, who
        is viewing now. By ticket_id or number. You must see the ticket; the internals are for the queue's holders."""
        w = Where()
        if params.ticket_id is not None:
            w.add("t.ticket_id = {}", params.ticket_id)
        elif params.number:
            n = params.number.strip().upper()
            w.add("t.number = {}", n if not n.isdigit() else "HD-" + n)
        else:
            return err("Give ticket_id or number.")
        rows = await fetch(f"SELECT t.* FROM mcp_tickets t WHERE {w.sql()}", *w.args)
        if not rows:
            return err("No such ticket among those you may see.")
        t = rows[0]
        tid = t["ticket_id"]
        tz = await caller_tz(fetch)
        internals = (await fetch("SELECT hd_can_see_internals($1) AS ok", tid))[0]["ok"]
        req = await fetch("SELECT requester_id, kind, display_name, organization_id, organization_name, email, phone, notes, ticket_count FROM mcp_requesters WHERE requester_id = $1", t["requester_id"])
        out = {"ticket": localize([t], tz, "created_at", "updated_at", "last_activity_at", "sla_next_due_at", "solved_at", "closed_at", "first_response_at")[0], "timezone": tz,
               "requester": req[0] if req else None, "can_see_internals": internals}
        out["followers"] = await fetch("SELECT kind, member_id, member_name, requester_id, requester_name FROM mcp_ticket_followers WHERE ticket_id = $1 ORDER BY created_at", tid)
        out["links"] = await fetch("""SELECT l.link_id, l.kind, l.from_ticket_id, ft.number AS from_number, l.to_ticket_id, l.to_number, l.to_subject
             FROM mcp_ticket_links l JOIN mcp_tickets ft ON ft.ticket_id = l.from_ticket_id WHERE l.from_ticket_id = $1 OR l.to_ticket_id = $1 ORDER BY l.created_at""", tid)
        out["articles"] = await fetch("SELECT article_id, title, kind FROM mcp_article_links WHERE ticket_id = $1", tid)
        last = await fetch("SELECT kind, author_member_name, author_requester_name, left(body_text, 240) AS excerpt, created_at FROM mcp_messages WHERE ticket_id = $1 ORDER BY created_at DESC, message_id DESC LIMIT 1", tid)
        out["last_message"] = localize(last, tz, "created_at")[0] if last else None
        cnt = await fetch("SELECT count(*) AS messages, count(*) FILTER (WHERE kind = 'public_reply') AS public_replies, count(*) FILTER (WHERE kind = 'internal_note') AS notes FROM mcp_messages WHERE ticket_id = $1", tid)
        out["counts"] = cnt[0]
        if internals:
            out["sla_targets"] = localize(await fetch("SELECT metric, policy_name, target_minutes, started_at, due_at, paused_at, met_at, breached_at, state FROM mcp_sla_targets WHERE ticket_id = $1 ORDER BY target_id", tid), tz, "due_at", "met_at")
            te = await fetch("SELECT entry_id, member_id, display_name, minutes, billable, note, worked_on FROM mcp_time_entries WHERE ticket_id = $1 ORDER BY worked_on, entry_id", tid)
            out["time"] = {"entries": te, "minutes": sum(e["minutes"] for e in te), "billable_minutes": sum(e["minutes"] for e in te if e["billable"])}
            out["viewers"] = await fetch("SELECT member_id, display_name, composing, last_seen_at FROM mcp_ticket_viewers WHERE ticket_id = $1", tid)
        return dumps(out)

    @mcp.tool(name="ticket_conversation", annotations={"title": "A ticket's messages", **RO})
    async def ticket_conversation(params: ConversationIn) -> str:
        """The messages of a ticket in order — public replies to whoever sees it, internal notes to the queue — each with author (person, agent or
        requester), channel, attachments (name, size, type). You must see the ticket; notes are the queue's."""
        if not await fetch("SELECT 1 FROM mcp_tickets WHERE ticket_id = $1", params.ticket_id):
            return err("No such ticket among those you may see.")
        w = Where().add("m.ticket_id = {}", params.ticket_id)
        if params.kind in ("public_reply", "internal_note"):
            w.add("m.kind = {}", params.kind)
        if params.since:
            ts = to_ts(params.since)
            if ts is None:
                return err("since is an ISO date or date-time.")
            w.add("m.created_at > {}", ts)
        tz = await caller_tz(fetch)
        msgs = await fetch(f"""SELECT m.message_id, m.kind, m.author_member_id, m.author_member_name, m.author_kind, m.author_requester_id, m.author_requester_name, m.body_text, m.channel, m.mentions, m.created_at
             FROM mcp_messages m WHERE {w.sql()} ORDER BY m.created_at, m.message_id LIMIT {lim(params.limit, 50)}""", *w.args)
        atts = await fetch("SELECT attachment_id, message_id, filename, mime_type, byte_size FROM mcp_attachments WHERE message_id = ANY($1) ORDER BY attachment_id", [m["message_id"] for m in msgs])
        for m in msgs:
            m["attachments"] = [a for a in atts if a["message_id"] == m["message_id"]]
        return dumps({"ticket_id": params.ticket_id, "timezone": tz, "messages": localize(msgs, tz, "created_at")})

    @mcp.tool(name="ticket_viewers", annotations={"title": "Who has it open", **RO})
    async def ticket_viewers(params: OnlyTicketIn) -> str:
        """Who has the ticket open right now and whether they are typing (collision warning). Queue holders."""
        if not await has_right(fetch, "tickets.view_queue") or not await fetch("SELECT 1 FROM mcp_tickets WHERE ticket_id = $1", params.ticket_id):
            return err("No such ticket among those you may see, or no queue right on it.")
        return dumps({"ticket_id": params.ticket_id, "viewers": await fetch("SELECT member_id, display_name, composing, last_seen_at FROM mcp_ticket_viewers WHERE ticket_id = $1 ORDER BY last_seen_at DESC", params.ticket_id)})

    @mcp.tool(name="my_queue", annotations={"title": "What to do next", **RO})
    async def my_queue(params: MyQueueIn) -> str:
        """What you should do next: tickets assigned to you that are open, needs-reply first, then by SLA due; then the unassigned tickets of your queues;
        counts at the top. An agent's duty starts here."""
        me = await me_id(fetch)
        tz = await caller_tz(fetch)
        open_list = list(OPEN_CATEGORIES)
        mine = await fetch(f"""SELECT {TICKET_COLS} FROM mcp_tickets t WHERE t.merged_into_id IS NULL AND t.assignee_member_id = $1 AND t.status_category = ANY($2)
             ORDER BY t.needs_reply DESC, t.sla_next_due_at ASC NULLS LAST, t.last_activity_at DESC LIMIT {lim(params.limit)}""", me, open_list)
        unassigned: list[dict] = []
        n_un = 0
        if params.include_unassigned:
            base = "t.merged_into_id IS NULL AND t.assignee_member_id IS NULL AND t.status_category = ANY($1) AND t.queue_id IN (SELECT queue_id FROM mcp_queues WHERE mine)"
            unassigned = await fetch(f"SELECT {TICKET_COLS} FROM mcp_tickets t WHERE {base} ORDER BY t.sla_next_due_at ASC NULLS LAST, t.created_at LIMIT {lim(params.limit)}", open_list)
            n_un = (await fetch(f"SELECT count(*) AS n FROM mcp_tickets t WHERE {base}", open_list))[0]["n"]
        counts = (await fetch("SELECT count(*) AS assigned_open, count(*) FILTER (WHERE needs_reply) AS needs_reply, count(*) FILTER (WHERE sla_state IN ('at_risk', 'breached')) AS sla_trouble FROM mcp_tickets WHERE merged_into_id IS NULL AND assignee_member_id = $1 AND status_category = ANY($2)", me, open_list))[0]
        counts["unassigned_in_my_queues"] = n_un
        return dumps({"member_id": me, "timezone": tz, "counts": counts, "assigned": localize(mine, tz, *LOCAL_FIELDS), "unassigned": localize(unassigned, tz, *LOCAL_FIELDS)})

    @mcp.tool(name="triage_queue", annotations={"title": "Tickets still new", **RO})
    async def triage_queue(params: QueueLimitIn) -> str:
        """Every ticket still in the `new` category, oldest first, with requester, organization, channel, the first message's excerpt and the requester's other
        open tickets (likely duplicates). Queue holders."""
        if not await has_right(fetch, "tickets.view_queue"):
            return err(NEEDS_QUEUE)
        w = Where().raw("t.merged_into_id IS NULL").raw("t.status_category = 'new'")
        if params.queue_id is not None:
            w.add("t.queue_id = {}", params.queue_id)
        tz = await caller_tz(fetch)
        rows = await fetch(f"""SELECT {TICKET_COLS},
               (SELECT left(m.body_text, 300) FROM mcp_messages m WHERE m.ticket_id = t.ticket_id AND m.kind = 'public_reply' ORDER BY m.created_at, m.message_id LIMIT 1) AS first_message,
               (SELECT coalesce(jsonb_agg(jsonb_build_object('ticket_id', o.ticket_id, 'number', o.number, 'subject', o.subject) ORDER BY o.created_at DESC), '[]'::jsonb)
                  FROM (SELECT o.* FROM mcp_tickets o WHERE o.requester_id = t.requester_id AND o.ticket_id <> t.ticket_id AND o.merged_into_id IS NULL AND o.status_category = ANY(${len(w.args) + 1}) ORDER BY o.created_at DESC LIMIT 3) o) AS same_requester_open
             FROM mcp_tickets t WHERE {w.sql()} ORDER BY t.created_at ASC, t.ticket_id LIMIT {lim(params.limit)}""", *w.args, list(OPEN_CATEGORIES))
        return dumps({"timezone": tz, "tickets": localize(rows, tz, *LOCAL_FIELDS)})

    @mcp.tool(name="sla_at_risk", annotations={"title": "SLA at risk or breached", **RO})
    async def sla_at_risk(params: SlaRiskIn) -> str:
        """Tickets whose SLA is breached or at risk, soonest due first — per queue, per assignee or team-wide — with the metric, due time and minutes left
        (negative when late). Queue holders; tickets.view_all across queues."""
        if not await has_right(fetch, "tickets.view_queue"):
            return err(NEEDS_QUEUE)
        me = await me_id(fetch)
        w = Where().raw("t.merged_into_id IS NULL").add("t.status_category = ANY({})", list(OPEN_CATEGORIES))
        w.add("t.sla_state = ANY({})", ["at_risk", "breached"] if params.state == "both" else [params.state if params.state in ("at_risk", "breached") else "at_risk"])
        if params.queue_id is not None:
            w.add("t.queue_id = {}", params.queue_id)
        if params.assignee == "me":
            w.add("t.assignee_member_id = {}", me)
        elif params.assignee and params.assignee.isdigit():
            w.add("t.assignee_member_id = {}", int(params.assignee))
        if params.within_minutes:
            w.add("t.sla_next_due_at <= now() + make_interval(mins => {})", params.within_minutes)
        tz = await caller_tz(fetch)
        rows = await fetch(f"SELECT {TICKET_COLS}, round(extract(epoch FROM (t.sla_next_due_at - now())) / 60)::int AS minutes_left FROM mcp_tickets t WHERE {w.sql()} ORDER BY t.sla_next_due_at ASC NULLS LAST LIMIT {lim(params.limit)}", *w.args)
        return dumps({"timezone": tz, "tickets": localize(rows, tz, *LOCAL_FIELDS)})

    @mcp.tool(name="stale_tickets", annotations={"title": "No activity for N days", **RO})
    async def stale_tickets(params: StaleIn) -> str:
        """Open tickets with no activity for N days (default 7), oldest first, with who wrote last (the last message's author; the full trail of who did what
        is ticket_history on the activity server). Queue holders; tickets.view_all across queues."""
        if not await has_right(fetch, "tickets.view_queue"):
            return err(NEEDS_QUEUE)
        w = Where().raw("t.merged_into_id IS NULL").add("t.status_category = ANY({})", list(OPEN_CATEGORIES)).add("t.last_activity_at < now() - make_interval(days => {})", params.days)
        if params.queue_id is not None:
            w.add("t.queue_id = {}", params.queue_id)
        tz = await caller_tz(fetch)
        rows = await fetch(f"""SELECT {TICKET_COLS}, lm.kind AS last_message_kind, coalesce(lm.author_member_name, lm.author_requester_name) AS last_message_by, lm.created_at AS last_message_at,
               floor(extract(epoch FROM (now() - t.last_activity_at)) / 86400)::int AS idle_days
             FROM mcp_tickets t LEFT JOIN LATERAL (SELECT m.kind, m.author_member_name, m.author_requester_name, m.created_at FROM mcp_messages m WHERE m.ticket_id = t.ticket_id ORDER BY m.created_at DESC, m.message_id DESC LIMIT 1) lm ON true
             WHERE {w.sql()} ORDER BY t.last_activity_at ASC LIMIT {lim(params.limit)}""", *w.args)
        return dumps({"days": params.days, "timezone": tz, "tickets": localize(rows, tz, *LOCAL_FIELDS, "last_message_at")})

    @mcp.tool(name="similar_tickets", annotations={"title": "Tickets like this one", **RO})
    async def similar_tickets(params: SimilarIn) -> str:
        """Tickets like this one — same requester or organization, matching words in the subject, same category — open ones first (duplicate and problem
        detection). By ticket_id or free text. Queue holders."""
        if not await has_right(fetch, "tickets.view_queue"):
            return err(NEEDS_QUEUE)
        base = None
        if params.ticket_id is not None:
            b = await fetch("SELECT ticket_id, subject, requester_id, organization_id, category_id FROM mcp_tickets WHERE ticket_id = $1", params.ticket_id)
            if not b:
                return err("No such ticket among those you may see.")
            base = b[0]
        elif not params.text:
            return err("Give ticket_id or text.")
        text = base["subject"] if base else params.text
        w = Where().raw("t.merged_into_id IS NULL")
        n = w.p(text)
        score = [f"similarity(t.subject, {n}) * 4"]
        if base:
            w.add("t.ticket_id <> {}", base["ticket_id"])
            score += [f"(CASE WHEN t.requester_id = {w.p(base['requester_id'])} THEN 3 ELSE 0 END)"]
            if base["organization_id"] is not None:
                score += [f"(CASE WHEN t.organization_id = {w.p(base['organization_id'])} THEN 1 ELSE 0 END)"]
            if base["category_id"] is not None:
                score += [f"(CASE WHEN t.category_id = {w.p(base['category_id'])} THEN 1 ELSE 0 END)"]
        if params.open_only:
            w.add("t.status_category = ANY({})", list(OPEN_CATEGORIES))
        w.raw(f"(similarity(t.subject, {n}) > 0.2 OR " + (f"t.requester_id = {w.p(base['requester_id'])}" if base else "FALSE") + ")")
        rows = await fetch(f"""SELECT {TICKET_COLS}, round(({' + '.join(score)})::numeric, 2) AS score FROM mcp_tickets t WHERE {w.sql()}
             ORDER BY (t.status_category = ANY('{{new,open,pending,on_hold}}')) DESC, score DESC, t.created_at DESC LIMIT {lim(params.limit, 10, 50)}""", *w.args)
        return dumps({"tickets": rows})

    @mcp.tool(name="tickets_changed_since", annotations={"title": "What changed since", **RO})
    async def tickets_changed_since(params: ChangedIn) -> str:
        """The cheap delta for a person's own tool: tickets whose updated_at passed a timestamp, with the last message's kind and author (ticket_history on the activity
        server says what changed). Your own; queue holders' queues."""
        ts = to_ts(params.since)
        if ts is None:
            return err("since is an ISO date or date-time.")
        w = Where().raw("t.merged_into_id IS NULL").add("t.updated_at > {}", ts)
        if params.queue_id is not None:
            w.add("t.queue_id = {}", params.queue_id)
        tz = await caller_tz(fetch)
        rows = await fetch(f"""SELECT {TICKET_COLS}, t.updated_at, lm.kind AS last_message_kind, coalesce(lm.author_member_name, lm.author_requester_name) AS last_message_by
             FROM mcp_tickets t LEFT JOIN LATERAL (SELECT m.kind, m.author_member_name, m.author_requester_name FROM mcp_messages m WHERE m.ticket_id = t.ticket_id ORDER BY m.created_at DESC, m.message_id DESC LIMIT 1) lm ON true
             WHERE {w.sql()} ORDER BY t.updated_at DESC LIMIT {lim(params.limit, 50)}""", *w.args)
        return dumps({"since": ts, "timezone": tz, "tickets": localize(rows, tz, *LOCAL_FIELDS, "updated_at")})

    @mcp.tool(name="ticket_counts", annotations={"title": "Counts by a grouping", **RO})
    async def ticket_counts(params: CountsIn) -> str:
        """Counts grouped by status, status category, priority, queue, assignee, type, category, organization, channel or SLA state, for a period (by created
        date) — the dashboard's numbers. Your own counts; queue holders' queues; tickets.view_all everything."""
        if params.group_by not in COUNT_GROUPS:
            return err(f"group_by is one of: {', '.join(COUNT_GROUPS)}.")
        me = await me_id(fetch)
        tz = await caller_tz(fetch)
        w = ticket_where({"queue_id": params.queue_id, "assignee": params.assignee}, Where(), me)
        period = None
        if params.period or params.from_ or params.to:
            try:
                s, e, f, t = period_bounds(params.period, params.from_, params.to, tz)
            except ValueError as ex:
                return err(str(ex))
            w.add("t.created_at >= {}", s).add("t.created_at < {}", e)
            period = {"from": f, "to": t}
        if params.open_only:
            w.add("t.status_category = ANY({})", list(OPEN_CATEGORIES))
        rows = await fetch(f"SELECT {COUNT_GROUPS[params.group_by]} AS \"group\", count(*) AS n FROM mcp_tickets t WHERE {w.sql()} GROUP BY 1 ORDER BY n DESC, 1", *w.args)
        return dumps({"group_by": params.group_by, "period": period, "total": sum(r["n"] for r in rows), "groups": rows})

    @mcp.tool(name="backlog_aging", annotations={"title": "Open tickets by age", **RO})
    async def backlog_aging(params: AgingIn) -> str:
        """Open tickets bucketed by age (today, 1–3 days, 4–7, 8–30, older), per queue and priority. Queue holders; tickets.view_all across queues."""
        if not await has_right(fetch, "tickets.view_queue"):
            return err(NEEDS_QUEUE)
        w = Where().raw("t.merged_into_id IS NULL").add("t.status_category = ANY({})", list(OPEN_CATEGORIES))
        if params.queue_id is not None:
            w.add("t.queue_id = {}", params.queue_id)
        bucket = "CASE WHEN t.created_at >= now() - interval '1 day' THEN 'today' WHEN t.created_at >= now() - interval '3 days' THEN 'd1_3' WHEN t.created_at >= now() - interval '7 days' THEN 'd4_7' WHEN t.created_at >= now() - interval '30 days' THEN 'd8_30' ELSE 'older' END"
        rows = await fetch(f"SELECT t.queue_id, t.queue_name, {bucket} AS bucket, count(*) AS total, count(*) FILTER (WHERE t.priority = 'urgent') AS urgent, count(*) FILTER (WHERE t.priority = 'high') AS high, count(*) FILTER (WHERE t.priority = 'normal') AS normal, count(*) FILTER (WHERE t.priority = 'low') AS low FROM mcp_tickets t WHERE {w.sql()} GROUP BY 1, 2, 3 ORDER BY 2", *w.args)
        order = ["today", "d1_3", "d4_7", "d8_30", "older"]
        qs: dict[int, dict] = {}
        for r in rows:
            q = qs.setdefault(r["queue_id"], {"queue_id": r["queue_id"], "queue_name": r["queue_name"], "buckets": {b: {"total": 0, "urgent": 0, "high": 0, "normal": 0, "low": 0} for b in order}})
            q["buckets"][r["bucket"]] = {k: r[k] for k in ("total", "urgent", "high", "normal", "low")}
        return dumps({"labels": {"today": "Today", "d1_3": "1–3 d", "d4_7": "4–7 d", "d8_30": "8–30 d", "older": "Older"}, "queues": list(qs.values()), "total": sum(r["total"] for r in rows)})
