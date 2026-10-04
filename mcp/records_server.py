"""helpdesk_records_mcp — Help Desk's record-memory server (PostgreSQL, the mcp_* views, read-only).

Tool surface: docs/helpdesk-mcp-tool-surface.md. Every tool reads the views as the asking member, so a person sees what they see on the screens:
their own tickets, the queues they hold, everything with tickets.view_all — and the internals (notes, SLA, time) only as a queue's holder. An agent
sees only the tools the kernel granted it (server_common). The kernel's own token reaches app_roles alone.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP

import db
from hd_common import RO, dumps
from hd_misc import SearchIn

mcp = FastMCP("helpdesk_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


@mcp.tool(name="records_search", annotations={"title": "Records search (SQL)", **RO})
async def records_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over Help Desk's views — tickets (mcp_tickets: ticket_id, number, subject, requester_*, queue_*, assignee_*,
    status, status_category, priority, needs_reply, sla_state, sla_next_due_at, reopen_count, first_response_at, solved_at, created_at …), messages
    (mcp_messages), attachments (mcp_attachments), followers and links (mcp_ticket_followers, mcp_ticket_links, mcp_ticket_viewers), SLA (mcp_sla_targets,
    mcp_sla_policies, mcp_business_hours), time (mcp_time_entries), people (mcp_members, mcp_requesters, mcp_organizations, mcp_departments), queues
    (mcp_queues, mcp_queue_members, mcp_queue_presence), the vocabulary (mcp_ticket_statuses, mcp_status_transitions, mcp_ticket_types, mcp_categories,
    mcp_tags, mcp_custom_field_defs), views and canned (mcp_saved_views, mcp_canned_responses), knowledge (mcp_articles, mcp_kb_sections,
    mcp_article_links), automation (mcp_automation_rules, mcp_rule_runs), mail and agents (mcp_mailboxes, mcp_inbound_email, mcp_agent_dispatches),
    ratings (mcp_satisfaction_ratings, mcp_csat_settings), mcp_notifications, mcp_my_tokens. Already scoped to what you may see. Times are UTC
    (timestamptz). One statement, 5 s timeout, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


@mcp.tool(name="app_roles", annotations={"title": "Help Desk's roles and rights", **RO})
async def app_roles() -> str:
    """The roles Help Desk offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1,
    maludb-os-integration 0.4.x). The catalogue is not about anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM hd_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


import hd_misc
import hd_people
import hd_tickets
import hd_vocab

hd_people.register(mcp, fetch)
hd_vocab.register(mcp, fetch)
hd_tickets.register(mcp, fetch)
hd_misc.register(mcp, fetch)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
