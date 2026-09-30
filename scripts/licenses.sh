#!/usr/bin/env bash
# Third-party component and licence inventory (SOW §8.2: "a list of all third-party components, modules,
# plugins, and libraries used, with their license details"; SOW §13: legally licensed, OSS terms
# compatible with Government deployment and transfer).
#
#   scripts/licenses.sh            regenerate docs/THIRD-PARTY-LICENSES.md
#   scripts/licenses.sh --stdout   print the inventory instead of writing it
#   scripts/licenses.sh --check    fail if the committed file is out of date or a component is unknown
#   scripts/licenses.sh --check --strict   also fail while any licence finding is open
#
# Components are discovered from the repository itself, so the inventory follows the code:
#   container images   docker-compose.yml, compose.*.yml, wordpress/Dockerfile, images used by scripts
#                      (docker run …, *_IMAGE=…) and by workflows
#   CI actions         "uses:" in .github/workflows/*.yml
#   WordPress plugins  pinned versions in scripts/setup.sh (POLYLANG_VERSION)
#   fonts              src/themes/tmc/assets/fonts/*.woff2
# Licences come from the table in licence_of() below; anything not in it is reported as UNKNOWN and makes
# --check fail, so a new dependency cannot be merged without its licence being recorded here.
# The output contains no dates, so it only changes when the components change.
set -euo pipefail
cd "$(dirname "$0")/.."
export LC_ALL=C   # identical sort order on every machine, so --check is reproducible

OUT="docs/THIRD-PARTY-LICENSES.md"
MODE="write"
STRICT=0
for arg in "$@"; do
  case "$arg" in
    --check)  MODE="check" ;;
    --stdout) MODE="stdout" ;;
    --strict) STRICT=1 ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 64 ;;
  esac
done

ROWS="$(mktemp)"
FINDINGS="$(mktemp)"
trap 'rm -f "$ROWS" "$FINDINGS" "${ROWS}.new"' EXIT

