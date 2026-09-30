<?php
/**
 * 001 — Content types go live on existing sites.
 *
 *   1. Replace the placeholder pages Tenders / Careers / Departments with the content-type
 *      listings, and point existing links at the new URLs.
 *   2. Remove the seeded menus so seed-site-structure.php rebuilds them with the new items.
 *   3. Add starter content (EN + HI): departments; the real TMC EOI from the tender notice on the
 *      TMC and TMH sites; clearly labelled SAMPLE tenders, openings, events and doctor profiles.
 *   4. Add the "Tenders, careers and events" section to existing home pages.
 *
 * Sample items carry meta _tmc_sample = 1 (remove before go-live).
 */

$host   = wp_parse_url( home_url(), PHP_URL_HOST );
$unit   = DOMAIN_CURRENT_SITE === $host ? '' : strstr( $host, '.', true );
$author = (int) ( get_user_by( 'login', getenv( 'WP_ADMIN_USER' ) ?: 'tmcadmin' )->ID ?? 0 );
$now    = time();
$at     = fn( $days, $time = '10:00:00' ) => wp_date( 'Y-m-d', $now + $days * DAY_IN_SECONDS ) . ' ' . $time;
$para   = fn( $text ) => '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';

/* ---------------------------------------------------------------- 1 + 2: IA */

$old_to_new = array(
	'/patient-care/departments/' => '/departments/',
	'/hi/rogi-dekhbhal/vibhag/'  => '/hi/departments/',
	'/hi/nividayen/'             => '/hi/tenders/',
	'/hi/career/'                => '/hi/careers/',
);
foreach ( array( 'tenders', 'nividayen', 'careers', 'career', 'patient-care/departments', 'rogi-dekhbhal/vibhag' ) as $path ) {
	$page = get_page_by_path( $path );
	if ( $page ) {
		wp_delete_post( $page->ID, true );
		WP_CLI::log( "    removed placeholder page /$path/" );
	}
}
global $wpdb;
foreach ( $old_to_new as $old => $new ) {
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)", home_url( $old ), home_url( $new ) ) );
}
foreach ( wp_get_nav_menus() as $menu ) {
	if ( preg_match( '/^(Main menu|Footer quick links|Footer policies) \(/u', $menu->name ) ) {
		wp_delete_nav_menu( $menu->term_id );
	}
}

/* ---------------------------------------------------------------- helpers */

/** Tiny text PDF (Helvetica, English) so document lists and search have real files to show. */
$make_pdf = function ( $filename, $title, array $lines ) use ( $author ) {
	$existing = get_page_by_path( sanitize_title( $title ), OBJECT, 'attachment' );
	if ( $existing ) {
		return $existing->ID;
	}
	$stream = "BT /F1 16 Tf 72 770 Td 22 TL\n(" . $title . ") Tj /F1 11 Tf T* T*\n";
	foreach ( $lines as $line ) {
		$stream .= '(' . str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $line ) . ") Tj T*\n";
	}
	$stream .= 'ET';
	$objects = array(
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		'<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream",
	);
	$pdf     = "%PDF-1.4\n";
	$offsets = array();
	foreach ( $objects as $i => $object ) {
		$offsets[] = strlen( $pdf );
		$pdf      .= ( $i + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
	}
	$xref = strlen( $pdf );
	$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offsets as $offset ) {
		$pdf .= sprintf( "%010d 00000 n \n", $offset );
	}
	$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

	$upload = wp_upload_bits( $filename, null, $pdf );
	if ( ! empty( $upload['error'] ) ) {
		WP_CLI::warning( $upload['error'] );
		return 0;
	}
	$id = wp_insert_attachment(
		array(
			'post_title'     => $title,
			'post_name'      => sanitize_title( $title ),
			'post_mime_type' => 'application/pdf',
			'post_status'    => 'inherit',
			'post_author'    => $author,
			'post_content'   => implode( "\n", $lines ), // searchable text (Phase 2 indexes PDFs properly)
		),
		$upload['file']
	);
	update_post_meta( $id, '_tmc_sample', 1 );
	return $id;
};

