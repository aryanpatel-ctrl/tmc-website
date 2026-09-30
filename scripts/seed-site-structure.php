<?php
/**
 * Seed the current site in English + Hindi: pages (incl. GIGW policy pages), menus, news/notice
 * categories with sample updates, per-site details and the Home page sections.
 *
 * Safe to re-run: creates only what is missing. The Home page is rebuilt only while it still holds
 * the placeholder text. Sample posts carry the meta _tmc_sample=1 so they can be removed in one go:
 *   wp post delete $(wp post list --meta_key=_tmc_sample --format=ids --post_type=post) --force
 *
 *   docker compose run --rm -T wpcli --url=<site> eval-file - < scripts/seed-site-structure.php
 */

if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'tmc_section_hero' ) ) {
	WP_CLI::error( 'Polylang and the TMC theme must be active on ' . home_url() );
}

/* ================================================================ site facts */

$host = wp_parse_url( home_url(), PHP_URL_HOST );
$unit = DOMAIN_CURRENT_SITE === $host ? '' : strstr( $host, '.', true );

$sites = array(
	''           => array( 'short' => 'TMC', 'short_hi' => 'टीएमसी', 'name_hi' => 'टाटा मेमोरियल केंद्र', 'city' => 'Mumbai (headquarters)', 'city_hi' => 'मुंबई (मुख्यालय)', 'address' => "Dr. Ernest Borges Marg, Parel\nMumbai – 400 012, Maharashtra, India", 'address_hi' => "डॉ. अर्नेस्ट बोर्जेस मार्ग, परेल\nमुंबई – 400 012, महाराष्ट्र, भारत" ),
	'tmh'        => array( 'short' => 'TMH', 'short_hi' => 'टीएमएच', 'name_hi' => 'टाटा मेमोरियल अस्पताल, मुंबई', 'city' => 'Parel, Mumbai', 'city_hi' => 'परेल, मुंबई', 'address' => "Dr. Ernest Borges Marg, Parel\nMumbai – 400 012, Maharashtra, India", 'address_hi' => "डॉ. अर्नेस्ट बोर्जेस मार्ग, परेल\nमुंबई – 400 012, महाराष्ट्र, भारत" ),
	'hbchrcv'    => array( 'short' => 'HBCH & RC Visakhapatnam', 'short_hi' => 'एचबीसीएच एवं आरसी विशाखापत्तनम', 'name_hi' => 'होमी भाभा कैंसर अस्पताल एवं अनुसंधान केंद्र, विशाखापत्तनम', 'city' => 'Visakhapatnam, Andhra Pradesh', 'city_hi' => 'विशाखापत्तनम, आंध्र प्रदेश', 'address' => 'Visakhapatnam, Andhra Pradesh, India', 'address_hi' => 'विशाखापत्तनम, आंध्र प्रदेश, भारत' ),
	'mpmmcc'     => array( 'short' => 'MPMMCC & HBCH Varanasi', 'short_hi' => 'एमपीएमएमसीसी एवं एचबीसीएच वाराणसी', 'name_hi' => 'महामना पंडित मदन मोहन मालवीय कैंसर केंद्र एवं होमी भाभा कैंसर अस्पताल, वाराणसी', 'city' => 'Varanasi, Uttar Pradesh', 'city_hi' => 'वाराणसी, उत्तर प्रदेश', 'address' => 'Varanasi, Uttar Pradesh, India', 'address_hi' => 'वाराणसी, उत्तर प्रदेश, भारत' ),
	'hbchrcmzp'  => array( 'short' => 'HBCH & RC Muzaffarpur', 'short_hi' => 'एचबीसीएच एवं आरसी मुज़फ़्फ़रपुर', 'name_hi' => 'होमी भाभा कैंसर अस्पताल एवं अनुसंधान केंद्र, मुज़फ़्फ़रपुर', 'city' => 'Muzaffarpur, Bihar', 'city_hi' => 'मुज़फ़्फ़रपुर, बिहार', 'address' => 'Muzaffarpur, Bihar, India', 'address_hi' => 'मुज़फ़्फ़रपुर, बिहार, भारत' ),
	'hbchpunjab' => array( 'short' => 'HBCH New Chandigarh', 'short_hi' => 'एचबीसीएच न्यू चंडीगढ़', 'name_hi' => 'होमी भाभा कैंसर अस्पताल, न्यू चंडीगढ़', 'city' => 'New Chandigarh, Punjab', 'city_hi' => 'न्यू चंडीगढ़, पंजाब', 'address' => 'New Chandigarh, Punjab, India', 'address_hi' => 'न्यू चंडीगढ़, पंजाब, भारत' ),
);
if ( ! isset( $sites[ $unit ] ) ) {
	WP_CLI::error( "Unknown site $host" );
}
$site    = $sites[ $unit ];
$is_main = '' === $unit;
$name    = get_option( 'blogname' );
$name_hi = $site['name_hi'];

update_option( 'tmc_name_hi', $name_hi );
update_option( 'tmc_city', $site['city'] );
update_option( 'tmc_city_hi', $site['city_hi'] );
set_theme_mod( 'tmc_address', $site['address'] );

/* ================================================================ helpers */

$log = function ( $message ) {
	WP_CLI::log( '  ' . $message );
};

$callout_en = '<!-- wp:paragraph {"className":"callout"} --><p class="callout">Detailed content for this page will be provided by Tata Memorial Centre and published through the CMS review workflow.</p><!-- /wp:paragraph -->';
$callout_hi = '<!-- wp:paragraph {"className":"callout"} --><p class="callout">इस पृष्ठ की विस्तृत सामग्री टाटा मेमोरियल केंद्र द्वारा उपलब्ध कराई जाएगी तथा सीएमएस की समीक्षा प्रक्रिया के माध्यम से प्रकाशित की जाएगी।</p><!-- /wp:paragraph -->';

$para = fn( $text ) => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$h2   = fn( $text ) => '<!-- wp:heading --><h2 class="wp-block-heading">' . $text . '</h2><!-- /wp:heading -->';
$list = function ( array $items ) {
	$out = '<!-- wp:list --><ul class="wp-block-list">';
	foreach ( $items as $item ) {
		$out .= '<!-- wp:list-item --><li>' . $item . '</li><!-- /wp:list-item -->';
	}
	return $out . '</ul><!-- /wp:list -->';
};

