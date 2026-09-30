#!/usr/bin/env bash
# Defect closure report from the project issue tracker (GitHub Issues), required before each Go-Live
# (SOW §4.14; Test Plan §10.3). Also produces the ticket data for the monthly support report.
#
#   scripts/reports/defect-closure-report.sh [options]
#
#   --label NAME       issue label to include (repeatable; all must match). Default: defect
#   --site SLUG        only this website (tmc, tmh, hbchrcv, mpmmcc, hbchrcmzp, hbchpunjab); items
#                      raised against "all websites" are always included
#   --since YYYY-MM-DD only issues still open on, or closed on/after, this date
#   --until YYYY-MM-DD only issues created on or before this date
#   --milestone NAME   only issues of this tracker milestone
#   --repo OWNER/NAME  repository (default: the repository of the current directory)
#   --out PREFIX       write PREFIX.md and PREFIX.csv (default: Markdown to standard output)
#   --gate             exit with status 2 when an S1 or S2 item is still open (Go-Live rule)
#   --limit N          maximum issues fetched (default 2000)
#   --input FILE       read issues from a JSON file saved earlier with
#                      gh issue list --state all --json number,title,state,stateReason,labels,createdAt,closedAt,url,assignees,body
#                      instead of querying GitHub (needs jq); used for archived evidence and offline tests
#
# Severity, priority and website come from the labels "severity:S1".."severity:S4",
# "priority:P1".."priority:P4" and "site:<slug>" set at triage; when a label is missing they are read
# from the issue form answers (.github/ISSUE_TEMPLATE/*.yml). Requires the GitHub CLI (gh), logged in
# with read access. Reports are generated files: keep them out of Git (reports/ is ignored).
set -euo pipefail

usage() { sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; }

LABELS=()
SITE="" SINCE="" UNTIL="" MILESTONE="" REPO="" OUT="" INPUT="" GATE=0 LIMIT=2000
while [ $# -gt 0 ]; do
  case "$1" in
    --label)     LABELS+=("${2:?--label needs a value}"); shift 2 ;;
    --site)      SITE="${2:?--site needs a value}"; shift 2 ;;
    --since)     SINCE="${2:?--since needs a date}"; shift 2 ;;
    --until)     UNTIL="${2:?--until needs a date}"; shift 2 ;;
    --milestone) MILESTONE="${2:?--milestone needs a value}"; shift 2 ;;
    --repo)      REPO="${2:?--repo needs OWNER/NAME}"; shift 2 ;;
    --out)       OUT="${2:?--out needs a file prefix}"; shift 2 ;;
    --gate)      GATE=1; shift ;;
    --limit)     LIMIT="${2:?--limit needs a number}"; shift 2 ;;
    --input)     INPUT="${2:?--input needs a file}"; shift 2 ;;
    -h|--help)   usage; exit 0 ;;
    *)           echo "unknown option: $1" >&2; usage >&2; exit 64 ;;
  esac
done
[ "${#LABELS[@]}" -gt 0 ] || LABELS=(defect)

