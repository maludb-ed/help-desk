"""Help Desk — the vocabulary, a ticket's legal moves, saved views and the board (tool surface, "The vocabulary" and the two view tools).
Reads mcp_ticket_statuses, mcp_status_transitions, mcp_ticket_types, mcp_categories, mcp_tags, mcp_custom_field_defs, mcp_saved_views, mcp_tickets."""
from __future__ import annotations

from pydantic import Field

from hd_common import RO, SORTS, TICKET_COLS, Where, _Base, caller_tz, dumps, err, lim, localize, q_alone, ticket_where, word_start, words


class VocabIn(_Base):
    q: str | None = Field(None, description="Resolve a status, category or type by name (word start). `q` alone answers a plain list of rows with status_id/category_id/type_id and a label")
    part: str | None = Field(None, description="Only one part: statuses, transitions, types, categories, tags, fields")


class TransitionsIn(_Base):
    ticket_id: int


class FindViewsIn(_Base):
    q: str | None = Field(None, description="A view's name. `q` alone answers a plain list (view_id, name)")
    layout: str | None = Field(None, description="list or board")
    limit: int = Field(25, ge=1, le=100)


class BoardIn(_Base):
    view_id: int | None = None
    system_key: str | None = Field(None, description="e.g. team_board")
    swimlane: str | None = Field(None, description="priority, queue, assignee, type or organization — overrides the view's")
    limit: int = Field(200, ge=1, le=500, description="Cards in all")


HOLD_REASONS = ["awaiting_requester", "awaiting_third_party", "awaiting_internal", "awaiting_problem"]
RESOLUTION_CODES = ["resolved", "workaround", "no_action", "duplicate", "spam", "cannot_reproduce"]
CHANNELS = ["web", "email", "form", "agent", "api", "phone", "chat", "assistant"]
COLUMN_FIELDS = {"status": "t.status_label", "priority": "t.priority", "assignee": "COALESCE(t.assignee_name, 'Unassigned')", "queue": "t.queue_name", "type": "COALESCE(t.type, 'No type')"}
LANE_FIELDS = {**COLUMN_FIELDS, "organization": "COALESCE(t.organization_name, 'No organization')"}