/** Create (or find) a page by path; returns its ID. */
$ensure_page = function ( $path, $title, $content, $lang, $parent = 0, $order = 0 ) use ( $log ) {
	$existing = get_page_by_path( $path );
	if ( $existing ) {
		return $existing->ID;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => basename( $path ),
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_parent'  => $parent,
			'menu_order'   => $order,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "$path: " . $id->get_error_message() );
	}
	pll_set_post_language( $id, $lang );
	$log( "page /$path" );
	return $id;
};

/* ================================================================ information architecture */
// [ en slug, en title, hi slug, hi title, en lead, hi lead, menu description en, hi, children[] ]
$s  = $name;
$sh = $name_hi;
$ia = array(
	array( 'about-us', 'About Us', 'hamare-bare-mein', 'हमारे बारे में', "Learn about $s: who we are, our history, our leadership and our work in cancer care, research and education.", "$sh के बारे में जानें: हम कौन हैं, हमारा इतिहास, हमारा नेतृत्व तथा कैंसर देखभाल, अनुसंधान एवं शिक्षा में हमारा कार्य।", 'Who we are, our history, leadership and annual reports.', 'हम कौन हैं, हमारा इतिहास, नेतृत्व एवं वार्षिक रिपोर्ट।', array(
		array( 'overview', 'Overview', 'avlokan', 'अवलोकन', "An overview of $s and the services it provides to patients and their families.", "$sh तथा रोगियों एवं उनके परिजनों को दी जाने वाली सेवाओं का अवलोकन।" ),
		array( 'vision-mission', 'Vision & Mission', 'drishti-evam-mission', 'दृष्टि एवं मिशन', 'Our mission is to provide comprehensive, compassionate cancer care to all through a commitment to excellence in service, research and education.', 'हमारा मिशन सेवा, अनुसंधान और शिक्षा में उत्कृष्टता के प्रति प्रतिबद्धता के माध्यम से सभी को व्यापक एवं करुणामय कैंसर देखभाल प्रदान करना है।' ),
		array( 'history', 'History', 'itihas', 'इतिहास', "The history and milestones of $s.", "$sh का इतिहास एवं प्रमुख उपलब्धियाँ।" ),
		array( 'leadership', 'Leadership', 'netritva', 'नेतृत्व', "The leadership and administration of $s.", "$sh का नेतृत्व एवं प्रशासन।" ),
		array( 'annual-reports', 'Annual Reports', 'varshik-report', 'वार्षिक रिपोर्ट', "Annual reports of $s, available for download.", "$sh की वार्षिक रिपोर्ट, डाउनलोड हेतु उपलब्ध।" ),
	) ),
	array( 'patient-care', 'Patient Care', 'rogi-dekhbhal', 'रोगी देखभाल', 'Everything patients and caregivers need: departments, the patient guide, OPD timings, appointments and charges.', 'रोगियों एवं देखभाल करने वालों के लिए आवश्यक सभी जानकारी: विभाग, रोगी मार्गदर्शिका, ओपीडी समय, अपॉइंटमेंट एवं शुल्क।', 'Departments, patient guide, OPD timings, appointments and charges.', 'विभाग, रोगी मार्गदर्शिका, ओपीडी समय, अपॉइंटमेंट एवं शुल्क।', array(
		array( 'patient-guide', 'Patient Guide', 'rogi-margdarshika', 'रोगी मार्गदर्शिका', 'A step-by-step guide for new patients and their families: registration, consultation, tests and treatment.', 'नए रोगियों एवं उनके परिजनों के लिए चरण-दर-चरण मार्गदर्शिका: पंजीकरण, परामर्श, जाँच एवं उपचार।' ),
		array( 'opd-schedule', 'OPD Schedule', 'opd-samay-sarini', 'ओपीडी समय-सारणी', 'Outpatient department (OPD) days and timings, by department.', 'विभागवार बाह्य रोगी विभाग (ओपीडी) के दिन एवं समय।' ),
		array( 'appointments', 'Appointments', 'appointment', 'अपॉइंटमेंट', "How to book an appointment. Online booking is provided through TMC's patient services system.", 'अपॉइंटमेंट कैसे बुक करें। ऑनलाइन बुकिंग टीएमसी की रोगी सेवा प्रणाली के माध्यम से उपलब्ध है।' ),
		array( 'fees-charges', 'Fees & Charges', 'shulk-evam-prabhar', 'शुल्क एवं प्रभार', 'Consultation, investigation and treatment charges, and information on financial assistance.', 'परामर्श, जाँच एवं उपचार शुल्क तथा वित्तीय सहायता संबंधी जानकारी।' ),
	) ),
	array( 'research', 'Research', 'anusandhan', 'अनुसंधान', 'Research programmes, clinical trials and publications.', 'अनुसंधान कार्यक्रम, क्लिनिकल परीक्षण एवं प्रकाशन।', 'Research programmes, clinical trials and publications.', 'अनुसंधान कार्यक्रम, क्लिनिकल परीक्षण एवं प्रकाशन।', array(
		array( 'clinical-trials', 'Clinical Trials', 'clinical-parikshan', 'क्लिनिकल परीक्षण', 'Clinical trials currently open, and how patients can take part.', 'वर्तमान में चल रहे क्लिनिकल परीक्षण तथा रोगी उनमें कैसे भाग ले सकते हैं।' ),
		array( 'publications', 'Publications', 'prakashan', 'प्रकाशन', 'Research publications by our clinicians and scientists.', 'हमारे चिकित्सकों एवं वैज्ञानिकों के शोध प्रकाशन।' ),
	) ),
	array( 'education', 'Education', 'shiksha', 'शिक्षा', 'Academic and training programmes in oncology and allied fields.', 'ऑन्कोलॉजी एवं संबद्ध क्षेत्रों में शैक्षणिक एवं प्रशिक्षण कार्यक्रम।', 'Courses, admissions and results.', 'पाठ्यक्रम, प्रवेश एवं परिणाम।', array(
		array( 'courses', 'Courses', 'pathyakram', 'पाठ्यक्रम', 'Courses offered, eligibility and duration.', 'उपलब्ध पाठ्यक्रम, पात्रता एवं अवधि।' ),
		array( 'admissions', 'Admissions', 'pravesh', 'प्रवेश', 'Admission notices, schedules and the application process.', 'प्रवेश सूचनाएं, समय-सारणी एवं आवेदन प्रक्रिया।' ),
		array( 'results', 'Results', 'parinam', 'परिणाम', 'Examination and selection results.', 'परीक्षा एवं चयन परिणाम।' ),
	) ),
	array( 'media', 'Media', 'media-kendra', 'मीडिया', "News, notices and photographs from $s.", "$sh से समाचार, सूचनाएं एवं तस्वीरें।", 'News, notices and photographs.', 'समाचार, सूचनाएं एवं तस्वीरें।', array(
		array( 'photo-gallery', 'Photo Gallery', 'chitra-dirgha', 'फोटो गैलरी', 'Photographs of events and activities.', 'कार्यक्रमों एवं गतिविधियों की तस्वीरें।' ),
	) ),
	array( 'donate', 'Donate', 'daan', 'दान करें', "Your contribution helps patients who cannot afford treatment. Online donation through TMC's approved payment gateway will be available on this page.", 'आपका योगदान उन रोगियों की सहायता करता है जो उपचार का खर्च वहन नहीं कर सकते। टीएमसी के अनुमोदित पेमेंट गेटवे के माध्यम से ऑनलाइन दान की सुविधा इस पृष्ठ पर उपलब्ध होगी।', '', '', array() ),
	array( 'contact-us', 'Contact Us', 'sampark', 'संपर्क करें', "How to reach $s.", "$sh तक कैसे पहुँचें।", '', '', array() ),
);

