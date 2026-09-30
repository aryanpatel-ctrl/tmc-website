#!/usr/bin/env bash
# Build the documentation set in editable (DOCX) and portable (PDF) formats (SOW §4.15: "handed over in
# editable and portable formats"; R-4.15-9). Sources stay in Markdown under docs/ (and README.md);
# outputs go to dist/docs/ (git-ignored) with the same folder structure, plus SHA256SUMS for handover.
#
#   scripts/docs/build-docs.sh                 all documents, DOCX + PDF, diagrams rendered
#   scripts/docs/build-docs.sh --format docx   only DOCX (or: --format pdf)
#   scripts/docs/build-docs.sh --no-diagrams   keep Mermaid diagrams as source text (no diagram image)
#   scripts/docs/build-docs.sh docs/manuals    only the Markdown files under the given paths
#
# Runs entirely in pinned containers, so nothing needs installing except Docker:
#   Mermaid CLI  renders ```mermaid diagrams to PNG images
#   Pandoc       converts to DOCX, and to PDF with XeLaTeX (DejaVu fonts)
# Links between documents are rewritten to the generated files (scripts/docs/pandoc-filter.lua).
# Known limitation: the PDF fonts have no Devanagari glyphs, so the few Hindi words in the manuals
# appear only in the DOCX version.
set -euo pipefail
cd "$(dirname "$0")/../.."

PANDOC_IMAGE="pandoc/latex:3.11.0.0-debian"
MERMAID_IMAGE="minlag/mermaid-cli:11.4.2"
OUT="dist/docs"
BUILD="dist/.build"
FORMATS="docx pdf"
DIAGRAMS=1
PATHS=()

while [ $# -gt 0 ]; do
  case "$1" in
    --format)      FORMATS="${2:?--format needs docx or pdf}"; shift 2 ;;
    --no-diagrams) DIAGRAMS=0; shift ;;
    -h|--help)     sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*)            echo "unknown option: $1" >&2; exit 64 ;;
    *)             PATHS+=("$1"); shift ;;
  esac
done
case "$FORMATS" in
  docx|pdf|"docx pdf") ;;
  *) echo "--format must be docx or pdf" >&2; exit 64 ;;
esac
command -v docker >/dev/null || { echo "Docker is required" >&2; exit 69; }

# Markdown sources, sorted, relative to the repository root. A full build starts from an empty output
# folder so the handover set and its checksums contain exactly the current documents.
if [ "${#PATHS[@]}" -eq 0 ]; then
  PATHS=(README.md docs)
  rm -rf "$OUT"
fi
FILES=()
while IFS= read -r file; do FILES+=("$file"); done < <(
  for path in "${PATHS[@]}"; do
    if [ -d "$path" ]; then find "$path" -name '*.md' -type f; elif [ -f "$path" ]; then echo "$path"; fi
  done | sed 's#^\./##' | sort -u)
[ "${#FILES[@]}" -gt 0 ] || { echo "no Markdown files found in: ${PATHS[*]}" >&2; exit 66; }

rm -rf "$BUILD" && mkdir -p "$BUILD" "$OUT"
USER_ARGS=(-u "$(id -u):$(id -g)")   # files in the bind mount belong to the caller, also on Linux CI

echo "==> preparing ${#FILES[@]} documents"
RENDER=()
for file in "${FILES[@]}"; do
  mkdir -p "$BUILD/$(dirname "$file")"
  if [ "$DIAGRAMS" -eq 1 ] && grep -q '^```mermaid' "$file"; then
    RENDER+=("$file")
  else
    cp "$file" "$BUILD/$file"
  fi
done

if [ "${#RENDER[@]}" -gt 0 ]; then
  echo "==> rendering diagrams in ${#RENDER[@]} documents ($MERMAID_IMAGE)"
  for file in "${RENDER[@]}"; do
    # Mermaid CLI rewrites each ```mermaid block into an image link and writes the PNGs next to the output.
    if ! docker run --rm "${USER_ARGS[@]}" -e HOME=/tmp -v "$PWD:/data" -w /data "$MERMAID_IMAGE" \
         -i "$file" -o "$BUILD/$file" -e png -s 2 -b white -q >/dev/null 2>"$BUILD/$file.mermaid.log"; then
      echo "  diagrams failed for $file (see $BUILD/$file.mermaid.log); keeping the diagram source" >&2
      cp "$file" "$BUILD/$file"
    fi
    # Give the generated images a meaningful alternative text instead of "diagram".
    sed -i.bak 's/^!\[diagram\](\(.*\))$/![Diagram](\1)/' "$BUILD/$file" && rm -f "$BUILD/$file.bak"
  done
fi

echo "==> converting to: $FORMATS ($PANDOC_IMAGE)"
status=0
docker run --rm "${USER_ARGS[@]}" -e HOME=/tmp -v "$PWD:/data" -w /data --entrypoint /bin/sh "$PANDOC_IMAGE" \
  scripts/docs/pandoc-convert.sh "$BUILD" "$OUT" "$FORMATS" "${FILES[@]}" || status=$?

( cd "$OUT" && find . -type f \( -name '*.docx' -o -name '*.pdf' \) | sort | sed 's#^\./##' |
    while IFS= read -r f; do
      if command -v sha256sum >/dev/null; then sha256sum "$f"; else shasum -a 256 "$f"; fi
    done > SHA256SUMS )

count="$(grep -c . "$OUT/SHA256SUMS" || true)"
if [ "$status" -ne 0 ]; then
  echo "==> documentation build finished with errors ($count files in $OUT)" >&2
  exit 1
fi
echo "==> $count files in $OUT (checksums in $OUT/SHA256SUMS)"
