# Site search and document library (R-4.12-5, R-4.6-6). Sourced by scripts/smoke-test.sh (uses check).
# shellcheck shell=bash
tmh="tmh.${TMC_BASE_DOMAIN}"

# Results page: facets, live count, suggestion-enabled form; words inside the EOI summary PDF (migration 001)
check "$tmh" "/?s=sample"                           200 'search-facets' 'result-list' 'search-result' 'data-tmc-suggest' 'role="status"'
check "$tmh" "/?s=Digital+Library"                  200 'search-result-attachment' 'doc-meta' 'PDF'
check "$tmh" "/?s=sample&type=tmc_tender"           200 'search-result-tmc_tender' 'aria-current="page"'
check "$tmh" "/?s=zzqqxxnomatch"                    200 'search-help'
check "$tmh" "/hi/?s=%E0%A4%A8%E0%A4%AE%E0%A5%82%E0%A4%A8%E0%A4%BE" 200 'lang="hi-IN"' 'search-result'

# Suggestions endpoint
check "$tmh" "/wp-json/tmc/v1/suggest?q=sample&lang=en" 200 '"items"' '"title"' '"url"' '"type"'
check "$tmh" "/wp-json/tmc/v1/suggest?q=s"          400 'tmc_query_too_short'

# Documents library
check "$tmh" "/documents/"                          200 'doc-library' 'doc-filter' 'doc-table' 'Sample tender document'
check "$tmh" "/documents/?doc_type=tender-document" 200 'Sample tender document' 'PDF'
check "$TMC_BASE_DOMAIN" "/documents/"              200 'doc-library' 'doc-filter'
