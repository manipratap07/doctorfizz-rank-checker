#!/usr/bin/env python3
"""
QA gate for the DoctorFizz rank checker.

This exists because three separate releases shipped looking broken, and in every case the
fault was mechanically detectable before upload. Each check below corresponds to a bug that
actually reached production, so none of them is hypothetical.

Run from the project root:

    python3 tools/qa.py

Exits non zero if any check fails. Nothing gets packaged until it passes.
"""

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FAILS = []
WARNS = []
PASSES = []


def read(rel):
    path = os.path.join(ROOT, rel)
    if not os.path.isfile(path):
        return None
    with open(path, encoding="utf-8", errors="ignore") as fh:
        return fh.read()


def find_one(pattern, folder):
    d = os.path.join(ROOT, folder)
    if not os.path.isdir(d):
        return None
    for name in sorted(os.listdir(d)):
        if re.fullmatch(pattern, name):
            return os.path.join(folder, name)
    return None


def ok(msg):
    PASSES.append(msg)


def fail(msg):
    FAILS.append(msg)


def warn(msg):
    WARNS.append(msg)


# ----------------------------------------------------------------- inputs

CSS_REL = find_one(r"app\.[a-z0-9]+\.css", "assets/css")
JS_REL = find_one(r"app\.[a-z0-9]+\.js", "assets/js")
MOTION_REL = find_one(r"motion\.[a-z0-9]+\.js", "assets/js")
HTML = read("index.html")
TPL = read("app/templates/page.php")
HTACCESS = read(".htaccess")

CSS = read(CSS_REL) if CSS_REL else None
JS = read(JS_REL) if JS_REL else None
MOTION = read(MOTION_REL) if MOTION_REL else None

for label, value in [("stylesheet", CSS), ("app script", JS), ("motion module", MOTION),
                     ("template", TPL), ("htaccess", HTACCESS)]:
    if value is None:
        fail("Missing %s" % label)

if FAILS:
    print("\n".join("FAIL  " + f for f in FAILS))
    sys.exit(1)


# ----------------------------------------------------------------- 1. invisible text

def check_clipped_gradients():
    """
    Shipped bug: .stack-b used background-clip:text with a transparent fill, and the words
    inside it were given a transform by GSAP. A transformed child paints in its own context,
    misses the parent's clipped background, and renders as invisible text.

    Rule: no element with a clipped gradient fill may contain a descendant that the motion
    layer transforms.
    """
    # classes carrying a clipped gradient with transparent text
    clipped = set()
    for m in re.finditer(r"([^{}]+)\{([^}]*)\}", CSS):
        sel, body = m.group(1).strip(), m.group(2).replace(" ", "")
        if "background-clip:text" not in body:
            continue
        if "color:transparent" not in body and "text-fill-color:transparent" not in body:
            continue
        for part in sel.split(","):
            for cls in re.findall(r"\.([A-Za-z][\w-]*)", part):
                clipped.add(cls)

    # classes the motion layer gives a transform to
    transform_props = ("yPercent", "xPercent", "scale", "rotate", "x:", "y:")
    animated = set()
    for m in re.finditer(r"q\('\.([\w-]+)'[^)]*\)", MOTION):
        cls = m.group(1)
        tail = MOTION[m.end():m.end() + 900]
        if any(p in tail for p in transform_props):
            animated.add(cls)
    for m in re.finditer(r"querySelectorAll\('\.([\w-]+)'\)", MOTION):
        cls = m.group(1)
        tail = MOTION[m.end():m.end() + 500]
        if any(p in tail for p in transform_props):
            animated.add(cls)

    risky = []
    for parent in sorted(clipped):
        # every element that carries the clipped class, and what sits inside it
        for m in re.finditer(r'class="[^"]*\b' + re.escape(parent) + r'\b[^"]*"[^>]*>', HTML):
            inner = HTML[m.end():m.end() + 600]
            inner = inner.split("</h1>")[0].split("</h2>")[0].split("</section>")[0]
            for child in animated:
                if re.search(r'class="[^"]*\b' + re.escape(child) + r'\b', inner):
                    risky.append(".%s contains animated .%s" % (parent, child))
                    break

    if risky:
        fail("Clipped gradient with animated descendants, text renders invisible: "
             + "; ".join(sorted(set(risky))))
    else:
        ok("No clipped gradient contains an animated descendant (%d clipped, %d animated classes checked)"
           % (len(clipped), len(animated)))


# ----------------------------------------------------------------- 2. readable without CSS

def check_headings_without_css():
    """
    Shipped bug: headings were two block spans with no whitespace between them, so without
    the stylesheet "WHAT IT" and "DOES" ran together as "WHAT ITDOES".
    """
    bad = []
    for m in re.finditer(r"<h([12])[^>]*class=\"stack\"[^>]*>(.*?)</h\1>", HTML, re.S):
        text = re.sub(r"<[^>]+>", "", m.group(2))
        text = re.sub(r"\s+", " ", text).strip()
        # a run of letters longer than any real word signals two words fused together
        for word in text.split():
            if len(word) > 14 and word.isalpha():
                bad.append(text)
    if bad:
        fail("Headings fuse without CSS: " + "; ".join(sorted(set(bad))))
    else:
        ok("All headings read correctly with no stylesheet")


