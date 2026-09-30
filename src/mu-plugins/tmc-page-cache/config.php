<?php
/**
 * TMC full-page cache — rules that decide which anonymous page views may be cached.
 *
 * Read by engine.php before WordPress loads (so: plain PHP, no WordPress functions).
 * A request that does not match these rules is simply served by WordPress as usual ("BYPASS"),
 * so the safe way to support a new feature is to do nothing; add a parameter here only when the
 * page it produces is the same for every anonymous visitor.
 */

return array(
	// Seconds a cached page may be served. Content changes purge the cache immediately; this is the
	// upper bound for date-driven changes (e.g. an event moving from "upcoming" to "past").
	'ttl'           => 600,

	// Query parameters that select a different, public version of a page. They become part of the
	// cache key. The value must match the pattern, otherwise the request is not cached.
	'query_params'  => array(
		'view'       => '/^(archive|calendar|past|upcoming|list|grid)$/', // listings: tenders, careers, events
		'month'      => '/^[0-9]{4}-(0[1-9]|1[0-2])$/',                   // event calendar month
		'department' => '/^[0-9]{1,9}$/',                                  // Find a doctor: department filter
		'name'       => '/^$/',                                            // Find a doctor: cached only when the name box is empty
	),

	// Tracking parameters that never change the page. They are ignored for the lookup (a cached
	// page is served), but a page rendered for such a URL is not stored, because WordPress may
	// have copied the parameter into links on the page.
	'ignore_params' => array( 'fbclid', 'gclid', 'msclkid', 'dclid', 'mc_cid', 'mc_eid', '_ga', '_gl' ), // plus every utm_*

	// Cookies that mean "this visitor may see personalised content" — never cached.
	'bypass_cookies' => '/^(wordpress_logged_in_|wordpress_sec_|wordpress_[0-9a-f]{32}$|wp-postpass_|comment_author_|wordpress_no_cache|tmc_nocache)/',

	// Paths that are never cached (in addition to everything that is not the front controller).
	'bypass_paths'  => '#(^|/)(wp-json|wp-admin|wp-content|wp-includes|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-comments-post\.php|wp-signup\.php|wp-activate\.php)(/|$)#i',
);