/** Create (or find) an English + Hindi pair of posts; returns [ en_id, hi_id ]. */
$pair = function ( $type, array $en, array $hi, array $meta = array(), array $meta_hi = array() ) use ( $author ) {
	$ids = array();
	foreach ( array( 'en' => $en, 'hi' => $hi ) as $lang => $data ) {
		$existing = get_page_by_path( $data['slug'], OBJECT, $type );
		if ( $existing ) {
			$ids[ $lang ] = $existing->ID;
			continue;
		}
		$ids[ $lang ] = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_name'    => $data['slug'],
				'post_title'   => $data['title'],
				'post_content' => wp_slash( $data['content'] ?? '' ),
				'post_excerpt' => $data['excerpt'] ?? '',
				'post_date'    => $data['date'] ?? current_time( 'mysql' ),
				'menu_order'   => $data['order'] ?? 0,
			),
			true
		);
		if ( is_wp_error( $ids[ $lang ] ) ) {
			WP_CLI::error( $ids[ $lang ]->get_error_message() );
		}
		pll_set_post_language( $ids[ $lang ], $lang );
		foreach ( ( 'hi' === $lang && $meta_hi ) ? $meta_hi : $meta as $key => $value ) {
			if ( is_array( $value ) && 'departments' === ( tmc_field_schema()[ $type ][ $key ]['type'] ?? '' ) ) {
				foreach ( $value as $department_id ) {
					add_post_meta( $ids[ $lang ], '_' . $key, $department_id );
				}
			} else {
				update_post_meta( $ids[ $lang ], '_' . $key, $value );
			}
		}
		if ( ! empty( $data['sample'] ) ) {
			update_post_meta( $ids[ $lang ], '_tmc_sample', 1 );
		}
	}
	pll_save_post_translations( $ids );
	return array( $ids['en'], $ids['hi'] );
};

/* ---------------------------------------------------------------- 3: departments */

$departments = array(
	array( 'medical-oncology', 'Medical Oncology', 'chikitsa-oncology', 'चिकित्सा ऑन्कोलॉजी', 'Diagnosis and treatment of cancer with chemotherapy, targeted therapy, immunotherapy and hormonal therapy.', 'कीमोथेरेपी, लक्षित चिकित्सा, इम्यूनोथेरेपी एवं हार्मोनल चिकित्सा द्वारा कैंसर का निदान एवं उपचार।' ),
	array( 'surgical-oncology', 'Surgical Oncology', 'shalya-oncology', 'शल्य ऑन्कोलॉजी', 'Surgical treatment of cancer, including organ-preserving and minimally invasive procedures.', 'कैंसर का शल्य उपचार, जिसमें अंग-संरक्षण एवं न्यूनतम चीर-फाड़ वाली प्रक्रियाएं शामिल हैं।' ),
	array( 'radiation-oncology', 'Radiation Oncology', 'vikiran-oncology', 'विकिरण ऑन्कोलॉजी', 'Treatment of cancer with radiation therapy, including external beam radiotherapy and brachytherapy.', 'विकिरण चिकित्सा द्वारा कैंसर का उपचार, जिसमें बाह्य बीम रेडियोथेरेपी एवं ब्रैकीथेरेपी शामिल हैं।' ),
	array( 'pathology', 'Pathology', 'vikriti-vigyan', 'विकृति विज्ञान (पैथोलॉजी)', 'Laboratory diagnosis of cancer through histopathology, cytology and molecular testing.', 'हिस्टोपैथोलॉजी, कोशिका विज्ञान एवं आणविक परीक्षण द्वारा कैंसर का प्रयोगशाला निदान।' ),
	array( 'radiodiagnosis', 'Radiodiagnosis', 'radio-nidan', 'रेडियो निदान', 'Imaging for diagnosis and follow-up, including CT, MRI, ultrasound and interventional radiology.', 'निदान एवं अनुवर्ती जाँच हेतु इमेजिंग, जिसमें सीटी, एमआरआई, अल्ट्रासाउंड एवं इंटरवेंशनल रेडियोलॉजी शामिल हैं।' ),
	array( 'nuclear-medicine', 'Nuclear Medicine', 'nabhikiya-chikitsa', 'नाभिकीय चिकित्सा', 'Diagnostic imaging and therapy using radioisotopes, including PET-CT.', 'रेडियोआइसोटोप द्वारा नैदानिक इमेजिंग एवं उपचार, जिसमें पीईटी-सीटी शामिल है।' ),
	array( 'anaesthesiology', 'Anaesthesiology', 'nishchetna-vigyan', 'निश्चेतना विज्ञान', 'Anaesthesia, critical care and pain management for patients undergoing cancer treatment.', 'कैंसर उपचार करा रहे रोगियों हेतु निश्चेतना, गहन चिकित्सा एवं दर्द प्रबंधन।' ),
	array( 'palliative-medicine', 'Palliative Medicine', 'prashamak-chikitsa', 'प्रशामक चिकित्सा', 'Care that relieves pain and other symptoms, and supports patients and their families.', 'दर्द एवं अन्य लक्षणों से राहत देने वाली तथा रोगियों एवं उनके परिवारों को सहयोग देने वाली देखभाल।' ),
);
$dept = array();
foreach ( $departments as $order => list( $en_slug, $en_title, $hi_slug, $hi_title, $en_text, $hi_text ) ) {
	$dept[ $en_slug ] = $pair(
		'tmc_department',
		array( 'slug' => $en_slug, 'title' => $en_title, 'content' => $para( $en_text ), 'excerpt' => $en_text, 'order' => $order ),
		array( 'slug' => $hi_slug, 'title' => $hi_title, 'content' => $para( $hi_text ), 'excerpt' => $hi_text, 'order' => $order )
	);
}

