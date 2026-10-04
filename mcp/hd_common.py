"""Shared pieces for Help Desk's tool modules: annotations, the input base, the where-builder, the ticket filter vocabulary (a port of
app/features/views/filters.php view_filters_sql — the same keys, the same SQL over mcp_tickets), periods, local times."""
from __future__ import annotations

import datetime
import re
import zoneinfo

from pydantic import BaseModel, ConfigDict

import db

RO = {"readOnlyHint": True, "openWorldHint": False}
OPEN_CATEGORIES = ("new", "open", "pending", "on_hold")
STATUS_CATEGORIES = ("new", "open", "pending", "on_hold", "solved", "closed")
PRIORITIES = ("low", "normal", "high", "urgent")
SLA_STATES = ("at_risk", "breached", "paused", "on_track")


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


def dumps(obj) -> str:
    return db.to_json(obj)


def err(message: str, **extra) -> str:
    return dumps({"error": message, **extra})


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        """`sql` uses {} for each value in order, e.g. add('t.queue_id = {}', 4)."""
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def words(q: str | None) -> list[str]:
    return [w for w in re.split(r"\s+", (q or "").strip()) if w][:6]


def word_start(w: str) -> str:
    """A regex that matches a word beginning with w (names match at the start of a word)."""
    return "\\m" + re.escape(w[:40])


def to_ts(s: str | None, end: bool = False) -> datetime.datetime | None:
    """An ISO date or date-time as a UTC datetime; a bare date as its start (or, `end`, the start of the next day)."""
    if not s:
        return None
    try:
        if len(s) == 10:
            d = datetime.datetime.fromisoformat(s).replace(tzinfo=datetime.timezone.utc)
            return d + datetime.timedelta(days=1) if end else d
        d = datetime.datetime.fromisoformat(s.replace("Z", "+00:00"))
        return d if d.tzinfo else d.replace(tzinfo=datetime.timezone.utc)
    except ValueError:
        return None


async def caller_tz(fetch) -> str:
    rows = await fetch("SELECT timezone FROM mcp_members WHERE member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint)")
    tz = (rows[0]["timezone"] if rows else None) or "UTC"
    try:
        zoneinfo.ZoneInfo(tz)
    except Exception:  # noqa: BLE001
        return "UTC"
    return tz


def localize(rows: list[dict], tz: str, *fields: str) -> list[dict]:
    """Add `<field>_local` ('YYYY-MM-DDTHH:MM' in the caller's zone) beside each UTC timestamp."""
    z = zoneinfo.ZoneInfo(tz)
    for r in rows:
        for f in fields:
            v = r.get(f)
            if isinstance(v, datetime.datetime):
                r[f + "_local"] = v.astimezone(z).strftime("%Y-%m-%dT%H:%M")
    return rows


async def has_right(fetch, right: str) -> bool:
    rows = await fetch("SELECT hd_has_right($1) AS ok", right)
    return bool(rows and rows[0]["ok"])


NEEDS_QUEUE = "This needs a queue's tickets.view_queue right (a requester sees their own tickets only)."


def period_bounds(period: str | None, date_from: str | None, date_to: str | None, tz: str) -> tuple[datetime.datetime, datetime.datetime, str, str]:
    """[start, end) as UTC datetimes plus the local first and last day. Explicit dates win; periods: today, yesterday, week (this, Monday-start),
    last_week, month (this), last_month, quarter, year, last_7_days, last_30_days (default). Days are the caller's zone's."""
    z = zoneinfo.ZoneInfo(tz)
    today = datetime.datetime.now(z).date()
    f = t = None
    if date_from or date_to:
        f = datetime.date.fromisoformat(date_from[:10]) if date_from else None
        t = datetime.date.fromisoformat(date_to[:10]) if date_to else None
        f, t = f or t or today, t or f or today
    else:
        p = (period or "last_30_days").lower()
        mon = today - datetime.timedelta(days=today.weekday())
        first = today.replace(day=1)
        if p == "today":
            f = t = today
        elif p == "yesterday":
            f = t = today - datetime.timedelta(days=1)
        elif p in ("week", "this_week"):
            f, t = mon, mon + datetime.timedelta(days=6)
        elif p == "last_week":
            f, t = mon - datetime.timedelta(days=7), mon - datetime.timedelta(days=1)
        elif p in ("month", "this_month"):
            f, t = first, (first + datetime.timedelta(days=32)).replace(day=1) - datetime.timedelta(days=1)
        elif p == "last_month":
            t = first - datetime.timedelta(days=1); f = t.replace(day=1)
        elif p in ("quarter", "this_quarter"):
            q = (today.month - 1) // 3
            f = datetime.date(today.year, q * 3 + 1, 1)
            t = (datetime.date(today.year + (q == 3), ((q + 1) % 4) * 3 + 1, 1)) - datetime.timedelta(days=1)
        elif p in ("year", "this_year"):
            f, t = datetime.date(today.year, 1, 1), datetime.date(today.year, 12, 31)
        elif p == "last_7_days":
            f, t = today - datetime.timedelta(days=6), today
        else:
            f, t = today - datetime.timedelta(days=29), today
    if t < f:
        raise ValueError("The period's start is after its end.")
    if (t - f).days + 1 > 366:
        raise ValueError("A period is at most 366 days.")
    start = datetime.datetime.combine(f, datetime.time.min, tzinfo=z).astimezone(datetime.timezone.utc)
    end = datetime.datetime.combine(t + datetime.timedelta(days=1), datetime.time.min, tzinfo=z).astimezone(datetime.timezone.utc)
    return start, end, f.isoformat(), t.isoformat()


