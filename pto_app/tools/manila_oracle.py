"""Independent Python re-implementation of the Manila (Bright Bird Design) PTO rules.

Mirrors the sheet's getDataAsJson() logic (split rule: each working day is charged to the
cycle it falls in) with the two adjustments ANCHORED to a cycle (SPEC section 9) instead of
the sheet's drifting CURRENT/NEXT semantics.

Run:  python pto_app/tools/manila_oracle.py            (prints the 18 balances as of 2026-09-14)
      python pto_app/tools/manila_oracle.py --check    (also diffs against tests/fixtures/expected_2026-09-14.json)
"""
import datetime as dt
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
FIX = os.path.join(HERE, "..", "tests", "fixtures")
D = json.load(open(os.path.join(FIX, "manila_snapshot_2026-09-14.json")))
TODAY = dt.date(2026, 9, 14)


def d(s):
    return dt.date.fromisoformat(s[:10]) if s else None


def yos(hire, at):
    y = at.year - hire.year
    if (at.month, at.day) < (hire.month, hire.day):
        y -= 1
    return y


def allot(y):
    # Manila tiers: 0 (<1), 3 (1-4), 5 (5-9), 10 (10+)
    return 10 if y >= 10 else 5 if y >= 5 else 3 if y >= 1 else 0


def safe_date(y, m, day):
    # JS new Date(y, m, d) semantics: Feb 29 rolls to Mar 1 in non-leap years.
    try:
        return dt.date(y, m, day)
    except ValueError:
        return dt.date(y, m, day - 1) + dt.timedelta(days=1)


def cycle_start(hire, ref):
    a = safe_date(ref.year, hire.month, hire.day)
    return a if a <= ref else safe_date(ref.year - 1, hire.month, hire.day)


def cycle_end(cs):
    return safe_date(cs.year + 1, cs.month, cs.day)


def next_cycle_start(hire, cs):
    return cycle_start(hire, cycle_end(cs))


def working_dates(a, b):
    c = a
    while c <= b:
        if c.weekday() < 5:
            yield c
        c += dt.timedelta(1)


def consumed_by_cycle(hire, start, end):
    out = {}
    for day in working_dates(start, end):
        k = cycle_start(hire, day)
        out[k] = out.get(k, 0) + 1
    return out


emps = {}
for r in D["Employee Start Date"]["rows"][1:]:
    if r[0] and r[0].strip() and r[1]:
        emps[r[0].strip()] = d(r[1])

reqs = []
for r in D["Time Requested Off"]["rows"][1:]:
    if r[0] and r[0].strip() and r[2] and r[3]:
        reqs.append((r[0].strip(), d(r[2]), d(r[3])))

# Adjustments anchored: CURRENT keeps the sheet's effective date; NEXT moves to the start of the
# cycle after the one containing the sheet's effective date.
adjs = []
for r in D["PTO Adjustments"]["rows"][1:]:
    name = (r[0] or "").strip()
    if not name or name not in emps or not r[1]:
        continue
    eff = d(r[1])
    if str(r[3] or "CURRENT").strip().upper() == "NEXT":
        eff = next_cycle_start(emps[name], cycle_start(emps[name], eff))
    adjs.append({"employee": name, "effective_date": eff, "days": float(r[2]), "reason": (r[5] or "").strip()})

results = {}
for name, hire in emps.items():
    cur = cycle_start(hire, TODAY)
    nxt = next_cycle_start(hire, cur)
    used = {cur: 0, nxt: 0}
    for emp, s, e in reqs:
        if emp != name:
            continue
        for k, n in consumed_by_cycle(hire, s, e).items():
            if k in used:
                used[k] += n
    adj = {cur: 0.0, nxt: 0.0}
    for a in adjs:
        if a["employee"] != name:
            continue
        k = cycle_start(hire, a["effective_date"])
        if k in adj:
            adj[k] += a["days"]

    def block(cs):
        al = allot(yos(hire, cs))
        return {
            "cycle_start": cs.isoformat(), "cycle_end": cycle_end(cs).isoformat(),
            "allotment": {"PTO": al}, "adjustments": {"PTO": adj[cs]}, "used": {"PTO": used[cs]},
            "remaining": {"PTO": al + adj[cs] - used[cs]},
        }

    results[name] = {"current": block(cur), "next": block(nxt), "after_date": nxt.strftime("%m/%d/%Y")}

print("MANILA ADJUSTMENTS (anchored):")
for a in adjs:
    print(f"  {a['employee']:16s} {a['effective_date']} {a['days']:+.1f}  {a['reason'][:60]}")
print("\nMANILA BALANCES (today %s):" % TODAY)
for name, r in results.items():
    c, n = r["current"], r["next"]
    print(f"  {name:16s} cycle {c['cycle_start']}..{c['cycle_end']}  PTO {c['remaining']['PTO']:g}/{c['allotment']['PTO'] + c['adjustments']['PTO']:g}"
          f"  (used {c['used']['PTO']})   next {n['remaining']['PTO']:g}/{n['allotment']['PTO']:g} after {r['after_date']}")

if "--check" in sys.argv:
    exp = json.load(open(os.path.join(FIX, "expected_2026-09-14.json")))["manila"]
    bad = 0
    for name, r in results.items():
        e = exp.get(name)
        if e is None:
            print("  MISSING in expected:", name)
            bad += 1
            continue
        for which in ("current", "next"):
            for key in ("cycle_start", "cycle_end"):
                if r[which][key] != e[which][key]:
                    bad += 1
                    print(f"  MISMATCH {name} {which} {key}: oracle {r[which][key]} expected {e[which][key]}")
            for key in ("allotment", "adjustments", "used", "remaining"):
                if abs(float(r[which][key]["PTO"]) - float(e[which][key]["PTO"])) > 1e-9:
                    bad += 1
                    print(f"  MISMATCH {name} {which} {key}: oracle {r[which][key]['PTO']} expected {e[which][key]['PTO']}")
        if r["after_date"] != e["after_date"]:
            bad += 1
            print(f"  MISMATCH {name} after_date: oracle {r['after_date']} expected {e['after_date']}")
    print("\ncheck vs expected: %d mismatches over %d employees" % (bad, len(results)))
    sys.exit(1 if bad else 0)