# row CATEGORY COMPONENT VERSION WHERE LICENCE UPSTREAM
row() { printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$@" >> "$ROWS"; }
finding() { printf '%s\t%s\n' "$1" "$2" >> "$FINDINGS"; }

# Exact version (e.g. 3.8.10, 11.4.3, v4.2.2, 3.11.0.0-debian) versus a moving series tag (7-alpine, 11.4, v5).
pin_state() {
  if [[ "$1" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+ || "$1" =~ ^[a-z]+-[0-9]+\.[0-9]+\.[0-9]+(-|$) ]]; then echo "exact";
  elif [[ "$1" =~ ^[0-9a-f]{40}$ ]]; then echo "exact commit";
  else echo "series tag"; fi
}

# licence_of NAME TAG → "component|licence|upstream|finding" (finding empty when none)
licence_of() {
  local name="$1" tag="$2"
  case "$name" in
    wordpress)
      case "$tag" in
        cli*) echo "WP-CLI with PHP (official image)|MIT (WP-CLI); PHP-3.01 (PHP)|https://hub.docker.com/_/wordpress|" ;;
        *)    echo "WordPress core, PHP and Apache httpd (official image)|GPL-2.0-or-later (WordPress); PHP-3.01 (PHP); Apache-2.0 (httpd); Debian packages under their own free licences|https://hub.docker.com/_/wordpress|" ;;
      esac ;;
    mariadb) echo "MariaDB Server|GPL-2.0-only|https://hub.docker.com/_/mariadb|" ;;
    redis)
      if [[ "$tag" =~ ^(6|7\.0|7\.2)([.-]|$) ]]; then
        echo "Redis|BSD-3-Clause|https://hub.docker.com/_/redis|"
      else
        echo "Redis|RSALv2 OR SSPL-1.0 for 7.4 and later (8.x adds an AGPL-3.0 option); BSD-3-Clause only up to 7.2|https://hub.docker.com/_/redis|The tag '$tag' resolves to Redis 7.4 or later, which is not under an OSI-approved licence. Replace with valkey/valkey (BSD-3-Clause, drop-in compatible) or pin redis:7.2.x (BSD-3-Clause); or use Redis 8.x under AGPL-3.0 after TMC's legal review."
      fi ;;
    valkey/valkey) echo "Valkey (Redis-compatible cache)|BSD-3-Clause|https://github.com/valkey-io/valkey|" ;;
    php) echo "PHP CLI (official image)|PHP-3.01|https://hub.docker.com/_/php|" ;;
    pandoc/latex|pandoc/core|pandoc/extra) echo "Pandoc (with TeX Live)|GPL-2.0-or-later (pandoc); TeX Live packages under free licences (LPPL-1.3c, GPL, OFL and others)|https://github.com/pandoc/dockerfiles|" ;;
    minlag/mermaid-cli) echo "Mermaid CLI (diagram rendering)|MIT (mermaid-cli, mermaid); bundled Chromium under BSD-3-Clause and other free licences|https://github.com/mermaid-js/mermaid-cli|" ;;
    koalaman/shellcheck) echo "ShellCheck|GPL-3.0-or-later|https://github.com/koalaman/shellcheck|" ;;
    aquasec/trivy) echo "Trivy (container image vulnerability scanner)|Apache-2.0|https://github.com/aquasecurity/trivy|" ;;
    ghcr.io/gitleaks/gitleaks) echo "Gitleaks (secret scanner)|MIT|https://github.com/gitleaks/gitleaks|" ;;
    ghcr.io/zaproxy/zaproxy) echo "OWASP ZAP (dynamic application security testing)|Apache-2.0|https://github.com/zaproxy/zaproxy|" ;;
    grafana/k6) echo "Grafana k6 (load testing)|AGPL-3.0-only (used as a test tool; not distributed or deployed)|https://github.com/grafana/k6|" ;;
    node) echo "Node.js (official image)|MIT (Node.js); Alpine Linux packages under their own free licences|https://hub.docker.com/_/node|" ;;
    mcr.microsoft.com/playwright) echo "Playwright with browsers (official image)|Apache-2.0 (Playwright); Chromium BSD-3-Clause, Firefox MPL-2.0, WebKit LGPL-2.1/BSD; Ubuntu packages under their own free licences|https://github.com/microsoft/playwright|" ;;
    actions/checkout|actions/upload-artifact|actions/download-artifact|actions/cache|actions/setup-node|actions/setup-python|actions/setup-java|actions/github-script)
      echo "GitHub Action ${name}|MIT|https://github.com/${name}|" ;;
    *) echo "${name}|UNKNOWN|—|No licence recorded in scripts/licenses.sh for '${name}'. Add it to licence_of() after checking the upstream licence." ;;
  esac
}

add_image() { # REF WHERE CATEGORY
  local ref="$1" where="$2" category="$3" name tag info component licence upstream note
  ref="${ref#docker://}"
  name="${ref%%:*}"; tag="${ref#*:}"; [ "$tag" != "$ref" ] || tag="latest"
  name="${name#docker.io/}"; name="${name#library/}"
  [ "$name" != "tmc-wordpress" ] || return 0   # built from wordpress/Dockerfile (listed via its base image)
  [ "$name" != "tmc-backup" ] || return 0      # built from backup/Dockerfile (listed via its base image)
  info="$(licence_of "$name" "$tag")"
  IFS='|' read -r component licence upstream note <<<"$info"
  row "$category" "$component" "\`${name}:${tag}\` ($(pin_state "$tag"))" "$where" "$licence" "$upstream"
  [ -z "$note" ] || finding "$([ "$licence" = "UNKNOWN" ] && echo UNKNOWN || echo ACTION)" "$note"
  if [ "$category" = "Runtime" ] && [ "$(pin_state "$tag")" != "exact" ]; then
    finding "PIN" "\`${name}:${tag}\` ($where) is a series tag; pin an exact version (or digest) so every environment runs the tested build (Patch Management, observation O-3)."
  fi
}