/* ---------------------------------------------------------------- 3: sample doctor profiles */

$doctors = array(
	array( 'a', 'medical-oncology', 'Professor and Head', 'प्रोफेसर एवं विभागाध्यक्ष' ),
	array( 'b', 'surgical-oncology', 'Associate Professor', 'एसोसिएट प्रोफेसर' ),
	array( 'c', 'radiation-oncology', 'Assistant Professor', 'असिस्टेंट प्रोफेसर' ),
	array( 'd', 'pathology', 'Professor', 'प्रोफेसर' ),
);
$hindi_letter = array( 'a' => 'ए', 'b' => 'बी', 'c' => 'सी', 'd' => 'डी' );
foreach ( $doctors as $order => list( $letter, $department, $designation, $designation_hi ) ) {
	$pair(
		'tmc_doctor',
		array( 'slug' => "sample-profile-$letter", 'title' => 'Dr. Sample Profile ' . strtoupper( $letter ), 'content' => $para( 'This is a sample profile that shows how doctor information is presented. Real profiles will be provided by Tata Memorial Centre.' ), 'order' => $order, 'sample' => true ),
		array( 'slug' => "namuna-profile-$letter", 'title' => 'डॉ. नमूना प्रोफ़ाइल ' . $hindi_letter[ $letter ], 'content' => $para( 'यह एक नमूना प्रोफ़ाइल है, जो दर्शाती है कि चिकित्सकों की जानकारी कैसे प्रस्तुत की जाती है। वास्तविक प्रोफ़ाइल टाटा मेमोरियल केंद्र द्वारा उपलब्ध कराई जाएंगी।' ), 'order' => $order, 'sample' => true ),
		array( 'tmc_designation' => "$designation (sample profile)", 'tmc_department_ids' => array( $dept[ $department ][0] ), 'tmc_qualifications' => 'MBBS, MD (sample)' ),
		array( 'tmc_designation' => "$designation_hi (नमूना प्रोफ़ाइल)", 'tmc_department_ids' => array( $dept[ $department ][1] ), 'tmc_qualifications' => 'एमबीबीएस, एमडी (नमूना)' )
	);
}

/* ---------------------------------------------------------------- 3: tenders */