$pages = array(); // en slug => [ en id, hi id ]
foreach ( $ia as $order => $section ) {
	list( $en_slug, $en_title, $hi_slug, $hi_title, $en_lead, $hi_lead, , , $children ) = $section;
	$extra_en = $extra_hi = '';
	if ( 'contact-us' === $en_slug ) {
		$extra_en = $h2( 'Address' ) . $para( nl2br( esc_html( $site['address'] ) ) );
		$extra_hi = $h2( 'पता' ) . $para( nl2br( esc_html( $site['address_hi'] ) ) );
	}
	$en = $ensure_page( $en_slug, $en_title, $para( $en_lead ) . $extra_en . $callout_en, 'en', 0, $order );
	$hi = $ensure_page( $hi_slug, $hi_title, $para( $hi_lead ) . $extra_hi . $callout_hi, 'hi', 0, $order );
	pll_save_post_translations( array( 'en' => $en, 'hi' => $hi ) );
	$pages[ $en_slug ] = array( $en, $hi );
	foreach ( $children as $child_order => list( $c_en_slug, $c_en_title, $c_hi_slug, $c_hi_title, $c_en_lead, $c_hi_lead ) ) {
		$c_en = $ensure_page( "$en_slug/$c_en_slug", $c_en_title, $para( $c_en_lead ) . $callout_en, 'en', $en, $child_order );
		$c_hi = $ensure_page( "$hi_slug/$c_hi_slug", $c_hi_title, $para( $c_hi_lead ) . $callout_hi, 'hi', $hi, $child_order );
		pll_save_post_translations( array( 'en' => $c_en, 'hi' => $c_hi ) );
		$pages[ $c_en_slug ] = array( $c_en, $c_hi );
	}
}

/* ================================================================ GIGW policy pages */

$reader_rows = array(
	array( 'NVDA (NonVisual Desktop Access)', 'https://www.nvaccess.org/', 'nvaccess.org', 'Free', 'निःशुल्क' ),
	array( 'Narrator (built into Windows)', 'https://support.microsoft.com/windows', 'support.microsoft.com', 'Free', 'निःशुल्क' ),
	array( 'VoiceOver (built into macOS and iOS)', 'https://www.apple.com/accessibility/', 'apple.com/accessibility', 'Free', 'निःशुल्क' ),
	array( 'TalkBack (built into Android)', 'https://support.google.com/accessibility/android', 'support.google.com', 'Free', 'निःशुल्क' ),
	array( 'JAWS', 'https://www.freedomscientific.com/products/software/jaws/', 'freedomscientific.com', 'Commercial', 'सशुल्क' ),
);
$reader_table = function ( $lang ) use ( $reader_rows ) {
	$head = 'hi' === $lang ? array( 'स्क्रीन रीडर', 'वेबसाइट', 'निःशुल्क / सशुल्क' ) : array( 'Screen reader', 'Website', 'Free / Commercial' );
	$html = '<!-- wp:table {"hasFixedLayout":false} --><figure class="wp-block-table"><table><thead><tr><th>' . implode( '</th><th>', $head ) . '</th></tr></thead><tbody>';
	foreach ( $reader_rows as $row ) {
		$html .= sprintf( '<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td></tr>', esc_html( $row[0] ), esc_url( $row[1] ), esc_html( $row[2] ), 'hi' === $lang ? $row[4] : $row[3] );
	}
	return $html . '</tbody></table></figure><!-- /wp:table -->';
};