# ---------------------------------------------------------------- runtime (deployed)
for file in docker-compose.yml compose.*.yml; do
  [ -f "$file" ] || continue
  sed -n 's/^[[:space:]]*image:[[:space:]]*["'"'"']\{0,1\}\([^"'"'"'[:space:]]*\).*/\1/p' "$file" | sort -u |
    while read -r ref; do add_image "$ref" "$file" "Runtime"; done
done
for dockerfile in wordpress/Dockerfile backup/Dockerfile; do
  [ -f "$dockerfile" ] || continue
  sed -n 's/^FROM[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' "$dockerfile" | sort -u |
    while read -r ref; do add_image "$ref" "$dockerfile" "Runtime"; done
done
if grep -q 'poppler-utils' wordpress/Dockerfile; then
  version="$(sed -n "s/.*poppler-utils=\([0-9][0-9.]*\).*/\1/p" wordpress/Dockerfile | head -1)"
  row "Runtime" "Poppler utilities (pdftotext, document text for site search)" "${version:-Debian package (not pinned)}" "wordpress/Dockerfile" "GPL-2.0-only OR GPL-3.0-only" "https://poppler.freedesktop.org/"
fi
if [ -f backup/Dockerfile ]; then
  grep -q 'rsync' backup/Dockerfile && row "Runtime" "rsync (backup image, incremental file snapshots)" "Debian package of the base image" "backup/Dockerfile" "GPL-3.0-or-later" "https://rsync.samba.org/"
  grep -q 'openssh-client' backup/Dockerfile && row "Runtime" "OpenSSH client (backup image, off-host copy)" "Debian package of the base image" "backup/Dockerfile" "BSD-2-Clause and other permissive licences (OpenSSH)" "https://www.openssh.com/"
fi

if grep -q 'pecl install redis' wordpress/Dockerfile; then
  version="$(sed -n 's/.*pecl install redis-\([0-9][0-9.]*\).*/\1/p' wordpress/Dockerfile | head -1)"
  row "Runtime" "phpredis PHP extension" "${version:-latest stable at build time (not pinned)}" "wordpress/Dockerfile" "PHP-3.01" "https://pecl.php.net/package/redis"
  [ -n "$version" ] || finding "PIN" "phpredis is installed with \`pecl install redis\` without a version; pin it (\`pecl install redis-x.y.z\`)."
fi

