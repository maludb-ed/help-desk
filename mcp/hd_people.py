"""Help Desk — people, organizations, queues (tool surface, "People, organizations, queues"). Reads mcp_members, mcp_queue_members, mcp_requesters,
mcp_organizations, mcp_queues, mcp_queue_presence, mcp_tickets. The views decide the rows: a member sees names, kind and roles but no address but
their own; a requester-only caller sees their own requester row; queue holders see everyone's address and phone."""
from __future__ import annotations

from pydantic import Field

from hd_common import OPEN_CATEGORIES, RO, Where, _Base, caller_tz, dumps, err, has_right, lim, localize, q_alone, word_start, words


class FindMembersIn(_Base):
    q: str | None = Field(None, description="Part of a name, matched at the start of a word. `q` alone answers a plain list (member_id, display_name)")
    kind: str | None = Field(None, description="human or agent")
    queue_id: int | None = Field(None, description="Only the members of this queue")
    limit: int = Field(25, ge=1, le=100)


class FindRequestersIn(_Base):
    q: str | None = Field(None, description="Name, email or organization (word start). `q` alone answers a plain list (requester_id, display_name)")
    organization_id: int | None = None
    kind: str | None = Field(None, description="member, external or agent")
    limit: int = Field(25, ge=1, le=100)


class RequesterHistoryIn(_Base):
    requester_id: int | None = None
    email: str | None = Field(None, description="Instead of requester_id (queue holders; an address is not shown to anyone else)")
    limit: int = Field(25, ge=1, le=100)


class FindOrganizationsIn(_Base):
    q: str | None = Field(None, description="Name or domain. `q` alone answers a plain list (organization_id, name)")
    organization_id: int | None = Field(None, description="One organization in full")
    limit: int = Field(25, ge=1, le=100)


class FindQueuesIn(_Base):
    q: str | None = Field(None, description="Name (word start). `q` alone answers a plain list (queue_id, name)")
    mine: bool | None = Field(None, description="Only the queues you hold")
    include_members: bool = Field(False, description="Add each queue's members (queue holders)")


