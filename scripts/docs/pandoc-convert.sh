#!/bin/sh
# Runs INSIDE the pandoc container (started by scripts/docs/build-docs.sh); not meant to be run directly.
#
#   pandoc-convert.sh BUILD_DIR OUT_DIR FORMATS FILE...
#
# FILE paths are relative to BUILD_DIR (prepared Markdown with diagrams already rendered to PNG).
# FORMATS is "docx", "pdf" or "docx pdf". Prints one line per output and exits 1 if any conversion failed.
set -u

BUILD="$1"; OUT="$2"; FORMATS="$3"; shift 3
FILTER="$(cd "$(dirname "$0")" && pwd)/pandoc-filter.lua"
FAILED=0

# convert SRC RESOURCE_DIR TARGET [format options...]
convert() {
  src="$1"; resources="$2"; target="$3"; shift 3
  pandoc "$src" --from gfm --standalone --shift-heading-level-by=-1 \
    --lua-filter "$FILTER" --resource-path "$resources" \
    --metadata lang=en-GB "$@" -o "$target" 2> "$target.log"
}

for rel in "$@"; do
  dir="$(dirname "$rel")"
  base="$(basename "$rel" .md)"
  mkdir -p "$OUT/$dir"
  for format in $FORMATS; do
    target="$OUT/$dir/$base.$format"
    case "$format" in
      docx) convert "$BUILD/$rel" "$BUILD/$dir" "$target" --to docx ;;
      pdf)  convert "$BUILD/$rel" "$BUILD/$dir" "$target" --to pdf --pdf-engine=xelatex --toc --toc-depth=2 \
              -V mainfont="DejaVu Sans" -V monofont="DejaVu Sans Mono" \
              -V geometry:a4paper -V geometry:margin=18mm -V fontsize=10pt \
              -V colorlinks=true -V linkcolor=blue -V urlcolor=blue \
              -V 'header-includes=\usepackage{etoolbox}\AtBeginEnvironment{longtable}{\footnotesize}' ;;
      *)    echo "unknown format $format" > "$target.log"; false ;;
    esac
    if [ $? -eq 0 ]; then
      rm -f "$target.log"
      echo "  ok    $dir/$base.$format"
    else
      echo "  FAIL  $dir/$base.$format (see $target.log)"
      FAILED=1
    fi
  done
done
exit "$FAILED"