# ---- the ticket filter vocabulary (the PHP translator's keys) -------------------------------------------------------------
TICKET_COLS = """t.ticket_id, t.number, t.subject, t.requester_id, t.requester_name, t.requester_kind, t.organization_id, t.organization_name,
       t.queue_id, t.queue_name, t.assignee_member_id, t.assignee_name, t.assignee_kind, t.status, t.status_label, t.status_category, t.priority,
       t.needs_reply, t.sla_state, t.sla_next_due_at, t.sla_next_metric, t.tags, t.channel, t.created_at, t.last_activity_at,
       t.number || ' — ' || t.subject AS label"""

SORTS = {
    "last_activity": "t.last_activity_at DESC",
    "created": "t.created_at DESC",
    "oldest": "t.created_at ASC",
    "priority": "CASE t.priority WHEN 'urgent' THEN 3 WHEN 'high' THEN 2 WHEN 'normal' THEN 1 ELSE 0 END DESC",
    "sla_due": "t.sla_next_due_at ASC NULLS LAST",
    "number": "t.number ASC",
}


def ticket_where(f: dict, w: Where, me: int | None) -> Where:
    """Add the filters to `w` (the same vocabulary as saved views): status_category[], status, queue_id[], assignee (id|me|none), requester (id|me),
    organization_id, type_id[], category_id[], priority[], tags[], needs_reply, sla, stale_days, channel, text, created_from/to, solved_from/to, reopened."""
    w.raw("t.merged_into_id IS NULL")

    def lst(v):
        return [v] if not isinstance(v, (list, tuple)) else list(v)

    cats = [c for c in lst(f.get("status_category") or []) if c in STATUS_CATEGORIES]
    if cats:
        w.add("t.status_category = ANY({})", cats)
    if f.get("status"):
        w.add("t.status = {}", str(f["status"]))
    qs = [int(x) for x in lst(f.get("queue_id") or []) if str(x).isdigit()]
    if qs:
        w.add("t.queue_id = ANY({})", qs)
    a = f.get("assignee")
    if a == "me":
        w.add("t.assignee_member_id = {}", me)
    elif a == "none":
        w.raw("t.assignee_member_id IS NULL")
    elif a not in (None, "") and str(a).isdigit():
        w.add("t.assignee_member_id = {}", int(a))
    r = f.get("requester") if f.get("requester") is not None else f.get("requester_id")
    if r == "me":
        w.raw("t.requester_id = (SELECT hd_my_requester_id())")
    elif r not in (None, "") and str(r).isdigit():
        w.add("t.requester_id = {}", int(r))
    if f.get("organization_id") not in (None, ""):
        w.add("t.organization_id = {}", int(f["organization_id"]))
    for k in ("type_id", "category_id"):
        ids = [int(x) for x in lst(f.get(k) or []) if str(x).isdigit()]
        if ids:
            w.add(f"t.{k} = ANY({{}})", ids)
    pr = [p for p in lst(f.get("priority") or []) if p in PRIORITIES]
    if pr:
        w.add("t.priority = ANY({})", pr)
    tags = [str(x) for x in lst(f.get("tags") or f.get("tag") or []) if x]
    if tags:
        w.add("t.tags && {}::text[]", tags)
    if f.get("needs_reply"):
        w.raw("t.needs_reply")
    if f.get("sla") in SLA_STATES:
        w.add("t.sla_state = {}", f["sla"])
    if f.get("stale_days") and int(f["stale_days"]) > 0:
        w.add("t.last_activity_at < now() - make_interval(days => {})", min(365, int(f["stale_days"])))
    if f.get("channel"):
        w.add("t.channel = {}", str(f["channel"]))
    text = str(f.get("text") or f.get("q") or "").strip()
    if text:
        esc = text.replace("%", "\\%").replace("_", "\\_")
        n, s = w.p(esc + "%"), w.p("%" + esc + "%")
        w.raw(f"(t.number ILIKE {n} OR t.subject ILIKE {s} OR t.requester_name ILIKE {s})")
    for key, col, end in (("created_from", "t.created_at >= {}", False), ("created_to", "t.created_at < {}", True), ("solved_from", "t.solved_at >= {}", False), ("solved_to", "t.solved_at < {}", True)):
        if f.get(key):
            ts = to_ts(str(f[key]), end)
            if ts:
                w.add(col, ts)
    if f.get("reopened"):
        w.raw("t.reopen_count > 0")
    return w


def q_alone(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given — the kernel's entity resolver's call, answered as a plain list of {id, label} rows."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}