if ( in_array( $unit, array( '', 'tmh' ), true ) ) {
	// The real EOI from the tender notice (TMH Purchase Department, 28/09/2026).
	$eoi_doc = $make_pdf(
		'eoi-website-design-development.pdf',
		'EOI TMH/TMH/2026-27/CAP/EO/0009 - summary',
		array(
			'Expression of Interest: Website Design and Development Service, Qty 01, for IT Department.',
			'Tata Memorial Centre umbrella website and five constituent unit websites.',
			'Submission: online on the CPP portal by 05/10/2026, 3:00 PM. Opening: 06/10/2026 from 3:30 PM.',
			'EOI meeting: 09/10/2026 at 2:00 PM, Digital Library, Conference Room, Ground Floor,',
			'Main Building, Tata Memorial Hospital, Parel, Mumbai 400012.',
			'Summary prepared for the website demonstration; the official notice is on the CPP portal.',
		)
	);
	$pair(
		'tmc_tender',
		array(
			'slug'    => 'eoi-website-design-development-service',
			'title'   => 'Expression of Interest: Website Design and Development Service for the TMC umbrella website and five constituent unit websites',
			'content' => $para( 'Tata Memorial Centre invites Expressions of Interest for the website ecosystem: the TMC umbrella website and the websites of Tata Memorial Hospital (Mumbai), HBCH & RC (Visakhapatnam), MPMMCC / HBCH (Varanasi), HBCH & RC (Muzaffarpur) and HBCH (New Chandigarh).' )
				. $para( 'EOI meeting: 09/10/2026 at 2:00 PM, Digital Library, Conference Room, Ground Floor, Main Building, Tata Memorial Hospital, Parel, Mumbai. Bidders unable to attend may send queries to capitalequip-purchase.tmh@tmc.gov.in by 06/10/2026.' ),
			'excerpt' => 'EOI for the TMC website ecosystem. Submission on the CPP portal by 05/10/2026, 3:00 PM.',
			'date'    => '2026-09-28 10:00:00',
		),
		array(
			'slug'    => 'eoi-website-design-vikas-seva',
			'title'   => 'अभिरुचि की अभिव्यक्ति: टीएमसी अम्ब्रेला वेबसाइट एवं पाँच घटक इकाई वेबसाइटों हेतु वेबसाइट डिज़ाइन एवं विकास सेवा',
			'content' => $para( 'टाटा मेमोरियल केंद्र वेबसाइट इकोसिस्टम हेतु अभिरुचि की अभिव्यक्ति आमंत्रित करता है: टीएमसी अम्ब्रेला वेबसाइट तथा टाटा मेमोरियल अस्पताल (मुंबई), एचबीसीएच एवं आरसी (विशाखापत्तनम), एमपीएमएमसीसी / एचबीसीएच (वाराणसी), एचबीसीएच एवं आरसी (मुज़फ़्फ़रपुर) और एचबीसीएच (न्यू चंडीगढ़) की वेबसाइटें।' )
				. $para( 'ईओआई बैठक: 09/10/2026, दोपहर 2:00 बजे, डिजिटल लाइब्रेरी, कॉन्फ्रेंस रूम, भूतल, मुख्य भवन, टाटा मेमोरियल अस्पताल, परेल, मुंबई। बैठक में उपस्थित न हो सकने वाले बोलीदाता अपने प्रश्न 06/10/2026 तक capitalequip-purchase.tmh@tmc.gov.in पर भेज सकते हैं।' ),
			'excerpt' => 'टीएमसी वेबसाइट इकोसिस्टम हेतु ईओआई। सीपीपी पोर्टल पर 05/10/2026, अपराह्न 3:00 बजे तक प्रस्तुत करें।',
			'date'    => '2026-09-28 10:00:00',
		),
		array(
			'tmc_ref_no'     => 'TMH/TMH/2026-27/CAP/EO/0009',
			'tmc_kind'       => 'eoi',
			'tmc_closing_at' => '2026-10-05 15:00:00',
			'tmc_opening_at' => '2026-10-06 15:30:00',
			'tmc_portal_url' => 'https://eprocure.gov.in/eprocure/app',
			'tmc_documents'  => $eoi_doc ? array( $eoi_doc ) : array(),
		)
	);
}

