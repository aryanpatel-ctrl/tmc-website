#!/usr/bin/env bash
# One-time: install the WordPress network and create the five unit sites.
# Safe to re-run — skips anything that already exists.
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

wp() { docker compose run --rm -T wpcli "$@" </dev/null; }

if ! wp core is-installed --network 2>/dev/null; then
  echo "==> Installing network on ${TMC_BASE_DOMAIN}"
  docker compose run --rm -T -e TMC_MULTISITE=0 wpcli core multisite-install \
    --subdomains --skip-config --skip-email \
    --url="${TMC_BASE_DOMAIN}" \
    --title="Tata Memorial Centre" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}"
else
  echo "==> Network already installed"
fi
wp network meta update 1 site_name "Tata Memorial Centre" --url="${TMC_BASE_DOMAIN}" >/dev/null

# slug | title — slugs follow the indicative subdomains in the tender (Section 4.1)
SITES=(
  "tmh|Tata Memorial Hospital, Mumbai"
  "hbchrcv|Homi Bhabha Cancer Hospital & Research Centre, Visakhapatnam"
  "mpmmcc|Mahamana Pandit Madan Mohan Malaviya Cancer Centre & HBCH, Varanasi"
  "hbchrcmzp|Homi Bhabha Cancer Hospital & Research Centre, Muzaffarpur"
  "hbchpunjab|Homi Bhabha Cancer Hospital, New Chandigarh"
)

existing="$(wp site list --field=domain --url="${TMC_BASE_DOMAIN}")"
for entry in "${SITES[@]}"; do
  slug="${entry%%|*}"; title="${entry#*|}"
  if grep -qx "${slug}.${TMC_BASE_DOMAIN}" <<<"$existing"; then
    echo "==> Site ${slug} already exists"
  else
    echo "==> Creating site ${slug}"
    wp site create --slug="${slug}" --title="${title}" --email="${WP_ADMIN_EMAIL}" --url="${TMC_BASE_DOMAIN}"
  fi
done

echo "==> Baseline settings on every site"
# One WP-CLI run for all sites (each run costs seconds of container start-up).
wp --url="${TMC_BASE_DOMAIN}" eval '
foreach ( get_sites( array( "number" => 100 ) ) as $site ) {
	switch_to_blog( $site->blog_id );
	update_option( "timezone_string", "Asia/Kolkata" );
	update_option( "date_format", "d/m/Y" );
	update_option( "time_format", "h:i A" );
	// Search engines: production only (WP_ENVIRONMENT_TYPE from TMC_WP_ENVIRONMENT); UAT, CI and local stay out.
	update_option( "blog_public", "production" === wp_get_environment_type() ? 1 : 0 );
	if ( "/%postname%/" !== get_option( "permalink_structure" ) ) {
		update_option( "permalink_structure", "/%postname%/" );
		delete_option( "rewrite_rules" ); // regenerated on the next request
	}
	echo "    configured " . home_url( "/" ) . "\n";
	restore_current_blog();
}'

echo "==> Done"
wp site list --fields=blog_id,url --url="${TMC_BASE_DOMAIN}"
