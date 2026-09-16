import json, datetime as dt, collections
D = json.load(open("sheet_snapshot_2026-09-14.json"))
def d(s): return dt.date.fromisoformat(s[:10]) if s else None
emps = {r[0].strip(): {"hire": d(r[1]), "bday": d(r[2]), "vac": r[3], "pto": r[4]} for r in D["Employee Start Date"]["rows"][1:] if r[0] and r[0].strip()}
reqs = [{"i": i+2, "emp": r[0].strip(), "type": (r[1] or "").strip(), "start": d(r[2]), "end": d(r[3]), "ptoRem": r[4], "vacRem": r[5]} for i, r in enumerate(D["Time Requested Off"]["rows"][1:])]
TODAY = dt.date(2026, 9, 14)
def yos(asof, h):
    y = asof.year - h.year
    if (asof.month, asof.day) < (h.month, h.day): y -= 1
    return y
def vac_allot(y): return 15 if y >= 10 else 10 if y >= 5 else 5 if y >= 1 else 0
def pto_allot(y): return 3 if y >= 1 else 0
def win(ref, h):
    a = dt.date(ref.year, h.month, h.day)
    s = a if a <= ref else dt.date(ref.year-1, h.month, h.day)
    return s, dt.date(s.year+1, s.month, s.day)
def wd(a, b):
    n=0; c=a
    while c <= b:
        if c.weekday() < 5: n += 1
        c += dt.timedelta(1)
    return n
print("EMPLOYEES:", len(emps))
for n,e in emps.items():
    y = yos(TODAY, e["hire"]); print(f"  {n:20s} hired {e['hire']} yos={y} sheetVac={e['vac']} calcVac={vac_allot(y)} sheetPTO={e['pto']} calcPTO={pto_allot(y)} bday={e['bday']} cycle={win(TODAY,e['hire'])[0]}")
print("\nREQUEST ROWS:", len(reqs))
print("type values:", collections.Counter(r["type"] for r in reqs))
print("date range:", min(r["start"] for r in reqs), "->", max(r["start"] for r in reqs))
print("unknown employees:", sorted({r["emp"] for r in reqs if r["emp"] not in emps}))
print("start>end:", [(r["i"], r["emp"]) for r in reqs if r["start"] > r["end"]])
print("zero working days:", [(r["i"], r["emp"], str(r["start"])) for r in reqs if wd(r["start"], r["end"]) == 0])
print("spans>5 wd:", [(r["i"], r["emp"], str(r["start"]), str(r["end"]), wd(r["start"], r["end"])) for r in reqs if wd(r["start"], r["end"]) > 5])
seen=set(); dups=[]
for r in reqs:
    k=(r["emp"], r["start"], r["end"], r["type"].lower())
    if k in seen: dups.append((r["i"],)+tuple(map(str,k)))
    seen.add(k)
print("exact duplicates:", dups)
# overlapping within employee
byemp = collections.defaultdict(list)
for r in reqs: byemp[r["emp"]].append(r)
ov=[]
for n, rs in byemp.items():
    rs=sorted(rs, key=lambda r:r["start"])
    for a,b in zip(rs, rs[1:]):
        if b["start"] <= a["end"]: ov.append((n, str(a["start"]), str(a["end"]), str(b["start"]), str(b["end"])))
print("overlaps:", ov)
# straddling anniversary
strad=[(r["i"], r["emp"], str(r["start"]), str(r["end"])) for r in reqs if r["emp"] in emps and win(r["start"], emps[r["emp"]]["hire"])[0] != win(r["end"], emps[r["emp"]]["hire"])[0]]
print("straddle cycle boundary:", strad)
# recompute remaining like the script and diff against stored E/F
mism=0; total=0
per_cycle = collections.defaultdict(lambda: {"pto":0,"vac":0})
for n, rs in byemp.items():
    if n not in emps: continue
    h = emps[n]["hire"]; rs = sorted(rs, key=lambda r:(r["start"], r["i"]))
    ck=None
    for r in rs:
        t=r["type"].lower()
        if t not in ("pto","vacation"): continue
        s,e = win(r["start"], h)
        if s != ck:
            ck=s; y=yos(s,h); ap=pto_allot(y); av=vac_allot(y); up=uv=0
        days=wd(r["start"], r["end"])
        if t=="pto": up+=days
        else: uv+=days
        per_cycle[(n,str(s))][ "pto" if t=="pto" else "vac"] += days
        rp, rv = ap-up, av-uv; total+=1
        if (r["ptoRem"], r["vacRem"]) != (rp, rv):
            mism+=1
            if mism<=25: print(f"  MISMATCH row {r['i']} {n} {t} {r['start']}..{r['end']} sheet=({r['ptoRem']},{r['vacRem']}) calc=({rp},{rv}) cycle={s} allot=({ap},{av})")
print(f"\nrecompute vs stored: {total} rows, {mism} mismatches")
print("\nNEGATIVE / OVERDRAWN cycles (used > allotment):")
for (n,cs),u in sorted(per_cycle.items()):
    h=emps[n]["hire"]; y=yos(dt.date.fromisoformat(cs),h)
    if u["pto"]>pto_allot(y) or u["vac"]>vac_allot(y): print(f"  {n} cycle {cs}: usedPTO={u['pto']}/{pto_allot(y)} usedVac={u['vac']}/{vac_allot(y)}")
print("\nCURRENT-CYCLE BALANCES (today 2026-09-14):")
for n,e in emps.items():
    s,en = win(TODAY, e["hire"]); y=yos(s,e["hire"]); u=per_cycle.get((n,str(s)),{"pto":0,"vac":0})
    print(f"  {n:20s} cycle {s}..{en} PTO {pto_allot(y)-u['pto']}/{pto_allot(y)}  Vac {vac_allot(y)-u['vac']}/{vac_allot(y)}")
print("\nrows per year:", collections.Counter(r["start"].year for r in reqs))
print("weekend-start rows:", sum(1 for r in reqs if r["start"].weekday()>=5))
print("sheet row order is chronological:", all(a["start"]<=b["start"] for a,b in zip(reqs,reqs[1:])))