$policies = array(
	array(
		'copyright-policy', 'Copyright Policy', 'copyright-niti', 'कॉपीराइट नीति',
		$para( 'Material on this website may be reproduced free of charge, provided it is reproduced accurately and is not used in a derogatory manner or in a misleading context. Wherever the material is published or issued to others, the source must be prominently acknowledged.' )
		. $para( 'Permission to reproduce this material does not extend to any material identified as the copyright of a third party. Authorisation to reproduce such material must be obtained from the copyright holders concerned.' ),
		$para( 'इस वेबसाइट पर उपलब्ध सामग्री का निःशुल्क पुनरुत्पादन किया जा सकता है, बशर्ते उसे सही रूप में प्रस्तुत किया जाए तथा अपमानजनक या भ्रामक संदर्भ में उपयोग न किया जाए। जहाँ भी सामग्री प्रकाशित या अन्य को जारी की जाए, वहाँ स्रोत का स्पष्ट उल्लेख किया जाना चाहिए।' )
		. $para( 'यह अनुमति किसी तृतीय पक्ष के कॉपीराइट वाली सामग्री पर लागू नहीं होती। ऐसी सामग्री के पुनरुत्पादन हेतु संबंधित कॉपीराइट धारकों से अनुमति लेनी होगी।' ),
	),
	array(
		'hyperlinking-policy', 'Hyperlinking Policy', 'hyperlinking-niti', 'हाइपरलिंकिंग नीति',
		$h2( 'Links to external websites' ) . $para( 'This website contains links to other websites for the convenience of visitors. Tata Memorial Centre is not responsible for the contents or reliability of linked websites and does not necessarily endorse the views expressed in them. Links to other websites open in a new tab and are marked for screen-reader users.' )
		. $h2( 'Links to this website' ) . $para( 'Prior permission is not required to link to this website. However, pages of this website must not be loaded into frames on another site; they must open in a new browser window or tab.' ),
		$h2( 'बाहरी वेबसाइटों के लिंक' ) . $para( 'आगंतुकों की सुविधा हेतु इस वेबसाइट पर अन्य वेबसाइटों के लिंक दिए गए हैं। लिंक की गई वेबसाइटों की सामग्री या विश्वसनीयता के लिए टाटा मेमोरियल केंद्र उत्तरदायी नहीं है और उनमें व्यक्त विचारों का समर्थन करना आवश्यक नहीं है। अन्य वेबसाइटों के लिंक नए टैब में खुलते हैं तथा स्क्रीन रीडर उपयोगकर्ताओं के लिए चिह्नित होते हैं।' )
		. $h2( 'इस वेबसाइट के लिंक' ) . $para( 'इस वेबसाइट से लिंक करने हेतु पूर्व अनुमति आवश्यक नहीं है। तथापि, इस वेबसाइट के पृष्ठों को किसी अन्य साइट के फ़्रेम में लोड नहीं किया जाना चाहिए; उन्हें नई ब्राउज़र विंडो या टैब में खुलना चाहिए।' ),
	),
	array(
		'privacy-policy', 'Privacy Policy', 'gopniyata-niti', 'गोपनीयता नीति',
		$para( 'This website does not automatically capture any personal information that identifies you individually, such as your name, phone number or email address.' )
		. $para( 'Our servers record technical information such as your IP address, browser type, the pages you visit and the time of your visit. This information is used only for statistics and for the security of the website, and is not linked to individuals except where required by law.' )
		. $para( 'Your choices of text size and contrast are stored only in your own browser. We do not sell or share personal information with third parties. Where a form asks for personal information, you will be told how it will be used.' ),
		$para( 'यह वेबसाइट स्वतः ऐसी कोई व्यक्तिगत जानकारी एकत्र नहीं करती जिससे आपकी व्यक्तिगत पहचान हो सके, जैसे आपका नाम, फ़ोन नंबर या ईमेल पता।' )
		. $para( 'हमारे सर्वर तकनीकी जानकारी दर्ज करते हैं, जैसे आपका आईपी पता, ब्राउज़र का प्रकार, आपके द्वारा देखे गए पृष्ठ तथा आपकी विज़िट का समय। इस जानकारी का उपयोग केवल सांख्यिकी एवं वेबसाइट की सुरक्षा हेतु किया जाता है, तथा विधि द्वारा अपेक्षित स्थिति को छोड़कर इसे किसी व्यक्ति से नहीं जोड़ा जाता।' )
		. $para( 'पाठ के आकार एवं कंट्रास्ट संबंधी आपकी पसंद केवल आपके अपने ब्राउज़र में संग्रहीत होती है। हम व्यक्तिगत जानकारी किसी तृतीय पक्ष को न बेचते हैं न साझा करते हैं। जहाँ किसी फ़ॉर्म में व्यक्तिगत जानकारी माँगी जाएगी, वहाँ उसके उपयोग की जानकारी दी जाएगी।' ),
	),
	array(
		'terms-conditions', 'Terms & Conditions', 'niyam-evam-sharten', 'नियम एवं शर्तें',
		$para( 'This website is designed, developed and maintained for Tata Memorial Centre, a Grant-in-Aid institution under the Department of Atomic Energy, Government of India.' )
		. $para( 'Although every effort has been made to ensure the accuracy of the content, it should not be construed as a statement of law or used for any legal purpose. In case of any ambiguity or doubt, users are advised to verify with Tata Memorial Centre.' )
		. $para( 'These terms and conditions are governed by the laws of India. Any dispute arising under them is subject to the exclusive jurisdiction of the courts at Mumbai, Maharashtra.' ),
		$para( 'यह वेबसाइट परमाणु ऊर्जा विभाग, भारत सरकार के अंतर्गत सहायता-अनुदान प्राप्त संस्थान टाटा मेमोरियल केंद्र के लिए अभिकल्पित, विकसित एवं अनुरक्षित है।' )
		. $para( 'यद्यपि सामग्री की सटीकता सुनिश्चित करने का हर संभव प्रयास किया गया है, इसे विधि का कथन नहीं माना जाना चाहिए और न ही किसी विधिक प्रयोजन हेतु उपयोग किया जाना चाहिए। किसी भी अस्पष्टता या संदेह की स्थिति में उपयोगकर्ता टाटा मेमोरियल केंद्र से पुष्टि कर लें।' )
		. $para( 'ये नियम एवं शर्तें भारत के कानूनों द्वारा शासित हैं। इनके अंतर्गत उत्पन्न कोई भी विवाद मुंबई, महाराष्ट्र के न्यायालयों के अनन्य क्षेत्राधिकार के अधीन होगा।' ),
	),
	array(
		'accessibility-statement', 'Accessibility Statement', 'sugamyata-vivaran', 'सुगम्यता विवरण',
		$para( 'We are committed to making this website accessible to all users, irrespective of device, technology or ability. It is designed to conform to the Guidelines for Indian Government Websites (GIGW 3.0) and the Web Content Accessibility Guidelines (WCAG) 2.2 at Level AA.' )
		. $h2( 'Accessibility features' )
		. $list( array( '“Skip to main content” link at the start of every page', 'Controls to change the text size and to switch to a high-contrast view', 'Full keyboard navigation with a clearly visible focus indicator', 'Content in English and Hindi', 'Text alternatives for images, and a consistent structure of headings and landmarks' ) )
		. $para( 'If you face any difficulty in accessing information on this website, please let us know through the Feedback page.' ),
		$para( 'हम इस वेबसाइट को सभी उपयोगकर्ताओं के लिए सुगम्य बनाने हेतु प्रतिबद्ध हैं, चाहे वे किसी भी उपकरण, तकनीक या क्षमता का उपयोग करते हों। यह वेबसाइट भारत सरकार की वेबसाइटों के लिए दिशानिर्देश (GIGW 3.0) एवं वेब सामग्री सुगम्यता दिशानिर्देश (WCAG) 2.2 लेवल AA के अनुरूप बनाई गई है।' )
		. $h2( 'सुगम्यता सुविधाएं' )
		. $list( array( 'प्रत्येक पृष्ठ के आरंभ में “मुख्य सामग्री पर जाएं” लिंक', 'पाठ का आकार बदलने तथा उच्च कंट्रास्ट दृश्य हेतु नियंत्रण', 'स्पष्ट फ़ोकस संकेतक के साथ पूर्ण कीबोर्ड नेविगेशन', 'अंग्रेज़ी एवं हिन्दी में सामग्री', 'चित्रों के लिए वैकल्पिक पाठ, तथा शीर्षकों एवं लैंडमार्क की सुसंगत संरचना' ) )
		. $para( 'यदि आपको इस वेबसाइट पर जानकारी प्राप्त करने में कोई कठिनाई हो, तो कृपया प्रतिक्रिया पृष्ठ के माध्यम से हमें सूचित करें।' ),
	),
	array(
		'disclaimer', 'Disclaimer', 'asvikaran', 'अस्वीकरण',
		$para( 'The information on this website is provided for general information only. It is not a substitute for professional medical advice, diagnosis or treatment. Always consult a qualified doctor about any medical condition.' )
		. $para( 'Tata Memorial Centre will not be liable for any expense, loss or damage arising from the use of this website or its content. Links to other websites are provided for convenience only.' ),
		$para( 'इस वेबसाइट पर दी गई जानकारी केवल सामान्य जानकारी हेतु है। यह पेशेवर चिकित्सीय परामर्श, निदान या उपचार का विकल्प नहीं है। किसी भी चिकित्सीय स्थिति के संबंध में सदैव योग्य चिकित्सक से परामर्श लें।' )
		. $para( 'इस वेबसाइट या इसकी सामग्री के उपयोग से होने वाले किसी भी व्यय, हानि या क्षति के लिए टाटा मेमोरियल केंद्र उत्तरदायी नहीं होगा। अन्य वेबसाइटों के लिंक केवल सुविधा हेतु दिए गए हैं।' ),
	),
	array(
		'help', 'Help', 'sahayata', 'सहायता',
		$h2( 'Finding information' ) . $para( 'Use the main menu at the top of every page, or type keywords in the search box. The Sitemap lists all pages of this website.' )
		. $h2( 'Changing text size and contrast' ) . $para( 'Use the A-, A, A+ and A++ buttons at the top of the page to change the text size, and the contrast buttons to switch to a high-contrast view. Your choice is remembered on this device.' )
		. $h2( 'Choosing a language' ) . $para( 'Select English or हिन्दी at the top of any page. Where a page is available in both languages, you are taken to the same page in the other language.' )
		. $h2( 'Viewing documents' ) . $para( 'Some documents are provided in PDF format. You need a PDF reader, such as the one built into most web browsers, to view them.' ),
		$h2( 'जानकारी खोजना' ) . $para( 'प्रत्येक पृष्ठ के ऊपर दिए गए मुख्य मेनू का उपयोग करें, या खोज बॉक्स में शब्द लिखें। साइटमैप में इस वेबसाइट के सभी पृष्ठ सूचीबद्ध हैं।' )
		. $h2( 'पाठ का आकार एवं कंट्रास्ट बदलना' ) . $para( 'पृष्ठ के ऊपर दिए गए A-, A, A+ और A++ बटन से पाठ का आकार बदलें, तथा कंट्रास्ट बटन से उच्च कंट्रास्ट दृश्य चुनें। आपकी पसंद इस उपकरण पर याद रखी जाती है।' )
		. $h2( 'भाषा चुनना' ) . $para( 'किसी भी पृष्ठ के ऊपर English या हिन्दी चुनें। जहाँ पृष्ठ दोनों भाषाओं में उपलब्ध है, आप दूसरी भाषा में उसी पृष्ठ पर पहुँचेंगे।' )
		. $h2( 'दस्तावेज़ देखना' ) . $para( 'कुछ दस्तावेज़ पीडीएफ़ प्रारूप में उपलब्ध हैं। इन्हें देखने के लिए पीडीएफ़ रीडर आवश्यक है, जो अधिकांश वेब ब्राउज़रों में पहले से उपलब्ध होता है।' ),
	),
	array(
		'feedback', 'Feedback', 'pratikriya', 'प्रतिक्रिया',
		$para( 'We welcome your feedback and suggestions on this website. Please write to us using the details on the Contact Us page, mentioning the address (URL) of the page your feedback relates to.' )
		. $para( 'Feedback about accessibility is especially welcome and is addressed on priority.' ),
		$para( 'इस वेबसाइट के संबंध में आपकी प्रतिक्रिया एवं सुझावों का स्वागत है। कृपया संपर्क पृष्ठ पर दिए गए विवरण के माध्यम से हमें लिखें तथा संबंधित पृष्ठ का पता (URL) अवश्य बताएं।' )
		. $para( 'सुगम्यता संबंधी प्रतिक्रिया का विशेष रूप से स्वागत है और उस पर प्राथमिकता से कार्यवाही की जाती है।' ),
	),
	array(
		'sitemap', 'Sitemap', 'sitemap-hi', 'साइटमैप',
		$para( 'All pages of this website.' ) . '<!-- wp:tmc/sitemap /-->',
		$para( 'इस वेबसाइट के सभी पृष्ठ।' ) . '<!-- wp:tmc/sitemap /-->',
	),
	array(
		'screen-reader-access', 'Screen Reader Access', 'screen-reader-sahayata', 'स्क्रीन रीडर एक्सेस',
		$para( 'This website complies with the Guidelines for Indian Government Websites (GIGW) and WCAG 2.2 Level AA, so that people with visual impairments can use it with assistive technologies such as screen readers. The following screen readers can be used:' ) . $reader_table( 'en' ),
		$para( 'यह वेबसाइट भारत सरकार की वेबसाइटों के लिए दिशानिर्देश (GIGW) एवं WCAG 2.2 लेवल AA का पालन करती है, ताकि दृष्टिबाधित व्यक्ति स्क्रीन रीडर जैसी सहायक तकनीकों से इसका उपयोग कर सकें। निम्नलिखित स्क्रीन रीडरों का उपयोग किया जा सकता है:' ) . $reader_table( 'hi' ),
	),
);