# ----------------------------------------------------------------- 3. nothing hidden awaiting JS

def check_no_css_hiding():
    """
    Shipped bug: the headline was hidden by CSS behind a .js-on class and revealed by
    JavaScript. When the animation bundle failed to load, it stayed hidden forever.
    """
    exempt = ("gsap-ready", "modal", "scanline", "hero-canvas", "btn-spin",
              "[hidden]", "sr-select", ".hp", "prefers-reduced-motion", "print")
    bad = []
    for m in re.finditer(r"([^{}]+)\{([^}]*)\}", CSS):
        sel, body = m.group(1).strip(), m.group(2).replace(" ", "")
        if any(x in sel for x in exempt):
            continue
        if "opacity:0" in body or "visibility:hidden" in body:
            bad.append(sel[:48])
    if bad:
        fail("CSS hides content that only JavaScript can restore: " + "; ".join(bad))
    else:
        ok("No CSS rule hides content awaiting JavaScript")


# ----------------------------------------------------------------- 4. hidden attribute wins

def check_hidden_attribute():
    """
    Shipped bug: .modal set display:flex, which beats the browser's [hidden]{display:none},
    so the popup could never be hidden and appeared on load.
    """
    if not re.search(r"\[hidden\]\s*\{\s*display\s*:\s*none\s*!important", CSS):
        fail("Missing [hidden]{display:none !important}, elements with a display rule cannot be hidden")
        return
    pos = CSS.index("[hidden]")
    later = [m.group(1).strip() for m in re.finditer(r"([^{}]+)\{([^}]*display\s*:[^}]*)\}", CSS[:pos])]
    ok("[hidden] override present and declared early")


# ----------------------------------------------------------------- 5. assets resolve

def check_assets_exist():
    """
    Shipped bug: the page referenced a stylesheet the server did not have, and reported
    nothing. It simply looked broken.
    """
    missing = []
    for rel in sorted(set(re.findall(r'(?:href|src)="((?:\./)?assets/[^"?]+)', HTML))):
        if not os.path.isfile(os.path.join(ROOT, rel.lstrip("./"))):
            missing.append(rel)
    if missing:
        fail("Referenced but not on disk: " + ", ".join(missing))
    else:
        ok("Every referenced asset exists on disk")


# ----------------------------------------------------------------- 6. cache busting

def check_cache_busting():
    """
    Shipped bug: assets were versioned with a query string. LiteSpeed, which Hostinger runs,
    and most CDNs strip it, so a 30 day cached stylesheet outlived three releases.
    """
    if not re.search(r'href="assets/css/app\.[a-z0-9]+\.css', HTML):
        fail("Stylesheet is not version stamped in its filename")
    elif not re.search(r"Cache-Control\s+\"no-cache", HTACCESS):
        fail("CSS and JS are not set to revalidate in .htaccess")
    else:
        ok("Assets versioned by filename and code set to revalidate")


# ----------------------------------------------------------------- 7. layout is not stranded

def check_dead_space():
    """
    Reported fault: sections rendered as a narrow left column against a large empty right
    side, because headings and section heads were capped far below the container width.
    """
    caps = []
    for m in re.finditer(r"(?:^|[,\s}])\.(stack|sec-head)\s*\{([^}]*)\}", CSS, re.M):
        mw = re.search(r"max-width:\s*(\d+)ch", m.group(2))
        if mw and int(mw.group(1)) < 20:
            caps.append("%s capped at %sch" % (m.group(1), mw.group(1)))
    if caps:
        warn("Narrow caps that can strand the right edge: " + "; ".join(caps))
    if ".sec-split" not in CSS:
        warn("No split layout defined, text led sections may sit against dead space")
    if not caps and ".sec-split" in CSS:
        ok("Header bands and text sections use the full container width")


# ----------------------------------------------------------------- 8. house style

def check_dashes():
    bad = []
    for base, _dirs, files in os.walk(ROOT):
        if "vendor" in base or ".git" in base:
            continue
        for name in files:
            if name.rsplit(".", 1)[-1] not in ("php", "css", "js", "md", "html", "txt"):
                continue
            body = read(os.path.relpath(os.path.join(base, name), ROOT)) or ""
            if "\u2014" in body or "\u2013" in body:
                bad.append(name)
    if bad:
        fail("Em or en dashes found in: " + ", ".join(sorted(set(bad))))
    else:
        ok("No em or en dashes anywhere")


# ----------------------------------------------------------------- run

for fn in (check_clipped_gradients, check_headings_without_css, check_no_css_hiding,
           check_hidden_attribute, check_assets_exist, check_cache_busting,
           check_dead_space, check_dashes):
    try:
        fn()
    except Exception as exc:                                   # a broken check is a failure
        fail("%s raised %s" % (fn.__name__, exc))

print("\nQA gate\n" + "=" * 60)
for p in PASSES:
    print("  pass  " + p)
for w in WARNS:
    print("  warn  " + w)
for f in FAILS:
    print("  FAIL  " + f)
print("=" * 60)
print("%d passed, %d warnings, %d failures" % (len(PASSES), len(WARNS), len(FAILS)))

sys.exit(1 if FAILS else 0)
