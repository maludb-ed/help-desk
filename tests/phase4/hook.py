"""Proof — the kernel's Actions MCP, with the KERNEL'S OWN application_actions.py (read from /var/www/mcp, never modified), fed Help Desk's registry exactly as the installer writes it
(deploy/kernel-registry-helpdesk.json's `resolve` + mcp/action_registry.json) and a STUBBED approval hook and application. No live kernel, no live application: what is proved is the
contract — the 96 actions become 96 tools; the 40 with an approval category ask the hook FIRST with their own log event and the handler URL, and never reach the handler when the hook
pauses, refuses, or records; the other 56 never ask the hook; names become ids through OUR find_* tools. Run by tests/phase4/hook.php with the fixture ids in the environment."""
import asyncio, json, os, pathlib, sys, tempfile

sys.path.insert(0, "/var/www/mcp")
import application_actions as aa                     # the kernel's, unmodified
from mcp.server.fastmcp import FastMCP

APP = pathlib.Path("/srv/apps/helpdesk")
passes = fails = 0


def ok(cond, label):
    global passes, fails
    if cond:
        passes += 1
    else:
        fails += 1
    print(("  ok   " if cond else "  FAIL ") + label)


reg = json.loads((APP / "mcp/action_registry.json").read_text())
wrap = json.loads((APP / "deploy/kernel-registry-helpdesk.json").read_text())
manifest = json.loads((APP / "maludb-os.json").read_text())
tmp = pathlib.Path(tempfile.mkdtemp())
(tmp / "helpdesk.json").write_text(json.dumps({
    "schema": "maludb-os.registry/1", "app_key": "helpdesk", "name": "Help Desk", "base_url": "http://app.stub.invalid",
    "records_url": f"http://127.0.0.1:{os.environ.get('P4_REC_PORT', '8295')}/mcp", "resolve": wrap["resolve"], "registry": reg}))
aa.REGISTRIES_DIR = tmp
TOKEN = os.environ.get("P4_TOKEN", "")
E = os.environ
state = {"hook": "pending", "calls": []}


async def app_post(path, fields, base=None):
    state["calls"].append({"path": path, "fields": dict(fields), "base": base})
    if path == "/approvals/hook.php":
        h = state["hook"]
        if h == "pending":
            return {"status": "pending_approval", "approval_request_id": 17, "did": "waits"}
        if h == "success":
            return {"status": "success"}
        if h == "recorded":
            return {"status": "recorded"}
        return {"status": "error", "message": "hook down"}
    return {"status": "success", "did": "done", "record_id": 5}


async def call(mcp, name, args):
    state["calls"] = []
    res = await mcp.call_tool(name, {"params": args})
    content = res[0] if isinstance(res, tuple) else res
    text = content[0].text if content else "{}"
    try:
        return json.loads(text)
    except ValueError:
        return {"raw": text}


RESOLVED = {p for v in wrap["resolve"].values() for p in v["params"]}


def sample(action):
    """Digits for every resolved entity and every repeated param, plain words for the rest — nothing to look up."""
    a = {}
    for p in action["params"]:
        if p.get("name") and p.get("required"):
            a[p["name"]] = "1" if p["name"] in RESOLVED or p.get("repeated") else "2026-10-09" if "date" in p["name"] or p["name"].endswith("_at") else "x"
    if action.get("confirm"):
        a["confirmed"] = True
    return a