foreach ( $policies as $order => list( $en_slug, $en_title, $hi_slug, $hi_title, $en_body, $hi_body ) ) {
	$en = $ensure_page( $en_slug, $en_title, $en_body, 'en', 0, 100 + $order );
	$hi = $ensure_page( $hi_slug, $hi_title, $hi_body, 'hi', 0, 100 + $order );
	pll_save_post_translations( array( 'en' => $en, 'hi' => $hi ) );
	$pages[ $en_slug ] = array( $en, $hi );
}
update_option( 'wp_page_for_privacy_policy', $pages['privacy-policy'][0] );

/* ================================================================ categories + sample updates */

$ensure_term = function ( $slug, $title, $lang ) {
	$term = get_term_by( 'slug', $slug, 'category' );
	if ( ! $term ) {
		$result = wp_insert_term( $title, 'category', array( 'slug' => $slug ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$term = get_term( $result['term_id'], 'category' );
	}
	pll_set_term_language( $term->term_id, $lang );
	return (int) $term->term_id;
};
$cats = array(
	'notices' => array( $ensure_term( 'notices', 'Notices', 'en' ), $ensure_term( 'suchnayen', 'सूचनाएं', 'hi' ) ),
	'news'    => array( $ensure_term( 'news', 'News', 'en' ), $ensure_term( 'samachar', 'समाचार', 'hi' ) ),
);
foreach ( $cats as list( $en_term, $hi_term ) ) {
	pll_save_term_translations( array( 'en' => $en_term, 'hi' => $hi_term ) );
}

// Self-descriptive sample items (true statements about this website), flagged for easy removal.
$samples = array(
	array( 'notices', 2, 'Website now available in English and Hindi', 'Visitors can switch between English and Hindi using the language selector at the top of every page.', 'website-angrezi-hindi-mein', 'वेबसाइट अब अंग्रेज़ी और हिन्दी में उपलब्ध', 'आगंतुक प्रत्येक पृष्ठ के ऊपर दिए गए भाषा चयनकर्ता से अंग्रेज़ी और हिन्दी के बीच बदल सकते हैं।' ),
	array( 'notices', 5, 'New accessibility options: text size and high contrast', 'Use the A-, A, A+ and A++ buttons to change the text size, and the contrast buttons for a high-contrast view.', 'sugamyata-vikalp', 'नए सुगम्यता विकल्प: पाठ का आकार एवं उच्च कंट्रास्ट', 'पाठ का आकार बदलने के लिए A-, A, A+ और A++ बटन तथा उच्च कंट्रास्ट दृश्य के लिए कंट्रास्ट बटन का उपयोग करें।' ),
	array( 'notices', 9, 'Tenders and EOIs are published on the Tenders page', 'All tenders, Expressions of Interest and corrigenda are listed on the Tenders page with their closing dates.', 'nividayen-evam-eoi', 'निविदाएं एवं ईओआई निविदा पृष्ठ पर प्रकाशित', 'सभी निविदाएं, अभिरुचि की अभिव्यक्ति एवं शुद्धिपत्र उनकी अंतिम तिथियों सहित निविदा पृष्ठ पर सूचीबद्ध हैं।' ),
	array( 'notices', 16, 'Screen reader access information published', 'Details of screen readers that work with this website are available on the Screen Reader Access page.', 'screen-reader-jankari', 'स्क्रीन रीडर एक्सेस संबंधी जानकारी प्रकाशित', 'इस वेबसाइट के साथ काम करने वाले स्क्रीन रीडरों का विवरण स्क्रीन रीडर एक्सेस पृष्ठ पर उपलब्ध है।' ),
	array( 'notices', 24, 'Sample notice for the website demonstration', 'This is a sample notice created to demonstrate the notice board. It will be replaced by notices published by Tata Memorial Centre.', 'pradarshan-suchna', 'वेबसाइट प्रदर्शन हेतु नमूना सूचना', 'यह सूचना बोर्ड के प्रदर्शन हेतु बनाई गई एक नमूना सूचना है। इसे टाटा मेमोरियल केंद्र द्वारा प्रकाशित सूचनाओं से बदल दिया जाएगा।' ),
	array( 'news', 3, "Six websites, one platform: TMC's unified website ecosystem", 'The Tata Memorial Centre website and its unit websites now share one content management system, one design system and one security framework.', 'chhah-websites-ek-platform', 'छह वेबसाइटें, एक प्लेटफ़ॉर्म: टीएमसी का एकीकृत वेबसाइट इकोसिस्टम', 'टाटा मेमोरियल केंद्र की वेबसाइट एवं इसकी इकाइयों की वेबसाइटें अब एक ही कंटेंट मैनेजमेंट सिस्टम, एक डिज़ाइन सिस्टम और एक सुरक्षा ढांचे पर आधारित हैं।' ),
	array( 'news', 8, 'Every page is reviewed before it is published', 'Content editors submit pages for review; reviewers approve them before publication, and every change is recorded in a tamper-evident audit log.', 'samiksha-prakriya', 'प्रत्येक पृष्ठ प्रकाशन से पहले समीक्षित', 'सामग्री संपादक पृष्ठ समीक्षा हेतु भेजते हैं; समीक्षक प्रकाशन से पहले उन्हें अनुमोदित करते हैं, और प्रत्येक परिवर्तन छेड़छाड़-रोधी ऑडिट लॉग में दर्ज होता है।' ),
	array( 'news', 12, 'Websites designed for accessibility', 'The websites follow the Guidelines for Indian Government Websites (GIGW 3.0) and WCAG 2.2 Level AA.', 'sugamya-websites', 'सुगम्यता को ध्यान में रखकर बनाई गई वेबसाइटें', 'ये वेबसाइटें भारत सरकार की वेबसाइटों के लिए दिशानिर्देश (GIGW 3.0) एवं WCAG 2.2 लेवल AA का पालन करती हैं।' ),
);
foreach ( $samples as list( $cat, $days_ago, $en_title, $en_text, $hi_slug, $hi_title, $hi_text ) ) {
	$date = wp_date( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
	$ids  = array();
	foreach ( array( 'en' => array( sanitize_title( $en_title ), $en_title, $en_text, 0 ), 'hi' => array( $hi_slug, $hi_title, $hi_text, 1 ) ) as $lang => list( $slug, $title, $text, $cat_index ) ) {
		$existing = get_page_by_path( $slug, OBJECT, 'post' );
		if ( $existing ) {
			$ids[ $lang ] = $existing->ID;
			continue;
		}
		$ids[ $lang ] = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_name'     => $slug,
				'post_title'    => $title,
				'post_content'  => wp_slash( $para( esc_html( $text ) ) ),
				'post_excerpt'  => $text,
				'post_date'     => $date,
				'post_category' => array( $cats[ $cat ][ $cat_index ] ),
				'meta_input'    => array( '_tmc_sample' => 1 ),
			)
		);
		pll_set_post_language( $ids[ $lang ], $lang );
	}
	pll_save_post_translations( $ids );
}
$log( 'categories + ' . count( $samples ) . ' sample updates (x2 languages)' );

/* ================================================================ menus */

$make_menu = function ( $menu_name, array $items ) {
	$menu = wp_get_nav_menu_object( $menu_name );
	if ( $menu ) {
		return (int) $menu->term_id;
	}
	$menu_id = wp_create_nav_menu( $menu_name );
	foreach ( $items as $item ) {
		$parent_item = wp_update_nav_menu_item( $menu_id, 0, $item['args'] );
		foreach ( $item['children'] ?? array() as $child ) {
			wp_update_nav_menu_item( $menu_id, 0, $child + array( 'menu-item-parent-id' => $parent_item ) );
		}
	}
	return (int) $menu_id;
};
$page_item = fn( $id, $description = '' ) => array( 'menu-item-object-id' => $id, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-description' => $description );
$cat_item  = fn( $id ) => array( 'menu-item-object-id' => $id, 'menu-item-object' => 'category', 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' );
// Listings of the content types (tenders, events, ...). Polylang adds /hi/ on Hindi pages.
$archive_titles = array(
	'tmc_department' => array( 'Departments', 'विभाग' ),
	'tmc_doctor'     => array( 'Find a Doctor', 'डॉक्टर खोजें' ),
	'tmc_job'        => array( 'Careers', 'करियर' ),
	'tmc_tender'     => array( 'Tenders', 'निविदाएं' ),
	'tmc_event'      => array( 'Events', 'कार्यक्रम' ),
);
$archive_item = fn( $type, $i ) => array( 'menu-item-type' => 'post_type_archive', 'menu-item-object' => $type, 'menu-item-title' => $archive_titles[ $type ][ $i ], 'menu-item-status' => 'publish' );

$locations = array();
foreach ( array( 'en' => 0, 'hi' => 1 ) as $lang => $i ) {
	$primary = array();
	foreach ( $ia as $section ) {
		list( $en_slug, , , , , , $desc_en, $desc_hi, $children ) = $section;
		if ( 'donate' === $en_slug ) {
			continue; // quick links + home tile
		}
		$item = array( 'args' => $page_item( $pages[ $en_slug ][ $i ], 'hi' === $lang ? $desc_hi : $desc_en ) );
		foreach ( $children as $child ) {
			$item['children'][] = $page_item( $pages[ $child[0] ][ $i ] );
		}
		if ( 'patient-care' === $en_slug ) {
			array_unshift( $item['children'], $archive_item( 'tmc_department', $i ), $archive_item( 'tmc_doctor', $i ) );
		}
		if ( 'media' === $en_slug ) {
			array_unshift( $item['children'], $cat_item( $cats['news'][ $i ] ), $cat_item( $cats['notices'][ $i ] ), $archive_item( 'tmc_event', $i ) );
		}
		$primary[] = $item;
		if ( 'education' === $en_slug ) {
			$primary[] = array( 'args' => $archive_item( 'tmc_job', $i ) );
			$primary[] = array( 'args' => $archive_item( 'tmc_tender', $i ) );
		}
	}
	$quick    = array(
		array( 'args' => $page_item( $pages['patient-guide'][ $i ] ) ),
		array( 'args' => $page_item( $pages['appointments'][ $i ] ) ),
		array( 'args' => $page_item( $pages['opd-schedule'][ $i ] ) ),
		array( 'args' => $archive_item( 'tmc_department', $i ) ),
		array( 'args' => $archive_item( 'tmc_doctor', $i ) ),
		array( 'args' => $archive_item( 'tmc_job', $i ) ),
		array( 'args' => $archive_item( 'tmc_tender', $i ) ),
		array( 'args' => $page_item( $pages['donate'][ $i ] ) ),
	);
	$policy   = array_map( fn( $slug ) => array( 'args' => $page_item( $pages[ $slug ][ $i ] ) ), array( 'copyright-policy', 'hyperlinking-policy', 'privacy-policy', 'terms-conditions', 'accessibility-statement', 'disclaimer', 'help', 'feedback', 'sitemap' ) );
	$suffix   = 'hi' === $lang ? ' (हिन्दी)' : ' (English)';
	$locations['primary'][ $lang ]         = $make_menu( 'Main menu' . $suffix, $primary );
	$locations['footer-quick'][ $lang ]    = $make_menu( 'Footer quick links' . $suffix, $quick );
	$locations['footer-policies'][ $lang ] = $make_menu( 'Footer policies' . $suffix, $policy );
}
set_theme_mod( 'nav_menu_locations', array_map( fn( $by_lang ) => $by_lang['en'], $locations ) );
$nav_menus                         = PLL()->options->get( 'nav_menus' );
$nav_menus[ get_stylesheet() ]     = $locations;
$error                             = PLL()->options->set( 'nav_menus', $nav_menus );
if ( $error->has_errors() ) {
	WP_CLI::warning( 'nav_menus: ' . $error->get_error_message() );
}
PLL()->options->save();
$log( 'menus: main, footer quick links, footer policies (en + hi)' );

/* ================================================================ Hindi strings (address) */

$hindi = PLL()->model->get_language( 'hi' );
$mo    = new PLL_MO();
$mo->import_from_db( $hindi );
$mo->add_entry( $mo->make_entry( $site['address'], $site['address_hi'] ) );
$mo->add_entry( $mo->make_entry( $name, $name_hi ) );
$mo->export_to_db( $hindi );

/* ================================================================ home page */

$home_en = (int) get_option( 'page_on_front' );
$home_hi = (int) pll_get_post( $home_en, 'hi' );
$url     = function ( $slug, $lang ) use ( $pages ) {
	$archives = array( 'departments' => 'departments', 'careers' => 'careers', 'tenders' => 'tenders', 'doctors' => 'doctors', 'events' => 'events' );
	if ( isset( $archives[ $slug ] ) ) {
		return home_url( ( 'hi' === $lang ? '/hi/' : '/' ) . $archives[ $slug ] . '/' );
	}
	return get_permalink( $pages[ $slug ][ 'hi' === $lang ? 1 : 0 ] );
};

$build_home = function ( $lang ) use ( $is_main, $name, $name_hi, $url ) {
	$hi       = 'hi' === $lang;
	$sections = array();

	if ( $is_main ) {
		$sections[] = tmc_section_hero(
			$hi ? 'टाटा मेमोरियल केंद्र' : 'Tata Memorial Centre',
			$hi ? 'सभी के लिए व्यापक एवं करुणामय कैंसर देखभाल' : 'Comprehensive, compassionate cancer care for all',
			$hi ? 'परमाणु ऊर्जा विभाग, भारत सरकार के अंतर्गत कैंसर अस्पतालों एवं अनुसंधान केंद्रों का राष्ट्रीय नेटवर्क — सेवा, अनुसंधान और शिक्षा में उत्कृष्टता के लिए समर्पित।' : 'A national network of cancer hospitals and research centres under the Department of Atomic Energy, Government of India — dedicated to excellence in service, research and education.',
			array( array( $hi ? 'रोगी देखभाल' : 'Patient care', $url( 'patient-care', $lang ) ), array( $hi ? 'अपने निकट इकाई खोजें' : 'Find a centre near you', '#tmc-network', true ) )
		);
	} else {
		$sections[] = tmc_section_hero(
			$hi ? 'टाटा मेमोरियल केंद्र की एक इकाई' : 'A unit of Tata Memorial Centre',
			$hi ? $name_hi : $name,
			$hi ? 'टाटा मेमोरियल केंद्र नेटवर्क के अंतर्गत व्यापक कैंसर देखभाल — निदान, उपचार, अनुसंधान एवं शिक्षा।' : 'Comprehensive cancer care — diagnosis, treatment, research and education — as part of the Tata Memorial Centre network.',
			array( array( $hi ? 'रोगी मार्गदर्शिका' : 'Patient guide', $url( 'patient-guide', $lang ) ), array( $hi ? 'ओपीडी समय-सारणी' : 'OPD schedule', $url( 'opd-schedule', $lang ), true ) )
		);
	}

	$sections[] = tmc_section_quick(
		$hi ? 'त्वरित लिंक' : 'Quick links',
		array(
			array( 'calendar', $hi ? 'अपॉइंटमेंट' : 'Appointments', $url( 'appointments', $lang ) ),
			array( 'guide', $hi ? 'रोगी मार्गदर्शिका' : 'Patient guide', $url( 'patient-guide', $lang ) ),
			array( 'department', $hi ? 'विभाग' : 'Departments', $url( 'departments', $lang ) ),
			array( 'research', $hi ? 'अनुसंधान' : 'Research', $url( 'research', $lang ) ),
			array( 'education', $hi ? 'शिक्षा' : 'Education', $url( 'education', $lang ) ),
			array( 'careers', $hi ? 'करियर' : 'Careers', $url( 'careers', $lang ) ),
			array( 'tender', $hi ? 'निविदाएं' : 'Tenders', $url( 'tenders', $lang ) ),
			array( 'donate', $hi ? 'दान करें' : 'Donate', $url( 'donate', $lang ) ),
		)
	);

	$sections[] = tmc_section_updates( $hi ? 'नया क्या है' : "What's new", $hi ? 'समाचार एवं कार्यक्रम' : 'News & events' );

	if ( $is_main ) {
		$sections[] = tmc_section_stats(
			$hi ? 'प्रमुख तथ्य' : 'Key facts',
			array(
				array( '1,20,000+', $hi ? 'प्रति वर्ष पंजीकृत नए कैंसर रोगी' : 'new cancer patients registered every year' ),
				array( '11', $hi ? 'पूरे भारत में केंद्र एवं संस्थान' : 'centres and institutions across India' ),
				array( '1941', $hi ? 'टाटा मेमोरियल अस्पताल की स्थापना' : 'Tata Memorial Hospital established' ),
				array( $hi ? 'हब एंड स्पोक' : 'Hub & spoke', $hi ? 'कैंसर देखभाल वितरण मॉडल' : 'model of cancer care delivery' ),
			)
		);
	} else {
		$sections[] = tmc_section_about(
			$hi ? 'हमारे बारे में' : 'About us',
			$hi ? "$name_hi टाटा मेमोरियल केंद्र की एक इकाई है, जो परमाणु ऊर्जा विभाग, भारत सरकार के अंतर्गत एक सहायता-अनुदान प्राप्त संस्थान है। यह क्षेत्र के रोगियों को टाटा मेमोरियल केंद्र के मानकों के अनुरूप कैंसर देखभाल, अनुसंधान एवं शिक्षा उपलब्ध कराता है।" : "$name is a unit of Tata Memorial Centre, a Grant-in-Aid institution under the Department of Atomic Energy, Government of India. It brings Tata Memorial Centre's standards of cancer care, research and education to patients in the region.",
			array( $hi ? 'और पढ़ें' : 'Read more', $url( 'about-us', $lang ) )
		);
	}

	$sections[] = tmc_section_opportunities( $hi ? 'निविदाएं एवं ईओआई' : 'Tenders & EOIs', $hi ? 'करियर' : 'Careers', $hi ? 'आगामी कार्यक्रम' : 'Upcoming events' );

	$sections[] = tmc_section_network(
		$hi ? 'हमारा नेटवर्क' : 'Our network',
		$hi ? "टाटा मेमोरियल केंद्र 'हब एंड स्पोक' मॉडल पर आधारित अस्पतालों एवं अनुसंधान केंद्रों के नेटवर्क के माध्यम से पूरे भारत में रोगियों की सेवा करता है।" : 'Tata Memorial Centre serves patients across India through a hub-and-spoke network of hospitals and research centres.'
	);

	return serialize_blocks( $sections );
};

foreach ( array( 'en' => $home_en, 'hi' => $home_hi ) as $lang => $home_id ) {
	if ( ! $home_id ) {
		WP_CLI::warning( "no $lang home page — run seed-home-pages.php first" );
		continue;
	}
	if ( false !== strpos( get_post_field( 'post_content', $home_id ), 'tmc-hero' ) && ! getenv( 'TMC_REBUILD_HOME' ) ) {
		$log( "home ($lang): kept (already built)" );
		continue;
	}
	wp_update_post( array( 'ID' => $home_id, 'post_content' => wp_slash( $build_home( $lang ) ) ) );
	$log( "home ($lang): sections built" );
}

/* ================================================================ document library (W1) */

if ( function_exists( 'tmc_documents_ensure_page' ) ) {
	if ( tmc_documents_ensure_menu_item( tmc_documents_ensure_page() ) ) {
		$log( 'menu: Documents added to footer quick links (en)' );
	}
}

/* ================================================================ application front ends + map (W4) */
// Appointment / results / online form / donate / location map blocks on their pages, map position.
// Each page is handled once (see inc/apps-pages.php); existing sites get this from migration 040.
foreach ( tmc_w4_seed_site() as $line ) {
	$log( $line );
}

flush_rewrite_rules( false );
WP_CLI::success( "$host seeded" );