$sample_doc = $make_pdf( 'sample-tender-document.pdf', 'Sample tender document', array( 'This is a sample document created for the website demonstration.', 'It shows how tender documents are listed with their file type and size.' ) );
$pair(
	'tmc_tender',
	array( 'slug' => 'sample-tender-laboratory-consumables', 'title' => 'Sample tender: supply of laboratory consumables (demonstration)', 'content' => $para( 'This is a sample tender that demonstrates how tenders are published, listed and archived automatically after the last date.' ), 'date' => $at( -4 ), 'sample' => true ),
	array( 'slug' => 'namuna-nivida-prayogshala', 'title' => 'नमूना निविदा: प्रयोगशाला उपभोज्य सामग्री की आपूर्ति (प्रदर्शन)', 'content' => $para( 'यह एक नमूना निविदा है, जो दर्शाती है कि निविदाएं कैसे प्रकाशित, सूचीबद्ध तथा अंतिम तिथि के बाद स्वतः संग्रहीत होती हैं।' ), 'date' => $at( -4 ), 'sample' => true ),
	array( 'tmc_ref_no' => 'DEMO/2026-27/T-001', 'tmc_kind' => 'tender', 'tmc_closing_at' => $at( 20, '15:00:00' ), 'tmc_opening_at' => $at( 21, '15:30:00' ), 'tmc_documents' => $sample_doc ? array( $sample_doc ) : array() )
);
$pair(
	'tmc_tender',
	array( 'slug' => 'sample-eoi-information-display-screens', 'title' => 'Sample EOI: hospital information display screens (demonstration)', 'content' => $para( 'This sample EOI has passed its last date, so it appears in the archive.' ), 'date' => $at( -40 ), 'sample' => true ),
	array( 'slug' => 'namuna-eoi-suchna-pradarshan', 'title' => 'नमूना ईओआई: अस्पताल सूचना प्रदर्शन स्क्रीन (प्रदर्शन)', 'content' => $para( 'इस नमूना ईओआई की अंतिम तिथि बीत चुकी है, इसलिए यह संग्रह में दिखाई देती है।' ), 'date' => $at( -40 ), 'sample' => true ),
	array( 'tmc_ref_no' => 'DEMO/2026-27/E-002', 'tmc_kind' => 'eoi', 'tmc_closing_at' => $at( -10, '15:00:00' ) )
);

/* ---------------------------------------------------------------- 3: sample job openings */

$pair(
	'tmc_job',
	array( 'slug' => 'sample-senior-resident-medical-oncology', 'title' => 'Sample advertisement: Senior Resident, Medical Oncology (demonstration)', 'content' => $para( 'This sample advertisement shows how recruitment notices, forms and results are published.' ), 'date' => $at( -3 ), 'sample' => true ),
	array( 'slug' => 'namuna-senior-resident', 'title' => 'नमूना विज्ञापन: सीनियर रेज़िडेंट, चिकित्सा ऑन्कोलॉजी (प्रदर्शन)', 'content' => $para( 'यह नमूना विज्ञापन दर्शाता है कि भर्ती सूचनाएं, प्रपत्र एवं परिणाम कैसे प्रकाशित किए जाते हैं।' ), 'date' => $at( -3 ), 'sample' => true ),
	array( 'tmc_ref_no' => 'DEMO/REC/2026-27/01', 'tmc_closing_at' => $at( 25, '17:00:00' ), 'tmc_vacancies' => 2 )
);
$pair(
	'tmc_job',
	array( 'slug' => 'sample-staff-nurse', 'title' => 'Sample advertisement: Staff Nurse (demonstration)', 'content' => $para( 'This sample opening has closed, so it appears in the archive.' ), 'date' => $at( -45 ), 'sample' => true ),
	array( 'slug' => 'namuna-staff-nurse', 'title' => 'नमूना विज्ञापन: स्टाफ़ नर्स (प्रदर्शन)', 'content' => $para( 'यह नमूना भर्ती बंद हो चुकी है, इसलिए यह संग्रह में दिखाई देती है।' ), 'date' => $at( -45 ), 'sample' => true ),
	array( 'tmc_ref_no' => 'DEMO/REC/2026-27/02', 'tmc_closing_at' => $at( -15, '17:00:00' ), 'tmc_vacancies' => 10 )
);