for d in "$SINCE" "$UNTIL"; do
  if [ -n "$d" ] && ! [[ "$d" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
    echo "dates must be YYYY-MM-DD: $d" >&2; exit 64
  fi
done
if [ -n "$SITE" ] && ! [[ "$SITE" =~ ^[a-z]+$ ]]; then
  echo "--site must be a site slug such as tmh" >&2; exit 64
fi
[[ "$LIMIT" =~ ^[0-9]+$ ]] || { echo "--limit must be a number" >&2; exit 64; }
if [ -n "$INPUT" ]; then
  [ -r "$INPUT" ] || { echo "cannot read $INPUT" >&2; exit 66; }
  command -v jq >/dev/null || { echo "jq is required with --input" >&2; exit 69; }
else
  command -v gh >/dev/null || { echo "the GitHub CLI (gh) is required: https://cli.github.com/" >&2; exit 69; }
fi

FIELDS="number,title,state,stateReason,labels,createdAt,closedAt,url,assignees,body"
args=(issue list --state all --limit "$LIMIT" --json "$FIELDS")
for label in "${LABELS[@]}"; do args+=(--label "$label"); done
[ -z "$REPO" ] || args+=(--repo "$REPO")
[ -z "$MILESTONE" ] || args+=(--milestone "$MILESTONE")

# One tab-separated row per issue:
# number state severity priority site created closed hours_to_close assignees title url
# shellcheck disable=SC2016  # $l, $sev ... are jq variables, not shell variables
JQ='
.[] |
([.labels[].name]) as $l |
(.body // "") as $b |
(($l | map(select(startswith("severity:"))) | .[0] // "" | sub("severity:"; ""))) as $sevl |
(($l | map(select(startswith("priority:"))) | .[0] // "" | sub("priority:"; ""))) as $pril |
(($l | map(select(startswith("site:"))) | .[0] // "" | sub("site:"; ""))) as $sitel |
(if $sevl != "" then $sevl else (($b | capture("Proposed severity[^\n]*\n+\\s*(?<s>S[1-4])") | .s) // "unclassified") end) as $sev |
(if $pril != "" then $pril else (($b | capture("(?<p>P[1-4]) (Critical|High|Medium|Low)") | .p) // "") end) as $pri |
(if $sitel != "" then $sitel
 elif ($b | test("### Website\\s+All websites")) then "all"
 else (($b | capture("### Website\\s+[^\n]*\\((?<s>[a-z]+)\\)") | .s) // "unknown") end) as $site |
(if .state == "OPEN" then (if ($l | any(. == "status:fixed-awaiting-retest")) then "fixed-awaiting-retest" else "open" end)
 elif .stateReason == "NOT_PLANNED" then "rejected"
 else "closed" end) as $status |
[ (.number | tostring), $status, $sev, $pri, $site,
  .createdAt[0:10], ((.closedAt // "")[0:10]),
  (if .closedAt then ((((.closedAt | fromdateiso8601) - (.createdAt | fromdateiso8601)) / 360 | floor) / 10 | tostring) else "" end),
  ([.assignees[].login] | join(" ")),
  (.title | gsub("[\t\r\n]"; " ")), .url ] | @tsv'

if [ -n "$INPUT" ]; then
  # gh filters by label on the server; apply the same "all labels must match" rule to the file.
  # shellcheck disable=SC2016  # $l, $w, $ARGS are jq variables
  ROWS="$(jq 'map(select([.labels[].name] as $l | all($ARGS.positional[]; . as $w | ($l | any(. == $w)))))' \
    "$INPUT" --args "${LABELS[@]}" | jq -r "$JQ")"
else
  ROWS="$(gh "${args[@]}" --jq "$JQ")"
fi

# Period and site filters (dates compare as YYYY-MM-DD strings).
ROWS="$(printf '%s\n' "$ROWS" | awk -F'\t' -v since="$SINCE" -v until="$UNTIL" -v site="$SITE" '
  NF == 0 { next }
  until != "" && $6 > until { next }
  since != "" && $7 != "" && $7 < since { next }
  site != "" && $5 != site && $5 != "all" { next }
  { print }')"

TOTAL="$(printf '%s\n' "$ROWS" | awk 'NF { n++ } END { print n + 0 }')"
BLOCKING="$(printf '%s\n' "$ROWS" | awk -F'\t' '($2 == "open" || $2 == "fixed-awaiting-retest") && ($3 == "S1" || $3 == "S2") { n++ } END { print n + 0 }')"
if [ -n "$INPUT" ]; then
  REPO_NAME="${REPO:-file $INPUT}"
else
  REPO_NAME="${REPO:-$(gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null || echo "current repository")}"
fi

csv() {
  printf 'number,status,severity,priority,site,created,closed,hours_to_close,assignees,title,url\n'
  printf '%s\n' "$ROWS" | awk -F'\t' 'NF {
    line = ""
    for (i = 1; i <= NF; i++) { v = $i; gsub(/"/, "\"\"", v); if (v ~ /^[=+@-]/) v = "'"'"'" v; line = line (i > 1 ? "," : "") "\"" v "\"" }
    print line }'
}

# shellcheck disable=SC2016  # the table conditions are awk expressions, not shell expansions
markdown() {
  echo "# Defect Closure Report"
  echo
  echo "| Item | Value |"
  echo "|---|---|"
  echo "| Repository | ${REPO_NAME} |"
  echo "| Labels | ${LABELS[*]} |"
  echo "| Website | ${SITE:-all websites} |"
  echo "| Period | ${SINCE:-start} to ${UNTIL:-today} |"
  [ -z "$MILESTONE" ] || echo "| Milestone | ${MILESTONE} |"
  echo "| Generated | $(date '+%d/%m/%Y %H:%M %Z') |"
  echo "| Items in report | ${TOTAL} |"
  if [ "$BLOCKING" -eq 0 ]; then
    echo "| Go-Live rule (no open S1/S2) | **Met** |"
  else
    echo "| Go-Live rule (no open S1/S2) | **Not met: ${BLOCKING} open S1/S2 item(s)** |"
  fi
  echo
  echo "## 1. Summary by severity"
  echo
  echo "| Severity | Total | Open | Fixed, awaiting re-test | Closed | Rejected / not planned |"
  echo "|---|---|---|---|---|---|"
  printf '%s\n' "$ROWS" | awk -F'\t' '
    NF { t[$3]++; s[$3, $2]++; T++; S[$2]++ }
    END {
      split("S1 S2 S3 S4 unclassified", order, " ")
      for (i = 1; i <= 5; i++) { k = order[i]
        printf "| %s | %d | %d | %d | %d | %d |\n", k, t[k], s[k, "open"], s[k, "fixed-awaiting-retest"], s[k, "closed"], s[k, "rejected"] }
      printf "| **Total** | **%d** | **%d** | **%d** | **%d** | **%d** |\n", T, S["open"], S["fixed-awaiting-retest"], S["closed"], S["rejected"]
    }'
  table() { # title, awk condition on status/severity
    echo
    echo "## $1"
    echo
    local body
    body="$(printf '%s\n' "$ROWS" | awk -F'\t' "NF && ($2) {
      t = \$10; gsub(/\\|/, \"\\\\|\", t)
      printf \"| [#%s](%s) | %s | %s | %s | %s | %s | %s | %s |\\n\", \$1, \$11, t, \$3, \$4, \$5, \$6, (\$7 == \"\" ? \"—\" : \$7 \" (\" \$8 \" h)\"), (\$9 == \"\" ? \"unassigned\" : \$9) }")"
    if [ -z "$body" ]; then
      echo "None."
    else
      echo "| # | Title | Severity | Priority | Site | Logged | Closed (elapsed) | Owner |"
      echo "|---|---|---|---|---|---|---|---|"
      printf '%s\n' "$body"
    fi
  }
  table "2. Open S1 and S2 items (must be none for Go-Live)" '($2 == "open" || $2 == "fixed-awaiting-retest") && ($3 == "S1" || $3 == "S2")'
  table "3. Other open items (S3, S4, unclassified): each needs TMC IT agreement and a fix date" '($2 == "open" || $2 == "fixed-awaiting-retest") && $3 != "S1" && $3 != "S2"'
  table "4. Closed items" '$2 == "closed"'
  table "5. Rejected / not planned (reason recorded in the ticket)" '$2 == "rejected"'
  echo
  echo "Elapsed hours are calendar hours between logging and closing, for information; SLA compliance in"
  echo "business hours is assessed in the monthly support report."
  echo
  echo "## 6. Sign-off"
  echo
  echo "| | Vendor QA / Testing Engineer | Vendor Project Lead | TMC IT (UAT lead) |"
  echo "|---|---|---|---|"
  echo "| Name | | | |"
  echo "| Date | | | |"
  echo "| Signature | | | |"
}

if [ -n "$OUT" ]; then
  mkdir -p "$(dirname "$OUT")"
  markdown > "$OUT.md"
  csv > "$OUT.csv"
  echo "==> ${OUT}.md and ${OUT}.csv (${TOTAL} items, ${BLOCKING} open S1/S2)"
else
  markdown
fi

if [ "$GATE" -eq 1 ] && [ "$BLOCKING" -gt 0 ]; then
  echo "==> Go-Live gate: ${BLOCKING} open S1/S2 item(s)" >&2
  exit 2
fi
