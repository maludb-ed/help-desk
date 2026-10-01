# Help Desk

A memory-first, ask-me-anything help desk ticketing system for humans and AI agents, built with the htmx-php-builder method on PostgreSQL 17 + MaluDB, Apache / PHP / Bootstrap 5.3 / HTMX, with Python FastMCP servers and a Claude Agent SDK assistant.

## Status

Phase 0 (planning) complete, awaiting checkpoint approval. No application code yet.

## Documents

- [docs/01-phase0-plan.md](docs/01-phase0-plan.md) — actors, memory model, question inventory, action inventory, slice order, kanban design, MCP coverage, checkpoint decisions.
- [docs/research/third-party-help-desk-systems.md](docs/research/third-party-help-desk-systems.md) — comparative research across Zendesk, Freshdesk, Jira Service Management, ServiceNow, Help Scout, osTicket, Zammad, HubSpot, Linear, and Intercom.

## Build order

Phase 1 database + memory design → Phase 2 auth + shell → Phase 3 vertical slices → Phase 4 MCP servers + unified assistant. Target host: Ubuntu 24.04.