polylang="$(sed -n 's/^POLYLANG_VERSION="\([^"]*\)".*/\1/p' scripts/setup.sh)"
[ -z "$polylang" ] || row "Runtime" "Polylang (WordPress plugin, multilingual)" "${polylang} (exact)" "scripts/setup.sh" "GPL-3.0-or-later" "https://wordpress.org/plugins/polylang/"
two_factor="$(sed -n 's/^TWO_FACTOR_VERSION="\([^"]*\)".*/\1/p' scripts/setup.sh)"
[ -z "$two_factor" ] || row "Runtime" "Two Factor (WordPress plugin, TOTP and backup codes)" "${two_factor} (exact)" "scripts/setup.sh" "GPL-2.0-or-later" "https://wordpress.org/plugins/two-factor/"
redis_cache="$(sed -n 's/^REDIS_CACHE_VERSION="\([^"]*\)".*/\1/p' scripts/setup-cache.sh 2>/dev/null)"
[ -z "$redis_cache" ] || row "Runtime" "Redis Object Cache (WordPress plugin, object-cache drop-in)" "${redis_cache} (exact)" "scripts/setup-cache.sh" "GPL-3.0-or-later" "https://wordpress.org/plugins/redis-cache/"
row "Runtime" "WordPress translations hi_IN and en_GB (core and plugins)" "installed by provisioning" "scripts/setup.sh" "GPL-2.0-or-later (as the software translated)" "https://translate.wordpress.org/"

# ---------------------------------------------------------------- bundled assets
for font in src/themes/tmc/assets/fonts/*.woff2; do
  [ -f "$font" ] || continue
  case "$(basename "$font")" in
    noto-sans-devanagari*) family="Noto Sans Devanagari (variable font)"; src="https://github.com/notofonts/devanagari" ;;
    noto-sans*)            family="Noto Sans (variable font, Latin subset)"; src="https://github.com/notofonts/latin-greek-cyrillic" ;;
    *)                     family="$(basename "$font")"; src="—" ;;
  esac
  row "Bundled asset" "$family" "$(basename "$font")" "$font" "OFL-1.1" "$src"
done
if ls src/themes/tmc/assets/fonts/noto-sans-devanagari*.woff2 >/dev/null 2>&1 &&
   ! grep -qi 'devanagari' src/themes/tmc/assets/fonts/OFL.txt 2>/dev/null; then
  finding "ACTION" "src/themes/tmc/assets/fonts/OFL.txt carries only the Noto Sans (latin-greek-cyrillic) copyright line; add the copyright notice of Noto Sans Devanagari (github.com/notofonts/devanagari), as the SIL OFL 1.1 requires the notice to accompany each font."
fi

# ---------------------------------------------------------------- CI actions and tool images
for wf in .github/workflows/*.yml; do
  [ -f "$wf" ] || continue
  sed -n 's/^[[:space:]-]*uses:[[:space:]]*\([^[:space:]#]*\).*/\1/p' "$wf" | sort -u | while read -r uses; do
    case "$uses" in
      ./*) continue ;;                                   # reusable workflow in this repository
      docker://*) add_image "$uses" "$wf" "Build and CI tool" ;;
      *)
        name="${uses%@*}"; ref="${uses#*@}"
        info="$(licence_of "$name" "$ref")"
        IFS='|' read -r component licence upstream note <<<"$info"
        row "Build and CI tool" "$component" "\`${uses}\` ($(pin_state "$ref"))" "$wf" "$licence" "$upstream"
        [ -z "$note" ] || finding "UNKNOWN" "$note" ;;
    esac
  done
  # image:/container: keys, *_IMAGE: variables and "docker run … image:tag" commands in steps.
  {
    sed -nE 's/^[[:space:]]*(image|container|[A-Z0-9_]*IMAGE):[[:space:]]*["'"'"']?([^"'"'"'[:space:]]*).*/\2/p' "$wf"
    grep -E '^[^#]*docker run' "$wf" | tr ' ' '\n' | grep -E '^[a-z0-9][a-z0-9._/-]*:[A-Za-z0-9][A-Za-z0-9._-]*(@sha256:[0-9a-f]+)?$' || true
  } | sort -u | while read -r ref; do
    [ -z "$ref" ] || add_image "$ref" "$wf" "Build and CI tool"
  done
done

# Images started by scripts: "docker run … image:tag …" and IMAGE variables such as PANDOC_IMAGE="…".
for script in scripts/*.sh scripts/*/*.sh tests/ci/*.sh; do
  [ -f "$script" ] || continue
  {
    grep -E '^[^#]*docker run' "$script" | tr ' ' '\n' | grep -E '^[a-z0-9][a-z0-9._/-]*:[A-Za-z0-9][A-Za-z0-9._-]*$' || true
    sed -n 's/^[[:space:]]*[A-Z_]*IMAGE="\{0,1\}\([a-z0-9][a-z0-9._/-]*:[A-Za-z0-9][A-Za-z0-9._-]*\)"\{0,1\}.*/\1/p' "$script"
  } | sort -u | while read -r ref; do add_image "$ref" "$script" "Build and CI tool"; done
done

# Quality-gate tooling (tests/package.json, exact versions; installed with npm ci from the lock file).
if [ -f tests/package.json ]; then
  python3 - tests/package.json <<'PY' | while IFS='|' read -r pkg version; do
import json, sys
deps = json.load(open(sys.argv[1])).get("devDependencies", {})
for name in sorted(deps):
    print(name + "|" + deps[name])
PY
    case "$pkg" in
      @playwright/test) lic="Apache-2.0"; up="https://github.com/microsoft/playwright" ;;
      @axe-core/playwright|axe-core) lic="MPL-2.0"; up="https://github.com/dequelabs/axe-core-npm" ;;
      @lhci/cli) lic="Apache-2.0"; up="https://github.com/GoogleChrome/lighthouse-ci" ;;
      vnu-jar) lic="MIT"; up="https://github.com/validator/validator" ;;
      *) lic="UNKNOWN"; up="—"; finding "UNKNOWN" "No licence recorded in scripts/licenses.sh for npm package '$pkg' (tests/package.json)." ;;
    esac
    row "Build and CI tool" "npm package $pkg (quality gates)" "$version ($(pin_state "$version"))" "tests/package.json" "$lic" "$up"
  done
