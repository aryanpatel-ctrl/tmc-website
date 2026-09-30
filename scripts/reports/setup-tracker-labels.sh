#!/usr/bin/env bash
# Create (or update) the issue-tracker labels used by the issue forms and by
# scripts/reports/defect-closure-report.sh (Test Plan §10.1). Safe to re-run.
#
#   scripts/reports/setup-tracker-labels.sh [--repo OWNER/NAME]
#
# Requires the GitHub CLI (gh) logged in with permission to manage labels on the repository.
set -euo pipefail

REPO_ARGS=()
if [ "${1:-}" = "--repo" ]; then
  REPO_ARGS=(--repo "${2:?--repo needs OWNER/NAME}")
fi
command -v gh >/dev/null || { echo "the GitHub CLI (gh) is required" >&2; exit 69; }

# name|colour|description
LABELS=(
  "defect|d73a4a|Deviation found in testing or UAT"
  "change-request|5319e7|Change Request under SOW §14 (needs TMC IT written approval)"
  "incident|b60205|Support incident or service request (warranty / AMC)"
  "severity:S1|b60205|Critical: unavailable, unsafe, security, data loss, accessibility blocker"
  "severity:S2|d93f0b|High: major function fails, no workaround"
  "severity:S3|fbca04|Medium: workaround exists, or one template/browser"
  "severity:S4|c5def5|Low: cosmetic"
  "priority:P1|b60205|Fix first (Critical: 4 business hours after Go-Live)"
  "priority:P2|d93f0b|High (1 business day after Go-Live)"
  "priority:P3|fbca04|Medium (3 business days after Go-Live)"
  "priority:P4|c5def5|Low / next planned release"
  "site:tmc|0e6b63|Tata Memorial Centre"
  "site:tmh|0e6b63|Tata Memorial Hospital, Mumbai"
  "site:hbchrcv|0e6b63|HBCH & RC, Visakhapatnam"
  "site:mpmmcc|0e6b63|MPMMCC & HBCH, Varanasi"
  "site:hbchrcmzp|0e6b63|HBCH & RC, Muzaffarpur"
  "site:hbchpunjab|0e6b63|HBCH, New Chandigarh"
  "site:all|0e6b63|All websites / CMS network"
  "phase:sit|0b3a6e|Found in system/integration testing"
  "phase:uat|0b3a6e|Found in user acceptance testing"
  "phase:production|0b3a6e|Found in Production"
  "status:fixed-awaiting-retest|1d76db|Fix deployed to UAT; waiting for the tester to re-test"
)

for entry in "${LABELS[@]}"; do
  IFS='|' read -r name colour description <<<"$entry"
  gh label create "$name" --color "$colour" --description "$description" --force "${REPO_ARGS[@]+"${REPO_ARGS[@]}"}" >/dev/null
  echo "  $name"
done
echo "==> ${#LABELS[@]} labels in place"
