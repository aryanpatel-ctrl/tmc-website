# shellcheck shell=bash
# W3 — SEO metadata, structured data, sitemaps and crawl directives (tender §4.10).
# Sourced by scripts/smoke-test.sh, which provides check(), FAILED and TMC_BASE_DOMAIN.

seo_absent() { # host path needle — the response body must NOT contain needle
  local body
  body="$(curl -s -m 20 -H "Host: $1" "${ORIGIN:-http://127.0.0.1}$2")"
  if grep -qF -- "$3" <<<"$body"; then
    printf '  FAIL  %-48s %s\n' "$1$2" "unexpected '$3'"
    FAILED=1
  else
    printf '  ok    %-48s %s\n' "$1$2" "without '$3'"
  fi
}

seo_header() { # host path header-text — a response header must contain header-text
  local headers
  headers="$(curl -s -m 20 -o /dev/null -D - -H "Host: $1" "${ORIGIN:-http://127.0.0.1}$2")"
  if grep -qiF -- "$3" <<<"$headers"; then
    printf '  ok    %-48s %s\n' "$1$2" "header '$3'"
  else
    printf '  FAIL  %-48s %s\n' "$1$2" "missing header '$3'"
    FAILED=1
  fi
}

SEO_UNIT="tmh.$TMC_BASE_DOMAIN"

# Metadata and organisation / website structured data
check "$SEO_UNIT" "/" 200 '<meta name="description"' '<link rel="canonical" href="' 'property="og:title"' 'name="twitter:card"' 'application/ld+json' '"@type":"Hospital"' '"parentOrganization"' '"SearchAction"'
check "$TMC_BASE_DOMAIN" "/" 200 '"GovernmentOrganization"' '"MedicalOrganization"' 'Department of Atomic Energy'
check "$TMC_BASE_DOMAIN" "/hi/" 200 'property="og:locale" content="hi_IN"' '"inLanguage":"hi-IN"'
check "$TMC_BASE_DOMAIN" "/about-us/history/" 200 '"BreadcrumbList"' '"ListItem"' 'property="og:type" content="website"'
check "$SEO_UNIT" "/tenders/" 200 '"CollectionPage"'
seo_absent "$TMC_BASE_DOMAIN" "/events/sample-cme-session/" '"@type":"Event"'   # sample items carry no structured data

# XML sitemaps: content types in, user list out, sample items out
check "$TMC_BASE_DOMAIN" "/wp-sitemap.xml" 200 '<sitemapindex' 'wp-sitemap-posts-page-1.xml' 'wp-sitemap-posts-tmc_department-1.xml'
seo_absent "$TMC_BASE_DOMAIN" "/wp-sitemap.xml" 'wp-sitemap-users'
check "$TMC_BASE_DOMAIN" "/wp-sitemap-users-1.xml" 404
seo_absent "$TMC_BASE_DOMAIN" "/wp-sitemap-posts-tmc_event-1.xml" 'sample-cme-session'

# Crawl directives follow the environment
if [ "${TMC_ENV:-}" = "production" ]; then
  check "$TMC_BASE_DOMAIN" "/robots.txt" 200 'Sitemap: ' 'Disallow: /wp-admin/'
  seo_absent "$TMC_BASE_DOMAIN" "/" 'noindex, nofollow'
else
  check "$TMC_BASE_DOMAIN" "/robots.txt" 200 'Disallow: /'
  seo_absent "$TMC_BASE_DOMAIN" "/robots.txt" 'Sitemap:'
  seo_header "$TMC_BASE_DOMAIN" "/" 'X-Robots-Tag: noindex'
  check "$SEO_UNIT" "/" 200 "noindex"
fi