fi

# Host tools the scripts call (installed on the runner or workstation; not distributed).
row "Host tool" "Docker Engine and Docker Compose v2" "host installation" "scripts/*.sh" "Apache-2.0" "https://github.com/docker"
row "Host tool" "ShellCheck (used by scripts/lint.sh when installed)" "runner image" "scripts/lint.sh" "GPL-3.0-or-later" "https://github.com/koalaman/shellcheck"
row "Host tool" "Node.js (JavaScript syntax check)" "runner image" "scripts/lint.sh" "MIT" "https://nodejs.org/"
row "Host tool" "Python 3 (JSON check, documentation link check)" "runner image" "scripts/lint.sh, scripts/docs/check-links.py" "PSF-2.0" "https://www.python.org/"
row "Host tool" "GitHub CLI (gh)" "runner image" "scripts/reports/*.sh" "MIT" "https://github.com/cli/cli"
row "Host tool" "jq" "runner image" "scripts/reports/defect-closure-report.sh --input" "MIT" "https://github.com/jqlang/jq"
row "Host tool" "GitHub Actions runner (self-hosted deploy runner)" "per GitHub release" ".github/workflows/pipeline.yml" "MIT" "https://github.com/actions/runner"

# ---------------------------------------------------------------- render
render_table() { # CATEGORY
  local rows
  rows="$(awk -F'\t' -v c="$1" '$1 == c' "$ROWS" | sort -u)"
  if [ -z "$rows" ]; then echo "None."; return; fi
  echo "| Component | Version / reference | Where defined | Licence | Upstream |"
  echo "|---|---|---|---|---|"
  printf '%s\n' "$rows" | awk -F'\t' '{ printf "| %s | %s | `%s` | %s | %s |\n", $2, $3, $4, $5, $6 }'
}

