#!/usr/bin/env python3
"""
Refresh the embedded faction + detachment data in index.html from the
community BSData 11th-edition Munitorum Field Manual repo.

Usage:
    pip install pyyaml
    python3 scripts/refresh-factions.py

It downloads BSData/wh40k-11e-mfm, parses data/<faction>.yaml, and rewrites the
block between the BSDATA:START / BSDATA:END markers in index.html. Run it from
the project root (or anywhere — paths are resolved relative to this file).

Source: https://github.com/BSData/wh40k-11e-mfm  (community, not official GW)
"""

import io
import os
import re
import sys
import json
import tarfile
import urllib.request

# Override with env BSDATA_TARBALL (e.g. a GitHub repo variable) to point at a
# fork/mirror if the upstream repo is taken down.
REPO_TARBALL = (
    os.environ.get("BSDATA_TARBALL")
    or "https://codeload.github.com/BSData/wh40k-11e-mfm/tar.gz/refs/heads/main"
)

# Safety guard: refuse to overwrite the snapshot if the source looks gutted
# (DMCA strip, emptied repo, broken parse). Set ALLOW_SHRINK=1 to bypass for a
# deliberate, reviewed update.
MIN_FACTION_RATIO = 1.0      # no faction may disappear
MIN_DETACHMENT_RATIO = 0.9   # total detachments may not drop more than 10%

HERE = os.path.dirname(os.path.abspath(__file__))
HTML = os.path.normpath(os.path.join(HERE, "..", "index.html"))

# Allegiance grouping by file slug. Order here = order in the dropdown.
GROUPS = [
    ("Space Marines", ["space-marines", "dark-angels", "blood-angels",
                        "space-wolves", "black-templars", "deathwatch"]),
    ("Imperium", ["grey-knights", "adepta-sororitas", "adeptus-custodes",
                  "adeptus-mechanicus", "astra-militarum", "imperial-knights",
                  "imperial-agents"]),
    ("Chaos", ["chaos-space-marines", "death-guard", "thousand-sons",
               "world-eaters", "emperors-children", "chaos-daemons",
               "chaos-knights"]),
    ("Xenos", ["aeldari", "drukhari", "necrons", "orks", "tau-empire",
               "tyranids", "genestealer-cults", "leagues-of-votann"]),
]

SMALL = {"of", "the", "and", "to", "in", "on", "a", "an", "or", "for", "from", "with"}


def clean(name: str) -> str:
    """Tidy the auto-scraped casing: Emperor'S -> Emperor's, mid-name 'Of' -> 'of'."""
    name = re.sub(r"([A-Za-z])[’']S\b", r"\1’s", name)
    words = name.split(" ")
    out = []
    for i, w in enumerate(words):
        out.append(w.lower() if i > 0 and w.lower() in SMALL else w)
    return " ".join(out)


def current_snapshot(html):
    """Return (faction names, total detachments) from the embedded block."""
    fg = re.search(r"const FACTION_GROUPS=(\[.*?\]);\n", html)
    dt = re.search(r"const DETACHMENTS=(\{.*?\});\n", html)
    if not fg or not dt:
        return set(), 0
    names = {f for g in json.loads(fg.group(1)) for f in g["f"]}
    return names, sum(len(v) for v in json.loads(dt.group(1)).values())


def check_not_gutted(html, faction_groups, detachments, total):
    if os.environ.get("ALLOW_SHRINK") == "1":
        print("  ALLOW_SHRINK=1 set, skipping shrink guard")
        return
    old_names, old_total = current_snapshot(html)
    if not old_names:
        return
    new_names = {f for g in faction_groups for f in g["f"]}
    lost = sorted(old_names - new_names)
    problems = []
    if len(new_names) < len(old_names) * MIN_FACTION_RATIO:
        problems.append(f"factions {len(old_names)} -> {len(new_names)} (lost: {', '.join(lost)})")
    if total < old_total * MIN_DETACHMENT_RATIO:
        problems.append(f"detachments {old_total} -> {total}")
    if problems:
        sys.exit("Source data looks gutted, snapshot left unchanged:\n  "
                 + "\n  ".join(problems)
                 + "\nRe-run with ALLOW_SHRINK=1 if this is a genuine update.")


def main():
    try:
        import yaml
    except ImportError:
        sys.exit("Missing dependency: pip install pyyaml")

    print(f"Downloading {REPO_TARBALL} ...")
    try:
        raw = urllib.request.urlopen(REPO_TARBALL, timeout=60).read()
    except Exception as e:
        sys.exit(f"Source unavailable ({e}). Snapshot left unchanged.")
    tar = tarfile.open(fileobj=io.BytesIO(raw), mode="r:gz")

    # slug -> parsed yaml dict
    data = {}
    for m in tar.getmembers():
        mm = re.search(r"/data/([a-z0-9-]+)\.yaml$", m.name)
        if not mm:
            continue
        slug = mm.group(1)
        if slug == "meta":
            continue
        data[slug] = yaml.safe_load(tar.extractfile(m).read())

    faction_groups = []
    detachments = {}
    dispositions = {}
    dp_costs = {}
    missing = []
    total = 0
    for label, slugs in GROUPS:
        names = []
        for slug in slugs:
            d = data.get(slug)
            if not d:
                missing.append(slug)
                continue
            name = d.get("name", slug)
            det_list = d.get("detachments") or []
            dets = [clean(x["name"]) for x in det_list]
            if not dets:
                missing.append(slug + " (no detachments)")
                continue
            names.append(name)
            detachments[name] = dets
            for x in det_list:
                det_name = clean(x["name"])
                objs = x.get("objectives") or ([x["objective"]] if x.get("objective") else [])
                if objs:
                    dispositions[det_name] = list(objs)
                dp = x.get("dp")
                if dp is not None:
                    dp_costs[det_name] = int(dp)
            total += len(dets)
        faction_groups.append({"g": label, "f": names})

    if missing:
        print("  note: skipped/empty ->", ", ".join(missing))

    block = (
        "  // === BSDATA:START — auto-generated by scripts/refresh-factions.py, do not edit by hand ===\n"
        "  const FACTION_GROUPS=" + json.dumps(faction_groups, ensure_ascii=False) + ";\n"
        "  const DETACHMENTS=" + json.dumps(detachments, ensure_ascii=False) + ";\n"
        "  const DISPOSITIONS=" + json.dumps(dispositions, ensure_ascii=False) + ";\n"
        "  const DP_COSTS=" + json.dumps(dp_costs, ensure_ascii=False) + ";\n"
        "  // === BSDATA:END ==="
    )

    html = open(HTML, encoding="utf-8").read()
    check_not_gutted(html, faction_groups, detachments, total)
    pattern = re.compile(
        r"  // === BSDATA:START.*?// === BSDATA:END ===", re.S
    )
    if not pattern.search(html):
        sys.exit("Could not find BSDATA markers in index.html — aborting.")
    html = pattern.sub(lambda _: block, html, count=1)
    open(HTML, "w", encoding="utf-8").write(html)

    nf = sum(len(g["f"]) for g in faction_groups)
    print(f"Updated {HTML}")
    print(f"  {nf} factions, {total} detachments")


if __name__ == "__main__":
    main()