class AgentWorkloadIn(_Base):
    queue_id: int | None = None
    include_agents: bool = Field(True, description="Include OS agents (default true)")
    limit: int = Field(50, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_members", annotations={"title": "Find people and agents", **RO})
    async def find_members(params: FindMembersIn) -> str:
        """Look up a person or an agent by name — support staff, a lead, an agent — to assign, follow or mention. Call before ticket_assign to a
        person. `q` alone answers a plain list (member_id, display_name). Names, kind, roles and departments; an address only for yourself."""
        w = Where().raw("m.status = 'active'")
        for word in words(params.q):
            w.add("m.display_name ~* {}", word_start(word))
        if params.kind in ("human", "agent"):
            w.add("m.member_kind = {}", params.kind)
        if params.queue_id is not None:
            w.add("m.member_id IN (SELECT member_id FROM mcp_queue_members WHERE queue_id = {})", params.queue_id)
        rows = await fetch(f"SELECT m.member_id, m.display_name, m.member_kind, m.job_title, m.roles, m.timezone, m.email FROM mcp_members m WHERE {w.sql()} ORDER BY lower(m.display_name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"member_id": r["member_id"], "display_name": r["display_name"]} for r in rows])
        return dumps({"members": rows})

    @mcp.tool(name="find_requesters", annotations={"title": "Find requesters", **RO})
    async def find_requesters(params: FindRequestersIn) -> str:
        """Who a requester is: by name, email or organization; an external, a member, an agent. Call before ticket_create on behalf of someone.
        `q` alone answers a plain list (requester_id, display_name). You see your own row; a queue holder sees every requester with address and phone."""
        w = Where().raw("r.merged_into_id IS NULL")
        for word in words(params.q):
            n = w.p(word_start(word))
            w.raw(f"(r.display_name ~* {n} OR r.email ~* {n} OR r.organization_name ~* {n})")
        if params.organization_id is not None:
            w.add("r.organization_id = {}", params.organization_id)
        if params.kind in ("member", "external", "agent"):
            w.add("r.kind = {}", params.kind)
        rows = await fetch(f"SELECT r.requester_id, r.kind, r.display_name, r.organization_id, r.organization_name, r.email, r.phone, r.ticket_count, r.last_seen_at FROM mcp_requesters r WHERE {w.sql()} ORDER BY lower(r.display_name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"requester_id": r["requester_id"], "display_name": r["display_name"]} for r in rows])
        return dumps({"requesters": rows})

    @mcp.tool(name="requester_history", annotations={"title": "What a requester asked before", **RO})
    async def requester_history(params: RequesterHistoryIn) -> str:
        """What a requester asked before: their tickets (newest first) with status and outcome, their organization, how many are open, and their
        ratings. By requester_id or (queue holders) email. You see your own, or those of the queues you hold."""
        if params.requester_id is None and not params.email:
            return err("Give requester_id or email.")
        w = Where()
        if params.requester_id is not None:
            w.add("r.requester_id = {}", params.requester_id)
        else:
            w.add("lower(r.email) = {}", params.email.strip().lower())
        reqs = await fetch(f"SELECT r.requester_id, r.kind, r.display_name, r.organization_id, r.organization_name, r.email, r.phone, r.ticket_count FROM mcp_requesters r WHERE {w.sql()} LIMIT 1", *w.args)
        if not reqs:
            return err("No such requester among those you may see — call find_requesters.")
        r = reqs[0]
        tz = await caller_tz(fetch)
        tickets = await fetch(f"""SELECT t.ticket_id, t.number, t.subject, t.status, t.status_label, t.status_category, t.priority, t.resolution_code, t.queue_name, t.created_at, t.solved_at,
                  (SELECT s.score FROM mcp_satisfaction_ratings s WHERE s.ticket_id = t.ticket_id AND s.responded_at IS NOT NULL LIMIT 1) AS rating
             FROM mcp_tickets t WHERE t.requester_id = $1 AND t.merged_into_id IS NULL ORDER BY t.created_at DESC LIMIT {lim(params.limit)}""", r["requester_id"])
        open_n = await fetch("SELECT count(*) AS n FROM mcp_tickets t WHERE t.requester_id = $1 AND t.merged_into_id IS NULL AND t.status_category = ANY($2)", r["requester_id"], list(OPEN_CATEGORIES))
        return dumps({"requester": r, "open_tickets": open_n[0]["n"], "timezone": tz, "tickets": localize(tickets, tz, "created_at", "solved_at")})

    @mcp.tool(name="find_organizations", annotations={"title": "Find organizations", **RO})
    async def find_organizations(params: FindOrganizationsIn) -> str:
        """The customer companies: by name or domain, with tier, SLA override, domains and open ticket count; or one in full by organization_id.
        `q` alone answers a plain list (organization_id, name). Queue holders see them all; a member of an organization sees their own."""
        w = Where().raw("o.active")
        if params.organization_id is not None:
            w.add("o.organization_id = {}", params.organization_id)
        for word in words(params.q):
            n = w.p(word_start(word))
            w.raw(f"(o.name ~* {n} OR EXISTS (SELECT 1 FROM unnest(o.domains) d WHERE d ~* {n}))")
        rows = await fetch(f"""SELECT o.organization_id, o.name, o.support_tier, o.tags, o.sla_policy_id, o.members_see_each_other, o.domains, o.notes, o.custom_values,
                  (SELECT count(*) FROM mcp_tickets t WHERE t.organization_id = o.organization_id AND t.status_category = ANY(${len(w.args) + 1})) AS open_tickets
             FROM mcp_organizations o WHERE {w.sql()} ORDER BY lower(o.name) LIMIT {lim(params.limit)}""", *w.args, list(OPEN_CATEGORIES))
        if q_alone(params):
            return dumps([{"organization_id": r["organization_id"], "name": r["name"]} for r in rows])
        return dumps({"organizations": rows})

    @mcp.tool(name="find_queues", annotations={"title": "Find queues", **RO})
    async def find_queues(params: FindQueuesIn) -> str:
        """The queues, each with its department, assignment method, default assignee (a person or an agent), schedule and policy, open count,
        whether you hold it and lead it. Call before ticket_assign to a queue. `q` alone answers a plain list (queue_id, name)."""
        w = Where().raw("q.archived_at IS NULL")
        for word in words(params.q):
            w.add("q.name ~* {}", word_start(word))
        if params.mine:
            w.raw("q.mine")
        rows = await fetch(f"""SELECT q.queue_id, q.key, q.name, q.description, q.department_id, q.department_name, q.include_department, q.assignment_method, q.default_assignee_member_id,
                  (SELECT m.display_name FROM mcp_members m WHERE m.member_id = q.default_assignee_member_id) AS default_assignee_name,
                  (SELECT m.member_kind FROM mcp_members m WHERE m.member_id = q.default_assignee_member_id) AS default_assignee_kind,
                  q.business_hours_id, q.sla_policy_id, q.email_address, q.open_count, q.mine, q.i_lead
             FROM mcp_queues q WHERE {w.sql()} ORDER BY q.sort_order, lower(q.name)""", *w.args)
        if q_alone(params):
            return dumps([{"queue_id": r["queue_id"], "name": r["name"]} for r in rows])
        if params.include_members:
            ids = [r["queue_id"] for r in rows]
            mem = await fetch("SELECT queue_id, member_id, display_name, member_kind, is_lead, how FROM mcp_queue_members WHERE queue_id = ANY($1) ORDER BY is_lead DESC, lower(display_name)", ids)
            for r in rows:
                r["members"] = [m for m in mem if m["queue_id"] == r["queue_id"]]
        return dumps({"queues": rows})

    @mcp.tool(name="agent_workload", annotations={"title": "Who holds how much", **RO})
    async def agent_workload(params: AgentWorkloadIn) -> str:
        """Who holds how many open tickets per queue, by priority and SLA state, against their capacity and availability — who is overloaded, who has
        room. Queue holders; a lead across queues."""
        if not await has_right(fetch, "tickets.view_queue"):
            return err("This needs a queue's tickets.view_queue right.")
        w = Where().raw("t.merged_into_id IS NULL").raw("t.assignee_member_id IS NOT NULL").add("t.status_category = ANY({})", list(OPEN_CATEGORIES))
        if params.queue_id is not None:
            w.add("t.queue_id = {}", params.queue_id)
        if not params.include_agents:
            w.raw("t.assignee_kind <> 'agent'")
        rows = await fetch(f"""SELECT t.assignee_member_id AS member_id, t.assignee_name AS display_name, t.assignee_kind AS kind, t.queue_id, t.queue_name,
                  count(*) AS open, count(*) FILTER (WHERE t.priority = 'urgent') AS urgent, count(*) FILTER (WHERE t.priority = 'high') AS high,
                  count(*) FILTER (WHERE t.sla_state = 'at_risk') AS at_risk, count(*) FILTER (WHERE t.sla_state = 'breached') AS breached, count(*) FILTER (WHERE t.needs_reply) AS needs_reply
             FROM mcp_tickets t WHERE {w.sql()} GROUP BY 1, 2, 3, 4, 5 ORDER BY open DESC, 2 LIMIT {lim(params.limit, 50)}""", *w.args)
        pres = {r["member_id"]: r for r in await fetch("SELECT member_id, availability, capacity FROM mcp_queue_presence")}
        totals: dict[int, int] = {}
        for r in rows:
            totals[r["member_id"]] = totals.get(r["member_id"], 0) + r["open"]
        for r in rows:
            p = pres.get(r["member_id"])
            r["availability"] = p["availability"] if p else None
            r["capacity"] = p["capacity"] if p else None
            r["over_capacity"] = bool(p and p["capacity"] and totals[r["member_id"]] > p["capacity"])
        return dumps({"workload": rows})
