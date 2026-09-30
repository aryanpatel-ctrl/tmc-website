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

| Component | Version / reference | Where defined | Licence | Upstream |
|---|---|---|---|---|
| MariaDB Server | `mariadb:11.4.13` (exact) | `backup/Dockerfile` | GPL-2.0-only | https://hub.docker.com/_/mariadb |
| MariaDB Server | `mariadb:11.4.13` (exact) | `docker-compose.yml` | GPL-2.0-only | https://hub.docker.com/_/mariadb |
| OpenSSH client (backup image, off-host copy) | Debian package of the base image | `backup/Dockerfile` | BSD-2-Clause and other permissive licences (OpenSSH) | https://www.openssh.com/ |
| PHP CLI (official image) | `php:8.3.35-cli-alpine` (exact) | `docker-compose.yml` | PHP-3.01 | https://hub.docker.com/_/php |
| Polylang (WordPress plugin, multilingual) | 3.8.10 (exact) | `scripts/setup.sh` | GPL-3.0-or-later | https://wordpress.org/plugins/polylang/ |
| Poppler utilities (pdftotext, document text for site search) | 25.03.0 | `wordpress/Dockerfile` | GPL-2.0-only OR GPL-3.0-only | https://poppler.freedesktop.org/ |
| Redis Object Cache (WordPress plugin, object-cache drop-in) | 3.0.0 (exact) | `scripts/setup-cache.sh` | GPL-3.0-or-later | https://wordpress.org/plugins/redis-cache/ |
| Two Factor (WordPress plugin, TOTP and backup codes) | 0.17.0 (exact) | `scripts/setup.sh` | GPL-2.0-or-later | https://wordpress.org/plugins/two-factor/ |
| Valkey (Redis-compatible cache) | `valkey/valkey:8.1.10-alpine` (exact) | `docker-compose.yml` | BSD-3-Clause | https://github.com/valkey-io/valkey |
| WP-CLI with PHP (official image) | `wordpress:cli-2.12.0-php8.3` (exact) | `docker-compose.yml` | MIT (WP-CLI); PHP-3.01 (PHP) | https://hub.docker.com/_/wordpress |
| WordPress core, PHP and Apache httpd (official image) | `wordpress:7.1.2-php8.3-apache` (exact) | `wordpress/Dockerfile` | GPL-2.0-or-later (WordPress); PHP-3.01 (PHP); Apache-2.0 (httpd); Debian packages under their own free licences | https://hub.docker.com/_/wordpress |
| WordPress translations hi_IN and en_GB (core and plugins) | installed by provisioning | `scripts/setup.sh` | GPL-2.0-or-later (as the software translated) | https://translate.wordpress.org/ |
| phpredis PHP extension | 6.3.0 | `wordpress/Dockerfile` | PHP-3.01 | https://pecl.php.net/package/redis |
| rsync (backup image, incremental file snapshots) | Debian package of the base image | `backup/Dockerfile` | GPL-3.0-or-later | https://rsync.samba.org/ |

## 3. Assets bundled with the theme

| Component | Version / reference | Where defined | Licence | Upstream |
|---|---|---|---|---|
| Noto Sans (variable font, Latin subset) | noto-sans-latin-wght.woff2 | `src/themes/tmc/assets/fonts/noto-sans-latin-wght.woff2` | OFL-1.1 | https://github.com/notofonts/latin-greek-cyrillic |
| Noto Sans Devanagari (variable font) | noto-sans-devanagari-wght.woff2 | `src/themes/tmc/assets/fonts/noto-sans-devanagari-wght.woff2` | OFL-1.1 | https://github.com/notofonts/devanagari |

## 4. Project code

| Component | Path | Licence | Owner |
|---|---|---|---|
| TMC Core must-use plugin (roles, workflow, audit log, content types, expiry, and modules added by work streams) | `src/mu-plugins/tmc-core.php`, `src/mu-plugins/tmc-core/` | GPL-2.0-or-later | Tata Memorial Centre |
| TMC theme (templates, blocks, patterns, styles, scripts, translations) | `src/themes/tmc/` | GPL-2.0-or-later | Tata Memorial Centre |
| Provisioning, deployment, test and report scripts; container configuration; documentation | `scripts/`, `wordpress/`, `nginx/`, `docker-compose.yml`, `docs/` | GPL-2.0-or-later (code); documentation © Tata Memorial Centre | Tata Memorial Centre |

## 5. Build, CI and documentation tools (not deployed)