/* ---------------------------------------------------------------- 3: sample events */

$events = array(
	array( 'sample-cancer-awareness-programme', 'Sample event: cancer awareness programme for the public (demonstration)', 'namuna-jagrukta-karyakram', 'नमूना कार्यक्रम: जनसाधारण हेतु कैंसर जागरूकता कार्यक्रम (प्रदर्शन)', 12, '10:00:00', '13:00:00', 'Auditorium (sample venue)', 'सभागार (नमूना स्थल)' ),
	array( 'sample-cme-session', 'Sample event: continuing medical education session (demonstration)', 'namuna-cme-satra', 'नमूना कार्यक्रम: सतत चिकित्सा शिक्षा सत्र (प्रदर्शन)', 30, '14:00:00', '17:00:00', 'Seminar hall (sample venue)', 'सेमिनार हॉल (नमूना स्थल)' ),
	array( 'sample-blood-donation-camp', 'Sample event: blood donation camp (demonstration)', 'namuna-raktdaan-shivir', 'नमूना कार्यक्रम: रक्तदान शिविर (प्रदर्शन)', -20, '09:00:00', '15:00:00', 'Blood bank (sample venue)', 'रक्त कोष (नमूना स्थल)' ),
);
foreach ( $events as list( $en_slug, $en_title, $hi_slug, $hi_title, $days, $start, $end, $venue, $venue_hi ) ) {
	$pair(
		'tmc_event',
		array( 'slug' => $en_slug, 'title' => $en_title, 'content' => $para( 'This is a sample event that demonstrates the events listing, calendar and "Add to calendar" download.' ), 'excerpt' => 'Sample event for the website demonstration.', 'date' => $at( min( -1, $days - 30 ) ), 'sample' => true ),
		array( 'slug' => $hi_slug, 'title' => $hi_title, 'content' => $para( 'यह एक नमूना कार्यक्रम है, जो कार्यक्रम सूची, कैलेंडर एवं "कैलेंडर में जोड़ें" सुविधा को दर्शाता है।' ), 'excerpt' => 'वेबसाइट प्रदर्शन हेतु नमूना कार्यक्रम।', 'date' => $at( min( -1, $days - 30 ) ), 'sample' => true ),
		array( 'tmc_start_at' => $at( $days, $start ), 'tmc_end_at' => $at( $days, $end ), 'tmc_venue' => $venue ),
		array( 'tmc_start_at' => $at( $days, $start ), 'tmc_end_at' => $at( $days, $end ), 'tmc_venue' => $venue_hi )
	);
}

/* ---------------------------------------------------------------- 4: home section */

$home_en = (int) get_option( 'page_on_front' );
$homes   = array( 'en' => $home_en, 'hi' => $home_en ? (int) pll_get_post( $home_en, 'hi' ) : 0 );
foreach ( $homes as $lang => $home_id ) {
	$content = $home_id ? get_post_field( 'post_content', $home_id ) : '';
	if ( ! $content || false === strpos( $content, 'tmc-hero' ) || false !== strpos( $content, 'tmc-opportunities' ) ) {
		continue; // not built yet (fresh install: seed-site-structure builds it) or already there
	}
	$hi      = 'hi' === $lang;
	$section = tmc_section_opportunities( $hi ? 'निविदाएं एवं ईओआई' : 'Tenders & EOIs', $hi ? 'करियर' : 'Careers', $hi ? 'आगामी कार्यक्रम' : 'Upcoming events' );
	$blocks  = parse_blocks( $content );
	$out     = array();
	foreach ( $blocks as $block ) {
		if ( false !== strpos( $block['attrs']['className'] ?? '', 'tmc-network' ) ) {
			$out[] = $section;
		}
		$out[] = $block;
	}
	wp_update_post( array( 'ID' => $home_id, 'post_content' => wp_slash( serialize_blocks( $out ) ) ) );
	WP_CLI::log( "    home ($lang): tenders, careers and events section added" );
}

return true;
