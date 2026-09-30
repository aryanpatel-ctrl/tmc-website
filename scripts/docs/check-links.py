#!/usr/bin/env python3
"""Offline link check for the project documentation.

Checks every relative link in README.md and docs/**/*.md:
  - the target file or directory exists in the repository;
  - a "#fragment" on a Markdown target matches a heading in that file (GitHub slug rules);
  - a "#fragment" on the same page matches a heading of the page itself.
External links (http, https, mailto) are not fetched, so the check is deterministic and needs no
network; they are only counted.

  python3 scripts/docs/check-links.py            # exit 1 if any link is broken
  python3 scripts/docs/check-links.py --verbose  # also list every checked link

Used by .github/workflows/docs.yml and runnable locally with no dependencies (Python 3.8+).
"""
import os
import re
import sys
import unicodedata

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
LINK = re.compile(r"(?<!\!)\[(?:[^\]\[]|\[[^\]]*\])*\]\(\s*<?([^)\s>]+)>?(?:\s+\"[^\"]*\")?\s*\)")
IMAGE = re.compile(r"!\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+\"[^\"]*\")?\s*\)")
HEADING = re.compile(r"^(#{1,6})\s+(.*?)\s*#*\s*$")
FENCE = re.compile(r"^\s*(```|~~~)")


def markdown_files():
    files = [os.path.join(ROOT, "README.md")]
    for base, dirs, names in os.walk(os.path.join(ROOT, "docs")):
        dirs.sort()
        for name in sorted(names):
            if name.endswith(".md"):
                files.append(os.path.join(base, name))
    return [f for f in files if os.path.isfile(f)]


def strip_code(lines, keep_inline=False):
    """Yield (line_no, text) outside fenced code blocks; inline code is removed unless keep_inline."""
    in_fence = False
    for number, line in enumerate(lines, 1):
        if FENCE.match(line):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        yield number, line if keep_inline else re.sub(r"`[^`]*`", "", line)


def slugify(text):
    """GitHub heading anchor: lower case, drop punctuation except '-' and '_', spaces to '-'."""
    text = re.sub(r"<[^>]+>", "", text)                      # inline HTML
    text = re.sub(r"!\[([^\]]*)\]\([^)]*\)", r"\1", text)    # images
    text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)     # links keep their text
    text = text.replace("`", "").lower()
    out = []
    for ch in text:
        cat = unicodedata.category(ch)
        if ch in "-_ " or cat.startswith("L") or cat.startswith("N") or cat == "Mn" or cat == "Mc":
            out.append("-" if ch == " " else ch)
    return "".join(out)


_anchor_cache = {}


def anchors(path):
    if path not in _anchor_cache:
        seen = {}
        result = set()
        with open(path, encoding="utf-8") as handle:
            for _, line in strip_code(handle.read().splitlines(), keep_inline=True):
                match = HEADING.match(line)
                if not match:
                    continue
                slug = slugify(match.group(2).strip())
                count = seen.get(slug, 0)
                result.add(slug if count == 0 else f"{slug}-{count}")
                seen[slug] = count + 1
        # explicit HTML anchors: <a id="..."> / <a name="...">
        with open(path, encoding="utf-8") as handle:
            for found in re.finditer(r"<a\s+(?:id|name)=\"([^\"]+)\"", handle.read()):
                result.add(found.group(1))
        _anchor_cache[path] = result
    return _anchor_cache[path]


def main():
    verbose = "--verbose" in sys.argv
    broken = []
    checked = external = 0
    for source in markdown_files():
        with open(source, encoding="utf-8") as handle:
            lines = handle.read().splitlines()
        for number, text in strip_code(lines):
            for pattern in (LINK, IMAGE):
                for match in pattern.finditer(text):
                    target = match.group(1)
                    if re.match(r"^[a-z][a-z0-9+.-]*:", target, re.I):
                        external += 1
                        continue
                    checked += 1
                    path_part, _, fragment = target.partition("#")
                    if path_part:
                        resolved = os.path.normpath(os.path.join(os.path.dirname(source), path_part))
                    else:
                        resolved = source
                    rel_source = os.path.relpath(source, ROOT)
                    if not resolved.startswith(ROOT):
                        broken.append(f"{rel_source}:{number}: {target} (points outside the repository)")
                        continue
                    if not os.path.exists(resolved):
                        broken.append(f"{rel_source}:{number}: {target} (missing file)")
                        continue
                    if fragment and resolved.endswith(".md") and fragment not in anchors(resolved):
                        broken.append(f"{rel_source}:{number}: {target} (no heading '#{fragment}')")
                        continue
                    if verbose:
                        print(f"  ok  {rel_source}:{number}: {target}")
    for line in broken:
        print(f"  BROKEN  {line}")
    print(f"==> {checked} relative links checked, {external} external links not fetched, {len(broken)} broken")
    return 1 if broken else 0


if __name__ == "__main__":
    sys.exit(main())
