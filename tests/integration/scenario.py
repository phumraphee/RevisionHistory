"""
Runs real saves through Omeka's REST API, one HTTP request each, then checks
what Revision History wrote. See README.md for the server this expects.

  python3 scenario.py TAG        (TAG makes titles unique between runs)
Environment: OMEKA_API (default http://127.0.0.1:8080/api), KEY_FILE (identity credential),
MYSQL_DB (default omeka) — read with the mysql command line client.
"""
import requests, json, sys, os, subprocess
B = os.environ.get("OMEKA_API", "http://127.0.0.1:8080/api")
ki, kc = open(os.environ["KEY_FILE"]).read().split()
K = {"key_identity": ki, "key_credential": kc}
def call(method, path, data=None):
    r = requests.request(method, B + path, params=K, json=data)
    if r.status_code >= 300:
        print("FAIL", method, path, r.status_code, r.text[:500]); sys.exit(1)
    return r.json() if r.text else None
props = {p["o:term"]: p["o:id"] for p in call("GET", "/properties?per_page=500")}
def lit(term, v, **kw):
    d = {"type": "literal", "property_id": props[term], "@value": v}
    d.update(kw); return d
def full(item):
    # what an item looks like when sent back whole, like the admin form does
    out = {k: v for k, v in item.items() if ":" in k and not k.startswith("o:") and not k.startswith("@")}
    for k in ("o:item_set", "o:is_public", "o:resource_template", "o:resource_class"):
        if k in item: out[k] = item[k]
    out["o:media"] = [{"o:id": m["o:id"]} for m in item.get("o:media", [])]
    return out
tag = sys.argv[1] if len(sys.argv) > 1 else "A"
iset = call("POST", "/item_sets", {"dcterms:title": [lit("dcterms:title", "Paintings " + tag)]})
a = call("POST", "/items", {
  "dcterms:title": [lit("dcterms:title", "Untitled (Blue) " + tag)],
  "dcterms:identifier": [lit("dcterms:identifier", "TC-%s-0001" % tag)],
  "dcterms:subject": [lit("dcterms:subject", "Oil"), lit("dcterms:subject", "Canvas")],
  "dcterms:date": [lit("dcterms:date", "1975")],
  "dcterms:description": [lit("dcterms:description", "ภาพวาดสีน้ำมันบนผ้าใบ ขนาดใหญ่ painted in Bangkok")],
  "o:item_set": [{"o:id": iset["o:id"]}],
  "o:media": [{"o:ingester": "html", "html": "<p>note one</p>", "dcterms:title": [lit("dcterms:title", "Scan front")]}],
})
b = call("POST", "/items", {"dcterms:title": [lit("dcterms:title", "Sketch " + tag)], "dcterms:identifier": [lit("dcterms:identifier", "TC-%s-0002" % tag)]})
A = a["o:id"]; Bid = b["o:id"]
item = call("GET", "/items/%d" % A)
d = full(item)
d["dcterms:title"] = [lit("dcterms:title", "Untitled (Blue), 1975 " + tag, **{"@language": "en"})]
d["dcterms:subject"] = [lit("dcterms:subject", "Canvas"), lit("dcterms:subject", "Linen")]
d["dcterms:description"] = [lit("dcterms:description", "ภาพวาดสีอะคริลิกบนผ้าใบ ขนาดใหญ่ painted in Thonburi")]
d["dcterms:date"] = [lit("dcterms:date", "1975", **{"is_public": False})]
d["dcterms:relation"] = [{"type": "resource:item", "property_id": props["dcterms:relation"], "value_resource_id": Bid}]
call("PUT", "/items/%d" % A, d)
item = call("GET", "/items/%d" % A); d = full(item)
d["dcterms:date"] = [dict(lit("dcterms:date", "1975", is_public=False), **{"@annotation": {"dcterms:description": [lit("dcterms:description", "Signed lower right")]}})]
call("PUT", "/items/%d" % A, d)
item = call("GET", "/items/%d" % A); d = full(item)
d["dcterms:subject"] = [lit("dcterms:subject", "Linen"), lit("dcterms:subject", "Canvas")]
call("PUT", "/items/%d" % A, d)
call("PATCH", "/items/%d" % A, {"o:is_public": False})
media_id = item["o:media"][0]["o:id"]
call("DELETE", "/media/%d" % media_id)
call("DELETE", "/items/%d" % Bid)
item = call("GET", "/items/%d" % A); d = full(item)
d["o:media"] = d["o:media"] + [{"o:ingester": "html", "html": "<p>two</p>", "dcterms:title": [lit("dcterms:title", "Scan back")]}]
call("PUT", "/items/%d" % A, d)
print("A", A, "B", Bid, "set", iset["o:id"])

def sql(q):
    out = subprocess.run(["mysql", "-N", "-B", os.environ.get("MYSQL_DB", "omeka"), "-e", q], capture_output=True, text=True, check=True).stdout
    return [line.split("\t") for line in out.strip().split("\n") if line]

ok = bad = 0
def check(what, got, want):
    global ok, bad
    if got == want:
        ok += 1
    else:
        bad += 1
        print("FAIL", what, "\n  got ", got, "\n  want", want)

rows = sql("SELECT e.action, e.resource_kind, e.via, GROUP_CONCAT(CONCAT(c.op, ' ', c.field) ORDER BY c.ord SEPARATOR ', ') "
           "FROM revision_history_event e LEFT JOIN revision_history_change c ON c.event_id = e.id "
           "WHERE e.source = 'here' AND (e.resource_id IN (%d, %d, %d) OR e.item_id = %d) GROUP BY e.id ORDER BY e.id" % (A, Bid, iset["o:id"], A))
got = [" | ".join(r) for r in rows]
want = [
    "created | item_set | api | added dcterms:title",
    "created | item | api | added o:item_set, added dcterms:title, added dcterms:identifier, added dcterms:subject, added dcterms:subject, added dcterms:date, added dcterms:description",
    "created | media | api | added o:data, added dcterms:title",
    "created | item | api | added dcterms:title, added dcterms:identifier",
    "edited | item | api | edited dcterms:title, edited dcterms:subject, edited dcterms:date, edited dcterms:description, added dcterms:relation",
    "edited | item | api | edited dcterms:date",
    "edited | item | api | order dcterms:subject",
    "edited | item | api | edited o:is_public",
    "deleted | media | api | removed o:is_public, removed o:owner, removed o:data, removed dcterms:title",
    "edited | item | api | removed dcterms:relation",
    "deleted | item | api | removed o:is_public, removed o:owner, removed dcterms:title, removed dcterms:identifier",
    "created | media | api | added o:data, added dcterms:title",
]
check("the whole story of item A, in order", sorted(got), sorted(want))
note = sql("SELECT after_json FROM revision_history_change c JOIN revision_history_event e ON e.id = c.event_id WHERE e.resource_id = %d AND c.field = 'dcterms:date' AND c.op = 'edited' ORDER BY c.id DESC LIMIT 1" % A)
check("the note on the date value is kept", "Signed lower right" in note[0][0], True)
gone = sql("SELECT before_json FROM revision_history_change c JOIN revision_history_event e ON e.id = c.event_id WHERE e.resource_id = %d AND e.action = 'deleted' AND c.field = 'dcterms:title'" % Bid)
check("a deleted item's values are kept, even with History Log flushing early", len(gone) == 1 and ("Sketch " + tag) in gone[0][0], True)
print(ok, "passed,", bad, "failed")
sys.exit(1 if bad else 0)