| Component | Version / reference | Where defined | Licence | Upstream |
|---|---|---|---|---|
| GitHub Action actions/checkout | `actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09` (exact commit) | `.github/workflows/load-test.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09` (exact commit) | `.github/workflows/quality.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09` (exact commit) | `.github/workflows/security.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5.0.0` (exact) | `.github/workflows/docs.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/apps-gateway.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/capacity.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/dr-drill.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/ops-checks.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/pipeline.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/release.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/updates.yml` | MIT | https://github.com/actions/checkout |
| GitHub Action actions/download-artifact | `actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c` (exact commit) | `.github/workflows/quality.yml` | MIT | https://github.com/actions/download-artifact |
| GitHub Action actions/setup-node | `actions/setup-node@249970729cb0ef3589644e2896645e5dc5ba9c38` (exact commit) | `.github/workflows/quality.yml` | MIT | https://github.com/actions/setup-node |
| GitHub Action actions/upload-artifact | `actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a` (exact commit) | `.github/workflows/load-test.yml` | MIT | https://github.com/actions/upload-artifact |
| GitHub Action actions/upload-artifact | `actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a` (exact commit) | `.github/workflows/quality.yml` | MIT | https://github.com/actions/upload-artifact |
| GitHub Action actions/upload-artifact | `actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a` (exact commit) | `.github/workflows/security.yml` | MIT | https://github.com/actions/upload-artifact |
| GitHub Action actions/upload-artifact | `actions/upload-artifact@v4.6.2` (exact) | `.github/workflows/docs.yml` | MIT | https://github.com/actions/upload-artifact |
| GitHub Action actions/upload-artifact | `actions/upload-artifact@v4` (series tag) | `.github/workflows/capacity.yml` | MIT | https://github.com/actions/upload-artifact |
| GitHub Action actions/upload-artifact | `actions/upload-artifact@v4` (series tag) | `.github/workflows/dr-drill.yml` | MIT | https://github.com/actions/upload-artifact |
| Gitleaks (secret scanner) | `ghcr.io/gitleaks/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f` (exact) | `.github/workflows/security.yml` | MIT | https://github.com/gitleaks/gitleaks |
| Grafana k6 (load testing) | `grafana/k6:1.8.1` (exact) | `.github/workflows/ops-checks.yml` | AGPL-3.0-only (used as a test tool; not distributed or deployed) | https://github.com/grafana/k6 |
| Grafana k6 (load testing) | `grafana/k6:2.3.0` (exact) | `.github/workflows/load-test.yml` | AGPL-3.0-only (used as a test tool; not distributed or deployed) | https://github.com/grafana/k6 |
| Grafana k6 (load testing) | `grafana/k6:2.3.0` (exact) | `.github/workflows/quality.yml` | AGPL-3.0-only (used as a test tool; not distributed or deployed) | https://github.com/grafana/k6 |
| Mermaid CLI (diagram rendering) | `minlag/mermaid-cli:11.4.2` (exact) | `scripts/docs/build-docs.sh` | MIT (mermaid-cli, mermaid); bundled Chromium under BSD-3-Clause and other free licences | https://github.com/mermaid-js/mermaid-cli |
| Node.js (official image) | `node:24.21.0-alpine` (exact) | `.github/workflows/load-test.yml` | MIT (Node.js); Alpine Linux packages under their own free licences | https://hub.docker.com/_/node |
| OWASP ZAP (dynamic application security testing) | `ghcr.io/zaproxy/zaproxy:2.17.0@sha256:781a2bdaea47324e7bab583e2263f21d257b0aee61ed51521a5be45f5f5081ef` (exact) | `.github/workflows/security.yml` | Apache-2.0 | https://github.com/zaproxy/zaproxy |
| PHP CLI (official image) | `php:8.3-cli` (series tag) | `scripts/lint.sh` | PHP-3.01 | https://hub.docker.com/_/php |
| PHP CLI (official image) | `php:8.3.35-cli-alpine` (exact) | `.github/workflows/apps-gateway.yml` | PHP-3.01 | https://hub.docker.com/_/php |
| Pandoc (with TeX Live) | `pandoc/latex:3.11.0.0-debian` (exact) | `scripts/docs/build-docs.sh` | GPL-2.0-or-later (pandoc); TeX Live packages under free licences (LPPL-1.3c, GPL, OFL and others) | https://github.com/pandoc/dockerfiles |
| Playwright with browsers (official image) | `mcr.microsoft.com/playwright:v1.63.0-noble` (exact) | `tests/ci/run-gate.sh` | Apache-2.0 (Playwright); Chromium BSD-3-Clause, Firefox MPL-2.0, WebKit LGPL-2.1/BSD; Ubuntu packages under their own free licences | https://github.com/microsoft/playwright |
| Trivy (container image vulnerability scanner) | `aquasec/trivy:0.74.0@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969` (exact) | `.github/workflows/security.yml` | Apache-2.0 | https://github.com/aquasecurity/trivy |
| npm package @axe-core/playwright (quality gates) | 4.13.0 (exact) | `tests/package.json` | MPL-2.0 | https://github.com/dequelabs/axe-core-npm |
| npm package @lhci/cli (quality gates) | 0.15.1 (exact) | `tests/package.json` | Apache-2.0 | https://github.com/GoogleChrome/lighthouse-ci |
| npm package @playwright/test (quality gates) | 1.63.0 (exact) | `tests/package.json` | Apache-2.0 | https://github.com/microsoft/playwright |
| npm package axe-core (quality gates) | 4.13.0 (exact) | `tests/package.json` | MPL-2.0 | https://github.com/dequelabs/axe-core-npm |
| npm package vnu-jar (quality gates) | 26.9.27 (exact) | `tests/package.json` | MIT | https://github.com/validator/validator |

Tools installed on the CI runner or workstation and called by the scripts:

| Component | Version / reference | Where defined | Licence | Upstream |
|---|---|---|---|---|
| Docker Engine and Docker Compose v2 | host installation | `scripts/*.sh` | Apache-2.0 | https://github.com/docker |
| GitHub Actions runner (self-hosted deploy runner) | per GitHub release | `.github/workflows/pipeline.yml` | MIT | https://github.com/actions/runner |
| GitHub CLI (gh) | runner image | `scripts/reports/*.sh` | MIT | https://github.com/cli/cli |
| Node.js (JavaScript syntax check) | runner image | `scripts/lint.sh` | MIT | https://nodejs.org/ |
| Python 3 (JSON check, documentation link check) | runner image | `scripts/lint.sh, scripts/docs/check-links.py` | PSF-2.0 | https://www.python.org/ |
| ShellCheck (used by scripts/lint.sh when installed) | runner image | `scripts/lint.sh` | GPL-3.0-or-later | https://github.com/koalaman/shellcheck |
| jq | runner image | `scripts/reports/defect-closure-report.sh --input` | MIT | https://github.com/jqlang/jq |

## 6. Findings

None.

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
