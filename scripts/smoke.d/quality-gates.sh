# shellcheck shell=bash
# W6 quality gates: regression checks for defects found by the browser and validator gates (tests/).
# Sourced by scripts/smoke-test.sh, which provides check() and TMC_BASE_DOMAIN.

# Find a Doctor: a name without matches stays on the listing. The field used to be called "name",
# a reserved WordPress query variable, which turned every name search into a 404 or a redirect.
check "$TMC_BASE_DOMAIN" "/doctors/?doctor_name=qqxxzz-no-such-doctor" 200 'filter-form' '0 doctors found.'
check "tmh.$TMC_BASE_DOMAIN" "/doctors/?doctor_name=Sample" 200 'filter-form' 'doctor-card'

# The quality-fixes stylesheet (contrast of hints in buttons, search box on phones) is loaded.
check "$TMC_BASE_DOMAIN" "/events/sample-cme-session/" 200 'features/quality-fixes.css'

# WordPress default content is gone and the footer Privacy Policy link works (migration 060).
check "$TMC_BASE_DOMAIN" "/privacy-policy/" 200 'personal information'
check "$TMC_BASE_DOMAIN" "/" 200 "/privacy-policy/\">Privacy Policy</a>"
check "tmh.$TMC_BASE_DOMAIN" "/sample-page/" 404
check "tmh.$TMC_BASE_DOMAIN" "/hello-world/" 404
