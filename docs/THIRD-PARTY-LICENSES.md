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
| MariaDB Server | `mariadb:11.4` (series tag) | `docker-compose.yml` | GPL-2.0-only | https://hub.docker.com/_/mariadb |
| Polylang (WordPress plugin, multilingual) | 3.8.10 (exact) | `scripts/setup.sh` | GPL-3.0-or-later | https://wordpress.org/plugins/polylang/ |
| Redis | `redis:7-alpine` (series tag) | `docker-compose.yml` | RSALv2 OR SSPL-1.0 for 7.4 and later (8.x adds an AGPL-3.0 option); BSD-3-Clause only up to 7.2 | https://hub.docker.com/_/redis |
| WP-CLI with PHP (official image) | `wordpress:cli-php8.3` (series tag) | `docker-compose.yml` | MIT (WP-CLI); PHP-3.01 (PHP) | https://hub.docker.com/_/wordpress |
| WordPress core, PHP and Apache httpd (official image) | `wordpress:php8.3-apache` (series tag) | `wordpress/Dockerfile` | GPL-2.0-or-later (WordPress); PHP-3.01 (PHP); Apache-2.0 (httpd); Debian packages under their own free licences | https://hub.docker.com/_/wordpress |
| WordPress translations hi_IN and en_GB (core and plugins) | installed by provisioning | `scripts/setup.sh` | GPL-2.0-or-later (as the software translated) | https://translate.wordpress.org/ |
| phpredis PHP extension | latest stable at build time (not pinned) | `wordpress/Dockerfile` | PHP-3.01 | https://pecl.php.net/package/redis |

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
| GitHub Action actions/checkout | `actions/checkout@v5` (series tag) | `.github/workflows/pipeline.yml` | MIT | https://github.com/actions/checkout |
| Mermaid CLI (diagram rendering) | `minlag/mermaid-cli:11.4.2` (exact) | `scripts/docs/build-docs.sh` | MIT (mermaid-cli, mermaid); bundled Chromium under BSD-3-Clause and other free licences | https://github.com/mermaid-js/mermaid-cli |
| PHP CLI (official image) | `php:8.3-cli` (series tag) | `scripts/lint.sh` | PHP-3.01 | https://hub.docker.com/_/php |
| Pandoc (with TeX Live) | `pandoc/latex:3.11.0.0-debian` (exact) | `scripts/docs/build-docs.sh` | GPL-2.0-or-later (pandoc); TeX Live packages under free licences (LPPL-1.3c, GPL, OFL and others) | https://github.com/pandoc/dockerfiles |

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

| # | Type | Finding |
|---|---|---|
| F-01 | ACTION | The tag '7-alpine' resolves to Redis 7.4 or later, which is not under an OSI-approved licence. Replace with valkey/valkey (BSD-3-Clause, drop-in compatible) or pin redis:7.2.x (BSD-3-Clause); or use Redis 8.x under AGPL-3.0 after TMC's legal review. |
| F-02 | PIN | `mariadb:11.4` (docker-compose.yml) is a series tag; pin an exact version (or digest) so every environment runs the tested build (Patch Management, observation O-3). |
| F-03 | PIN | `redis:7-alpine` (docker-compose.yml) is a series tag; pin an exact version (or digest) so every environment runs the tested build (Patch Management, observation O-3). |
| F-04 | PIN | `wordpress:cli-php8.3` (docker-compose.yml) is a series tag; pin an exact version (or digest) so every environment runs the tested build (Patch Management, observation O-3). |
| F-05 | PIN | `wordpress:php8.3-apache` (wordpress/Dockerfile) is a series tag; pin an exact version (or digest) so every environment runs the tested build (Patch Management, observation O-3). |
| F-06 | PIN | phpredis is installed with `pecl install redis` without a version; pin it (`pecl install redis-x.y.z`). |

Types: **ACTION** licence or notice issue to resolve; **PIN** version not pinned exactly;
**UNKNOWN** licence not recorded (fails the check).

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
