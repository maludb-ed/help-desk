# Help Desk

A memory-first, ask-me-anything help desk ticketing system for humans and AI agents, built with the htmx-php-builder method on PostgreSQL 17 + MaluDB, Apache / PHP / Bootstrap 5.3 / HTMX, with Python FastMCP servers and a Claude Agent SDK assistant.

## Status

Phase 0 complete (2026-10-02) and Phase 1 written (2026-10-03), awaiting the owner's checkpoint: the OS-fitted design with the
owner's decisions, the schema (`db/001`–`016`, proven by `db/proof/phase0_proof.sql`), the sign-on kit proven without a kernel
(`tests/phase0/run.sh`), `maludb-os.json`, the three agents' job descriptions, six skills, the deploy templates, the MCP tool surface,
the action manifest (65 screens, 96 actions) and eleven build specs in `docs/build-specs/`. No feature screens yet.

Help Desk is an application **of the Business OS** (kernel repo `/var/www`): installed at `/srv/apps/helpdesk`,
served as `helpdesk.<domain>`, signed in by the kernel, worked by people and by the kernel's agents through MCP,
and part of every OS install. `CLAUDE.md` carries the rules.

## Documents

- [docs/helpdesk-design.md](docs/helpdesk-design.md) — **read first**: the design fitted to the Business OS — what changes from the Phase 0 plan, requesters without an OS account, agents filing and working tickets, roles and rights, the memory model, the build order, what the kernel owes, the decisions asked of the owner.
- [docs/helpdesk-mcp-tool-surface.md](docs/helpdesk-mcp-tool-surface.md) and [docs/helpdesk-action-manifest.md](docs/helpdesk-action-manifest.md) — Phase 1: every read tool and every screen and action; `docs/build-specs/` holds the eleven slice specs (`tickets-core.md` is the exemplar).
- [docs/01-phase0-plan.md](docs/01-phase0-plan.md) — actors, memory model, question inventory, action inventory, slice order, kanban design, MCP coverage, checkpoint decisions.
- [docs/research/third-party-help-desk-systems.md](docs/research/third-party-help-desk-systems.md) — comparative research across Zendesk, Freshdesk, Jira Service Management, ServiceNow, Help Scout, osTicket, Zammad, HubSpot, Linear, and Intercom.

## Build order

Phase 0 design and schema → Phase 1 tool surface, action manifest and slice specs (the checkpoint) → Phase 2 sign-on, mirror and shell → Phase 3 ten vertical slices (tickets core the exemplar) → Phase 4 the two MCP servers, `app_roles`, the registry and the three agents → Phase 5 the kernel's installer, DNS, mailboxes, hires and the end-to-end proof. Target host: the kernel's (Ubuntu, PostgreSQL 17, MaluDB).