def register(mcp, fetch) -> None:
    @mcp.tool(name="get_vocabulary", annotations={"title": "Every legal value", **RO})
    async def get_vocabulary(params: VocabIn) -> str:
        """Every legal value at once: statuses with category and whether the clock runs, the transitions table, types, categories with path and routing
        defaults, tags, custom field definitions, priorities, hold reasons, resolution codes, channels. Call before setting any field. `q` alone resolves a
        status, category or type by name (a plain list). A requester-only caller sees the requester-visible categories and fields."""
        if q_alone(params):
            out: list[dict] = []
            ws = [word_start(x) for x in words(params.q)]

            def match(col: str, w: Where) -> None:
                for x in ws:
                    w.add(f"{col} ~* {{}}", x)

            w = Where().raw("archived_at IS NULL"); match("label", w)
            out += [{**r, "label": r["label"]} for r in await fetch(f"SELECT status_id, key, label, category FROM mcp_ticket_statuses WHERE {w.sql()} ORDER BY sort_order LIMIT 10", *w.args)]
            w = Where().raw("archived_at IS NULL"); match("path", w)
            out += await fetch(f"SELECT category_id, path, name FROM mcp_categories WHERE {w.sql()} ORDER BY path LIMIT 10", *w.args)
            w = Where().raw("archived_at IS NULL"); match("label", w)
            out += await fetch(f"SELECT type_id, key, label FROM mcp_ticket_types WHERE {w.sql()} ORDER BY sort_order LIMIT 10", *w.args)
            return dumps(out)
        part = params.part
        res: dict = {}
        if part in (None, "statuses"):
            res["statuses"] = await fetch("SELECT status_id, key, label, category, color, is_default, on_board, clock_runs FROM mcp_ticket_statuses WHERE archived_at IS NULL ORDER BY sort_order")
        if part in (None, "transitions"):
            res["transitions"] = await fetch("SELECT from_category, to_category, requires, may_carry, needs_right, label FROM mcp_status_transitions ORDER BY from_category, to_category")
        if part in (None, "types"):
            res["types"] = await fetch("SELECT type_id, key, label, description FROM mcp_ticket_types WHERE archived_at IS NULL ORDER BY sort_order")
        if part in (None, "categories"):
            res["categories"] = await fetch("SELECT category_id, parent_id, name, path, description, default_queue_id, default_type_id, default_priority, sla_policy_id, requester_visible FROM mcp_categories WHERE archived_at IS NULL ORDER BY path")
        if part in (None, "tags"):
            res["tags"] = await fetch("SELECT tag_id, slug, label, usage_count FROM mcp_tags ORDER BY usage_count DESC, slug LIMIT 200")
        if part in (None, "fields"):
            res["fields"] = await fetch("SELECT field_id, key, label, field_type, options, applies_to, type_ids, required_on_solve, requester_visible FROM mcp_custom_field_defs WHERE archived_at IS NULL ORDER BY sort_order")
        if part is None:
            res.update({"priorities": ["low", "normal", "high", "urgent"], "hold_reasons": HOLD_REASONS, "resolution_codes": RESOLUTION_CODES, "channels": CHANNELS})
        return dumps(res)

    @mcp.tool(name="ticket_transitions", annotations={"title": "What this ticket may move to", **RO})
    async def ticket_transitions(params: TransitionsIn) -> str:
        """What THIS ticket may move to from where it is, each with `requires` (resolution_code, hold_reason), `may_carry` (pending_until, assignee) and the
        right it needs — and whether you may make each move (`may`). Call before ticket_transition. You must see the ticket."""
        t = await fetch("SELECT t.ticket_id, t.number, t.status_id, t.status, t.status_category FROM mcp_tickets t WHERE t.ticket_id = $1", params.ticket_id)
        if not t:
            return err("No such ticket among those you may see.")
        t = t[0]
        rows = await fetch("""SELECT s.status_id, s.key, s.label, s.category, x.requires, x.may_carry, x.needs_right, x.label AS move_label,
                  (SELECT hd_has_right(x.needs_right)) AND (SELECT hd_can_work_ticket($1)) AS may
             FROM mcp_ticket_statuses s CROSS JOIN LATERAL hd_transition($2, s.status_id) x WHERE s.archived_at IS NULL ORDER BY s.sort_order""", t["ticket_id"], t["status_id"])
        return dumps({"ticket_id": t["ticket_id"], "number": t["number"], "from": {"status": t["status"], "category": t["status_category"]}, "moves": rows})

    @mcp.tool(name="find_views", annotations={"title": "Saved views and boards", **RO})
    async def find_views(params: FindViewsIn) -> str:
        """The saved views and boards you may open: the standard ones, your own, your queues', with layout and filters. Call before board_move_card.
        `q` alone answers a plain list (view_id, name)."""
        w = Where()
        for x in words(params.q):
            w.add("v.name ~* {}", word_start(x))
        if params.layout in ("list", "board"):
            w.add("v.layout = {}", params.layout)
        rows = await fetch(f"SELECT v.view_id, v.name, v.scope, v.queue_id, v.owner_member_id, v.filters, v.sort_by, v.sort_dir, v.columns, v.layout, v.board, v.is_system, v.system_key FROM mcp_saved_views v WHERE {w.sql()} ORDER BY v.sort_order, lower(v.name) LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return dumps([{"view_id": r["view_id"], "name": r["name"]} for r in rows])
        return dumps({"views": rows})

    @mcp.tool(name="board_snapshot", annotations={"title": "A board as the board renders it", **RO})
    async def board_snapshot(params: BoardIn) -> str:
        """A saved view rendered as the board renders it: the columns (status by default; priority, assignee, queue or type) in order with WIP limit and count,
        the cards in rank order with SLA strip, needs-reply, assignee, tags. By view_id or system_key (team_board). Rows follow your own reach."""
        if params.view_id is None and not params.system_key:
            return err("Give view_id or system_key (find_views lists them).")
        w = Where()
        if params.view_id is not None:
            w.add("v.view_id = {}", params.view_id)
        else:
            w.add("v.system_key = {}", params.system_key)
        vs = await fetch(f"SELECT v.view_id, v.name, v.filters, v.board, v.layout FROM mcp_saved_views v WHERE {w.sql()} LIMIT 1", *w.args)
        if not vs:
            return err("No such view among those you may open — call find_views.")
        v = vs[0]
        b = v["board"] or {}
        colf = b.get("column_field") if b.get("column_field") in COLUMN_FIELDS else "status"
        lane = params.swimlane if params.swimlane in LANE_FIELDS else (b.get("swimlane") if b.get("swimlane") in LANE_FIELDS else None)
        me = (await fetch("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m"))[0]["m"]
        tw = ticket_where(v["filters"] or {}, Where(), me)
        if colf == "status":
            n = tw.p(int(b.get("solved_days", 3)) if str(b.get("solved_days", 3)).isdigit() else 3)
            tw.raw(f"t.status_category <> 'closed' AND (t.status_category <> 'solved' OR t.solved_at IS NULL OR t.solved_at >= now() - make_interval(days => {n}))")
        cards = await fetch(f"""SELECT {TICKET_COLS}, t.board_position, {COLUMN_FIELDS[colf]} AS col, {LANE_FIELDS[lane] if lane else "NULL::text"} AS lane
             FROM mcp_tickets t WHERE {tw.sql()} ORDER BY t.board_position ASC NULLS LAST, t.last_activity_at DESC, t.ticket_id DESC LIMIT {lim(params.limit, 200, 500)}""", *tw.args)
        if colf == "status":
            cols = [r["label"] for r in await fetch("SELECT label FROM mcp_ticket_statuses WHERE archived_at IS NULL AND on_board AND category NOT IN ('closed') ORDER BY sort_order")]
        elif colf == "priority":
            cols = ["urgent", "high", "normal", "low"]
        else:
            cols = sorted({c["col"] for c in cards})
        wip = {str(k): int(n) for k, n in (b.get("wip") or {}).items() if int(n) > 0}
        out_cols = []
        for c in cols:
            mine = [x for x in cards if x["col"] == c]
            out_cols.append({"column": c, "count": len(mine), "wip_limit": wip.get(c), "over_wip": bool(wip.get(c) and len(mine) > wip[c]), "cards": mine})
        return dumps({"view_id": v["view_id"], "name": v["name"], "column_field": colf, "swimlane": lane, "columns": out_cols, "cards": len(cards)})