generate() {
  cat <<'EOF'
# Third-Party Components and Licences

| Document ID | RTM references |
|---|---|
| TMC-WEB-LIC-01 | R-8.2-7, R-4.5-2, R-13-2, R-13-3 |

**Generated file — do not edit by hand.** Regenerate with `scripts/licenses.sh` after any change to a
container image, plugin, font, CI action or tool; `scripts/licenses.sh --check` (CI) fails when this file
is out of date or a component has no recorded licence.

## 1. Statement

The TMC Website Ecosystem uses only free and open-source components. No proprietary, "premium" or
"pro" component, no SaaS dependency and no component licensed to the Vendor is required to build, deploy
or operate the websites. The project's own code (the `tmc-core` must-use plugin and the `tmc` theme) is
licensed under GPL-2.0-or-later and is the property of TMC (SOW §13). All licences listed permit
government use, modification and transfer to TMC or a third party nominated by TMC; open findings are
listed in section 6.

## 2. Runtime components (deployed on every environment)
EOF
  echo
  render_table "Runtime"
  cat <<'EOF'

## 3. Assets bundled with the theme
EOF
  echo
  render_table "Bundled asset"
  cat <<'EOF'

## 4. Project code

| Component | Path | Licence | Owner |
|---|---|---|---|
| TMC Core must-use plugin (roles, workflow, audit log, content types, expiry, and modules added by work streams) | `src/mu-plugins/tmc-core.php`, `src/mu-plugins/tmc-core/` | GPL-2.0-or-later | Tata Memorial Centre |
| TMC theme (templates, blocks, patterns, styles, scripts, translations) | `src/themes/tmc/` | GPL-2.0-or-later | Tata Memorial Centre |
| Provisioning, deployment, test and report scripts; container configuration; documentation | `scripts/`, `wordpress/`, `nginx/`, `docker-compose.yml`, `docs/` | GPL-2.0-or-later (code); documentation © Tata Memorial Centre | Tata Memorial Centre |

## 5. Build, CI and documentation tools (not deployed)
EOF
  echo
  render_table "Build and CI tool"
  echo
  echo "Tools installed on the CI runner or workstation and called by the scripts:"
  echo
  render_table "Host tool"
  cat <<'EOF'

## 6. Findings

EOF
  if [ -s "$FINDINGS" ]; then
    echo "| # | Type | Finding |"
    echo "|---|---|---|"
    sort -u "$FINDINGS" | awk -F'\t' '{ printf "| F-%02d | %s | %s |\n", NR, $1, $2 }'
    echo
    echo "Types: **ACTION** licence or notice issue to resolve; **PIN** version not pinned exactly;"
    echo "**UNKNOWN** licence not recorded (fails the check)."
  else
    echo "None."
  fi
  cat <<'EOF'

## 7. Licence texts

| Licence | Text |
|---|---|
| GPL-2.0 / GPL-2.0-or-later | <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html> |
| GPL-3.0-or-later | <https://www.gnu.org/licenses/gpl-3.0.html> |
| MIT | <https://opensource.org/license/mit> |
| BSD-3-Clause | <https://opensource.org/license/bsd-3-clause> |
| Apache-2.0 | <https://www.apache.org/licenses/LICENSE-2.0> |
| PHP-3.01 | <https://www.php.net/license/3_01.txt> |
| OFL-1.1 | <https://openfontlicense.org/> (copy shipped in `src/themes/tmc/assets/fonts/OFL.txt`) |
| PSF-2.0 | <https://docs.python.org/3/license.html> |
| LPPL-1.3c | <https://www.latex-project.org/lppl/lppl-1-3c/> |
| MPL-2.0 | <https://www.mozilla.org/MPL/2.0/> |
| LGPL-2.1 | <https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html> |
| BSD-2-Clause | <https://opensource.org/license/bsd-2-clause> |
| AGPL-3.0-only (test tool only) | <https://www.gnu.org/licenses/agpl-3.0.html> |
EOF
}

generate > "${ROWS}.new"

case "$MODE" in
  stdout) cat "${ROWS}.new" ;;
  write)  cp "${ROWS}.new" "$OUT"; echo "==> $OUT written ($(awk 'END{print NR}' "$ROWS") components, $(sort -u "$FINDINGS" | awk 'END{print NR}') findings)" ;;
  check)
    status=0
    if ! diff -u "$OUT" "${ROWS}.new"; then
      echo "==> $OUT is out of date: run scripts/licenses.sh and commit the result" >&2; status=1
    fi
    if grep -q '^UNKNOWN' "$FINDINGS"; then
      echo "==> components without a recorded licence (see section 6)" >&2; status=1
    fi
    if [ "$STRICT" -eq 1 ] && [ -s "$FINDINGS" ]; then
      echo "==> --strict: $(sort -u "$FINDINGS" | awk 'END{print NR}') open licence finding(s)" >&2; status=1
    fi
    [ "$status" -ne 0 ] || echo "==> licence inventory up to date"
    exit "$status" ;;
esac