async def main():
    mcp = FastMCP("t")
    n = aa.register(mcp, app_post, lambda: TOKEN, set())
    actions = reg["actions"]
    built = [a for a in actions.values() if a.get("built")]
    tools = {t.name for t in await mcp.list_tools()}
    ok(len(actions) == 96 and len(built) == 96 and n == 96 and tools == set(actions), f"the kernel's register() turns the registry's {len(actions)} actions into {n} tools, every name the registry's")
    pausing = {k: a for k, a in actions.items() if a.get("approval")}
    ok(len(pausing) == 40, f"{len(pausing)} actions carry an approval category")
    cats = {a["action"]: a["category"] for a in manifest["approvals"]}
    ok(set(cats) == set(pausing) and all(cats[k] == a["approval"] for k, a in pausing.items()), "each pausing action's category equals maludb-os.json approvals[]")
    ext = sorted(k for k, a in pausing.items() if a["approval"] == "external_send")
    ok(ext == ["article_send", "canned_apply", "survey_send", "ticket_reply"], "external_send: " + ", ".join(ext))
    ok(all(pausing[k]["log_event"] for k in pausing), "each pausing action names its log event")
    free = ["ticket_create", "ticket_note", "ticket_assign", "ticket_transition", "ticket_set_fields", "ticket_add_tags", "ticket_link", "ticket_follow", "article_suggest"]
    ok(all(k in actions and not actions[k].get("approval") for k in free), "an agent files, notes, assigns, routes, tags, links, follows and suggests without a pause")

    for name, a in sorted(pausing.items()):
        args = sample(a)
        state["hook"] = "pending"
        r = await call(mcp, name, args)
        c = state["calls"]
        ok(r.get("status") == "pending_approval" and [x["path"] for x in c] == ["/approvals/hook.php"], f"{name}: the hook pauses it -> pending_approval; the handler is NEVER posted")
        h = c[0]["fields"] if c else {}
        ok(h.get("action_key") == name and h.get("log_event") == a["log_event"] and h.get("handler_url") == "http://app.stub.invalid" + a["endpoint"], f"{name}: the hook is asked with its key, its log event ({a['log_event']}) and the handler URL")
        state["hook"] = "success"
        r = await call(mcp, name, args)
        ok([x["path"] for x in state["calls"]] == ["/approvals/hook.php", a["endpoint"]] and r.get("status") == "success", f"{name}: hook says go -> the handler is posted after it")
    for mode, label in (("recorded", "an evaluation (hook: recorded)"), ("error", "the hook failing")):
        state["hook"] = mode
        bad = 0
        for name, a in pausing.items():
            await call(mcp, name, sample(a))
            if any(x["path"] == a["endpoint"] for x in state["calls"]):
                bad += 1
        ok(bad == 0, f"{label}: none of the {len(pausing)} reaches its handler")
    state["hook"] = "pending"
    others = {k: a for k, a in actions.items() if not a.get("approval")}
    hooked, unposted = [], []
    for name, a in others.items():
        await call(mcp, name, sample(a))
        paths = [x["path"] for x in state["calls"]]
        if "/approvals/hook.php" in paths:
            hooked.append(name)
        if paths != [a["endpoint"] if a["endpoint"].startswith("/") else "/" + a["endpoint"]]:
            unposted.append((name, paths))
    ok(len(others) == 56 and hooked == [] and unposted == [], f"the other {len(others)} never ask the hook and post their handler straight away" + (f" — NOT: {hooked} {unposted[:3]}" if hooked or unposted else ""))
    conf = [k for k, a in actions.items() if a.get("confirm")]
    r = await call(mcp, conf[0], {k: v for k, v in sample(actions[conf[0]]).items() if k != "confirmed"})
    ok(r.get("status") == "needs_confirmation", f"a destructive action ({conf[0]}) asks for confirmation before anything is posted ({len(conf)} of them do)")

    # names become ids through OUR tools (the caller's own token, a real records server)
    if TOKEN:
        state["hook"] = "success"

        async def fields_of(name, args):
            r = await call(mcp, name, args)
            return r, (state["calls"][-1]["fields"] if state["calls"] else {})

        r, f = await fields_of("ticket_reply", {"ticket": E["P4_NUMBER"], "body": "Hello"})
        ok(f.get("ticket") == E["P4_TICKET"] and [c["path"] for c in state["calls"]][0] == "/approvals/hook.php", f"ticket_reply 'ticket {E['P4_NUMBER']}' -> its id ({f.get('ticket')}) — and it asked the hook first")
        ok(r.get("resolved", {}).get("ticket", "").startswith(E["P4_NUMBER"]), "and the answer says which ticket it resolved: " + json.dumps(r.get("resolved")))
        r, f = await fields_of("ticket_assign", {"ticket": "dated it5", "assignee": "Marco"})
        ok(f.get("ticket") == E["P4_TICKET"] and f.get("assignee") == "27", f"ticket_assign: the subject 'dated it5' and the name 'Marco' become ids ({f.get('ticket')}, {f.get('assignee')}) through find_tickets and find_members")
        r, f = await fields_of("ticket_assign", {"ticket": E["P4_TICKET"], "queue": "IT help"})
        ok(f.get("queue") == E["P4_QUEUE"], f"ticket_assign: queue 'IT help' -> {f.get('queue')} through find_queues")
        r, f = await fields_of("ticket_create", {"subject": "SMOKE via kernel", "body": "It broke", "queue": "IT help", "requester": "SMOKE Jo", "type": "incident", "category": "SMOKE p4 hook category"})
        ok(f.get("queue") == E["P4_QUEUE"] and f.get("requester") == E["P4_JO"] and f.get("type") == E["P4_TYPE"] and f.get("category") == E["P4_CATEGORY"], f"ticket_create: queue, requester, type and category by name -> ids ({f.get('queue')}, {f.get('requester')}, {f.get('type')}, {f.get('category')})")
        r, f = await fields_of("ticket_transition", {"ticket": E["P4_TICKET"], "status": "Solved", "resolution_code": "resolved"})
        ok(f.get("status") == E["P4_SOLVED"] and f.get("resolution_code") == "resolved", f"ticket_transition: status 'Solved' -> its id ({f.get('status')}); the resolution code stays a word (not an entity)")
        r, f = await fields_of("canned_apply", {"ticket": E["P4_TICKET"], "canned": "hook reset"})
        ok(f.get("canned") == E["P4_CANNED"] and [c["path"] for c in state["calls"]][0] == "/approvals/hook.php", f"canned_apply: canned 'hook reset' -> its id ({f.get('canned')}); it pauses for an agent" + ("" if f.get("canned") == E["P4_CANNED"] else " — " + json.dumps(r)[:200]))
        r, f = await fields_of("rule_toggle", {"rule": "reopen"})
        ok(f.get("rule") == E["P4_RULE"], f"rule_toggle: rule 'reopen' -> {f.get('rule')} through find_rules")
        r, f = await fields_of("tickets_bulk_update", {"tickets": "1,2,3", "priority": "high", "confirmed": True})
        ok(f.get("tickets[]") == ["1", "2", "3"], "a repeated param (tickets '1,2,3') is a list of ids the handler reads — not looked up as a name" + ("" if f.get("tickets[]") == ["1", "2", "3"] else f" — got {json.dumps(f)[:160]} / {json.dumps(r)[:160]}"))
        r, f = await fields_of("ticket_reply", {"ticket": "dated", "body": "x"})
        ok(r.get("status") == "error" and "Several match" in r.get("message", ""), "'dated' matches many tickets: the kernel asks which one — " + r.get("message", "")[:70])
        ok(not any(c["path"] == "/tickets/reply.php" for c in state["calls"]), "…and nothing was posted")
        r, f = await fields_of("ticket_note", {"ticket": "ZZZ nothing here", "body": "x"})
        ok(r.get("status") == "error" and "No ticket matching" in r.get("message", "") and state["calls"] == [], "a name nobody has: 'No ticket matching' — nothing posted")
        r, f = await fields_of("ticket_note", {"ticket": E["P4_TICKET"], "body": "SMOKE"})
        ok(f.get("ticket") == E["P4_TICKET"] and r.get("status") == "success", "a digit id passes straight through (no lookup)")
    print(f"{'all ' + str(passes) + ' passed' if not fails else str(fails) + ' FAILED (' + str(passes) + ' passed)'}")
    sys.exit(1 if fails else 0)

asyncio.run(main())
