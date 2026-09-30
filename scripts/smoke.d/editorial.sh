# shellcheck shell=bash
# Editorial platform (W5): component library (noindex), audience entry points, feature styles.
# Sourced by scripts/smoke-test.sh, which provides check() and TMC_BASE_DOMAIN.

check "$TMC_BASE_DOMAIN" "/component-library/" 200 'noindex' 'class="cl-toc"' 'cl-grade' 'var(--wp--preset--color--primary)' 'tmc/page-faq' 'notice-board' 'cal-grid'
check "tmh.$TMC_BASE_DOMAIN" "/for-referring-doctors/" 200 'Referring a patient' '/departments/'
check "tmh.$TMC_BASE_DOMAIN" "/students-and-researchers/" 200 'Study and training' '/research/clinical-trials/'
check "tmh.$TMC_BASE_DOMAIN" "/patient-care/" 200 'for-referring-doctors'
check "$TMC_BASE_DOMAIN" "/wp-content/themes/tmc/assets/css/features/editorial.css" 200 'syndicated-note' 'data-contrast="high"'
