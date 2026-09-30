<?php
/**
 * Page templates (tender §4.3 / §4.6: editors create any page by choosing a template and filling
 * defined content fields, without HTML, CSS or code — R-4.3-2, R-4.6-1).
 *
 * Each template is a WordPress "starter pattern" for pages (blockTypes core/post-content), so the
 * editor offers the list as soon as a new page is created. A template is a sequence of named,
 * non-removable sections:
 *   - locked sections (templateLock "contentOnly"): only the text and links can change;
 *   - flexible areas: editors add approved blocks or TMC component patterns (person, question,
 *     card, callout) but cannot remove or move the area.
 * Text an editor must replace starts with "[Replace:" — such pages cannot be published until every
 * prompt is replaced (tmc-core/editorial-governance.php).
 *
 * Slots for other modules: the contact map, the online-service block and the document list can be
 * supplied through the "tmc_page_template_slot" filter (e.g. the maps module returns its map block).
 */

defined( 'ABSPATH' ) || exit;

/** Template registry: slug => title, description, builder. */
function tmc_page_templates() {
	return array(
		'standard'  => array( __( 'Standard content page', 'tmc' ), __( 'An introduction followed by headings, text, lists, tables and documents.', 'tmc' ), 'tmc_template_standard' ),
		'landing'   => array( __( 'Section landing page', 'tmc' ), __( 'The first page of a section: introduction and cards that lead to the pages below it.', 'tmc' ), 'tmc_template_landing' ),
		'contact'   => array( __( 'Contact page', 'tmc' ), __( 'Address, telephone numbers, email, working hours, location map and directions.', 'tmc' ), 'tmc_template_contact' ),
		'documents' => array( __( 'Document listing page', 'tmc' ), __( 'A list of downloadable documents with help on opening them.', 'tmc' ), 'tmc_template_documents' ),
		'service'   => array( __( 'Service / application page', 'tmc' ), __( 'An online service: what it is, what you need, the application itself and where to get help.', 'tmc' ), 'tmc_template_service' ),
		'people'    => array( __( 'People / leadership page', 'tmc' ), __( 'Profiles of the leadership or of a team: name, designation and a short profile.', 'tmc' ), 'tmc_template_people' ),
		'faq'       => array( __( 'FAQ page', 'tmc' ), __( 'Frequently asked questions as expandable answers.', 'tmc' ), 'tmc_template_faq' ),
	);
}

/** Blocks of one template (optionally with real content instead of the prompts). */
function tmc_page_template_blocks( $slug, array $args = array() ) {
	$templates = tmc_page_templates();
	return isset( $templates[ $slug ] ) ? call_user_func( $templates[ $slug ][2], $args ) : array();
}

/** Text the editor must replace. */
function tmc_prompt( $text ) {
	return ( defined( 'TMC_TEMPLATE_PROMPT' ) ? TMC_TEMPLATE_PROMPT : '[Replace:' ) . ' ' . $text . ']';
}

/* ---------------------------------------------------------------- building blocks */

/** A named section whose structure is fixed; only its text and links can be edited. */
function tmc_b_locked( array $inner, $class, $name ) {
	return tmc_b_group(
		$inner,
		trim( 'tmc-tpl-section ' . $class ),
		array(
			'templateLock' => 'contentOnly',
			'lock'         => array( 'move' => true, 'remove' => true ),
			'metadata'     => array( 'name' => $name ),
		)
	);
}

/** A named area that cannot be removed or moved, into which editors add approved blocks. */
function tmc_b_area( array $inner, $class, $name ) {
	return tmc_b_group(
		$inner,
		trim( 'tmc-tpl-area ' . $class ),
		array(
			'lock'     => array( 'move' => true, 'remove' => true ),
			'metadata' => array( 'name' => $name ),
		)
	);
}

/** A repeatable item (person, card): its inside is fixed, but it can be duplicated or removed. */
function tmc_b_item( array $inner, $class, $name ) {
	return tmc_b_group( $inner, $class, array( 'templateLock' => 'contentOnly', 'metadata' => array( 'name' => $name ) ) );
}

function tmc_b_list( array $items_html, $class = '' ) {
	$inner = array();
	foreach ( $items_html as $html ) {
		$inner[] = tmc_block( 'core/list-item', array(), array(), '<li>' . $html . '</li>' );
	}
	return tmc_block(
		'core/list',
		$class ? array( 'className' => $class ) : array(),
		$inner,
		'<ul class="wp-block-list' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '">',
		'</ul>'
	);
}

/** Accessible disclosure (native details/summary). */
function tmc_b_details( $summary, array $inner ) {
	return tmc_block( 'core/details', array(), $inner, '<details class="wp-block-details"><summary>' . esc_html( $summary ) . '</summary>', '</details>' );
}

function tmc_b_link_paragraph( $text, $url, $class = '' ) {
	return tmc_b_paragraph( sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $text ) ), $class );
}

/** Blocks for a slot another module may fill; $fallback when nothing is registered. */
function tmc_template_slot( $slot, array $fallback ) {
	/**
	 * Filters the blocks placed in a page-template slot ("map", "application", "documents").
	 *
	 * @param array|null $blocks   Block arrays (see tmc_block()), or null for the default.
	 * @param string     $slot     Slot name.
	 * @param array      $fallback The default blocks (e.g. to keep their heading).
	 */
	$blocks = apply_filters( 'tmc_page_template_slot', null, $slot, $fallback );
	return is_array( $blocks ) && $blocks ? array_values( $blocks ) : $fallback;
}

function tmc_tpl_intro( $lead ) {
	return tmc_b_locked( array( tmc_b_paragraph( esc_html( $lead ), 'tmc-lead' ) ), 'tmc-tpl-intro', __( 'Introduction', 'tmc' ) );
}

function tmc_tpl_callout_prompt( $text ) {
	return tmc_b_paragraph( esc_html( tmc_prompt( $text ) ), 'callout' );
}

/* ---------------------------------------------------------------- component patterns */

function tmc_component_person( $name = '', $role = '', $bio = '' ) {
	return tmc_b_item(
		array(
			tmc_b_heading( $name ? $name : tmc_prompt( __( 'Name', 'tmc' ) ), 'tmc-person-name', 3 ),
			tmc_b_paragraph( esc_html( $role ? $role : tmc_prompt( __( 'Designation', 'tmc' ) ) ), 'tmc-person-role' ),
			tmc_b_paragraph( esc_html( $bio ? $bio : tmc_prompt( __( 'Short profile in two or three sentences.', 'tmc' ) ) ) ),
		),
		'tmc-person',
		__( 'Person', 'tmc' )
	);
}

function tmc_component_question( $question = '', $answer = '' ) {
	return tmc_b_details(
		$question ? $question : tmc_prompt( __( 'Question, as a visitor would ask it', 'tmc' ) ),
		array( tmc_b_paragraph( esc_html( $answer ? $answer : tmc_prompt( __( 'Answer in plain language.', 'tmc' ) ) ) ) )
	);
}

function tmc_component_card( $title = '', $text = '', $link_text = '', $url = '#' ) {
	return tmc_b_item(
		array(
			tmc_b_heading( $title ? $title : tmc_prompt( __( 'Card title', 'tmc' ) ), 'tmc-card-title', 3 ),
			tmc_b_paragraph( esc_html( $text ? $text : tmc_prompt( __( 'One sentence about the page this card leads to.', 'tmc' ) ) ) ),
			tmc_b_link_paragraph( $link_text ? $link_text : tmc_prompt( __( 'Link text', 'tmc' ) ), $url, 'tmc-card-link' ),
		),
		'tmc-card',
		__( 'Card', 'tmc' )
	);
}

/* ---------------------------------------------------------------- templates */

function tmc_template_standard( array $a = array() ) {
	$a += array(
		'lead'    => tmc_prompt( __( 'One or two sentences on what this page is about and who it is for.', 'tmc' ) ),
		'heading' => tmc_prompt( __( 'Section heading', 'tmc' ) ),
		'text'    => tmc_prompt( __( 'Section text. Add headings, lists, tables, images and documents with the block inserter.', 'tmc' ) ),
	);
	return array(
		tmc_tpl_intro( $a['lead'] ),
		tmc_b_area( array( tmc_b_heading( $a['heading'] ), tmc_b_paragraph( esc_html( $a['text'] ) ) ), 'tmc-tpl-body', __( 'Page content', 'tmc' ) ),
	);
}

function tmc_template_landing( array $a = array() ) {
	$a += array(
		'lead'  => tmc_prompt( __( 'Introduce this section in one or two sentences.', 'tmc' ) ),
		'cards' => array( array(), array(), array() ),
		'more'  => tmc_prompt( __( 'Anything else visitors to this section should know, or remove this paragraph.', 'tmc' ) ),
	);
	$cards = array();
	foreach ( $a['cards'] as $card ) {
		$card   += array( '', '', '', '#' );
		$cards[] = tmc_component_card( $card[0], $card[1], $card[2], $card[3] );
	}
	return array(
		tmc_tpl_intro( $a['lead'] ),
		tmc_b_area( $cards, 'tmc-cards', __( 'Section cards', 'tmc' ) ),
		tmc_b_area( array( tmc_b_paragraph( esc_html( $a['more'] ) ) ), 'tmc-tpl-body', __( 'More information', 'tmc' ) ),
	);
}

function tmc_template_contact( array $a = array() ) {
	$a += array(
		'lead'       => tmc_prompt( __( 'Who to contact for what, in one or two sentences.', 'tmc' ) ),
		'address'    => tmc_prompt( __( 'Postal address with PIN code', 'tmc' ) ),
		'phones'     => array( tmc_prompt( __( 'Reception: telephone number', 'tmc' ) ), tmc_prompt( __( 'Helpline: telephone number and hours', 'tmc' ) ) ),
		'email'      => tmc_prompt( __( 'Email address', 'tmc' ) ),
		'hours'      => tmc_prompt( __( 'Office days and hours', 'tmc' ) ),
		'directions' => tmc_prompt( __( 'Nearest railway station, bus stops and parking.', 'tmc' ) ),
	);
	return array(
		tmc_tpl_intro( $a['lead'] ),
		tmc_b_locked(
			array(
				tmc_b_columns(
					array(
						array( null, array( tmc_b_heading( __( 'Address', 'tmc' ), '', 2 ), tmc_b_paragraph( esc_html( $a['address'] ) ), tmc_b_heading( __( 'Email', 'tmc' ), '', 2 ), tmc_b_paragraph( esc_html( $a['email'] ) ) ) ),
						array( null, array( tmc_b_heading( __( 'Telephone', 'tmc' ), '', 2 ), tmc_b_list( array_map( 'esc_html', $a['phones'] ) ), tmc_b_heading( __( 'Working hours', 'tmc' ), '', 2 ), tmc_b_paragraph( esc_html( $a['hours'] ) ) ) ),
					),
					'tmc-contact-columns'
				),
			),
			'tmc-contact-details',
			__( 'Contact details', 'tmc' )
		),
		tmc_b_area(
			tmc_template_slot( 'map', array( tmc_tpl_callout_prompt( __( 'Insert the location map block here. Until then, delete this paragraph.', 'tmc' ) ) ) ),
			'tmc-slot tmc-slot-map',
			__( 'Location map', 'tmc' )
		),
		tmc_b_locked( array( tmc_b_heading( __( 'How to reach us', 'tmc' ) ), tmc_b_paragraph( esc_html( $a['directions'] ) ) ), 'tmc-contact-directions', __( 'Directions', 'tmc' ) ),
	);
}

function tmc_template_documents( array $a = array() ) {
	$a += array(
		'lead'    => tmc_prompt( __( 'What these documents are and who they are for.', 'tmc' ) ),
		'heading' => tmc_prompt( __( 'Group heading, for example the year', 'tmc' ) ),
	);
	$help = sprintf(
		/* translators: %s: link to the Screen Reader Access page */
		esc_html__( 'Documents are provided as PDF files unless stated otherwise; the file type and size are shown next to each link. A free PDF reader is needed to open them. For help with assistive technology, see %s.', 'tmc' ),
		sprintf( '<a href="%s">%s</a>', esc_url( home_url( '/screen-reader-access/' ) ), esc_html__( 'Screen Reader Access', 'tmc' ) )
	);
	return array(
		tmc_tpl_intro( $a['lead'] ),
		tmc_b_area(
			tmc_template_slot( 'documents', array( tmc_b_heading( $a['heading'] ), tmc_b_dynamic( 'core/file' ), tmc_b_dynamic( 'core/file' ) ) ),
			'tmc-slot tmc-slot-documents',
			__( 'Documents', 'tmc' )
		),
		tmc_b_locked( array( tmc_b_heading( __( 'Opening the documents', 'tmc' ) ), tmc_b_paragraph( $help ) ), 'tmc-doc-help', __( 'Help with documents', 'tmc' ) ),
	);
}

function tmc_template_service( array $a = array() ) {
	$a += array(
		'lead'   => tmc_prompt( __( 'What this service does, in one or two sentences.', 'tmc' ) ),
		'before' => array( tmc_prompt( __( 'Who can use this service', 'tmc' ) ), tmc_prompt( __( 'Documents or details you will need', 'tmc' ) ), tmc_prompt( __( 'How long it takes and what happens next', 'tmc' ) ) ),
		'help'   => tmc_prompt( __( 'Helpline number, email address and hours for this service.', 'tmc' ) ),
	);
	return array(
		tmc_tpl_intro( $a['lead'] ),
		tmc_b_locked( array( tmc_b_heading( __( 'Before you start', 'tmc' ) ), tmc_b_list( array_map( 'esc_html', $a['before'] ) ) ), 'tmc-service-before', __( 'Before you start', 'tmc' ) ),
		tmc_b_area(
			tmc_template_slot( 'application', array( tmc_tpl_callout_prompt( __( 'Insert the online service block from the TMC application gateway here.', 'tmc' ) ) ) ),
			'tmc-slot tmc-slot-application',
			__( 'Online service', 'tmc' )
		),
		tmc_b_locked( array( tmc_b_heading( __( 'Need help?', 'tmc' ) ), tmc_b_paragraph( esc_html( $a['help'] ) ) ), 'tmc-service-help callout', __( 'Help', 'tmc' ) ),
	);
}

function tmc_template_people( array $a = array() ) {
	$a += array(
		'lead'   => tmc_prompt( __( 'Who is on this page, for example the leadership of the institution.', 'tmc' ) ),
		'people' => array( array(), array(), array() ),
	);
	$people = array();
	foreach ( $a['people'] as $person ) {
		$person  += array( '', '', '' );
		$people[] = tmc_component_person( $person[0], $person[1], $person[2] );
	}
	return array(
		tmc_tpl_intro( $a['lead'] ),
		tmc_b_area( $people, 'tmc-people', __( 'People', 'tmc' ) ),
	);
}

function tmc_template_faq( array $a = array() ) {
	$a += array(
		'lead'      => tmc_prompt( __( 'What these questions are about.', 'tmc' ) ),
		'questions' => array( array(), array(), array() ),
		'contact'   => tmc_prompt( __( 'Who to contact if the answer is not here, with telephone and email.', 'tmc' ) ),
	);
	$questions = array();
	foreach ( $a['questions'] as $question ) {
		$question   += array( '', '' );
		$questions[] = tmc_component_question( $question[0], $question[1] );
	}
	return array(
		tmc_tpl_intro( $a['lead'] ),
		tmc_b_area( $questions, 'tmc-faq', __( 'Questions', 'tmc' ) ),
		tmc_b_locked( array( tmc_b_heading( __( 'Still have a question?', 'tmc' ) ), tmc_b_paragraph( esc_html( $a['contact'] ) ) ), 'tmc-faq-contact', __( 'Contact', 'tmc' ) ),
	);
}

/* ---------------------------------------------------------------- registration */

add_action( 'init', 'tmc_register_page_template_category' );
function tmc_register_page_template_category() {
	register_block_pattern_category( 'tmc-page-templates', array( 'label' => __( 'TMC: Page templates', 'tmc' ) ) );
}

// Patterns are only read by the REST API (block editor) and admin screens, so they are built there.
add_action( 'rest_api_init', 'tmc_register_page_templates' );
add_action( 'admin_init', 'tmc_register_page_templates' );
function tmc_register_page_templates() {
	$registry = WP_Block_Patterns_Registry::get_instance();
	if ( $registry->is_registered( 'tmc/page-standard' ) ) {
		return;
	}
	foreach ( tmc_page_templates() as $slug => list( $title, $description ) ) {
		register_block_pattern(
			'tmc/page-' . $slug,
			array(
				'title'       => $title,
				'description' => $description,
				'content'     => serialize_blocks( tmc_page_template_blocks( $slug ) ),
				'categories'  => array( 'tmc-page-templates' ),
				'blockTypes'  => array( 'core/post-content' ),
				'postTypes'   => array( 'page' ),
				'keywords'    => array( 'template', 'page' ),
			)
		);
	}

	// Components editors add inside the flexible areas.
	$components = array(
		'person'   => array( __( 'Person', 'tmc' ), __( 'Name, designation and a short profile.', 'tmc' ), tmc_component_person() ),
		'question' => array( __( 'Question and answer', 'tmc' ), __( 'An expandable question for FAQ pages.', 'tmc' ), tmc_component_question() ),
		'card'     => array( __( 'Card', 'tmc' ), __( 'Title, one sentence and a link.', 'tmc' ), tmc_component_card() ),
		'callout'  => array( __( 'Callout', 'tmc' ), __( 'A highlighted note.', 'tmc' ), tmc_tpl_callout_prompt( __( 'Important information to highlight.', 'tmc' ) ) ),
	);
	foreach ( $components as $slug => list( $title, $description, $block ) ) {
		register_block_pattern(
			'tmc/component-' . $slug,
			array(
				'title'       => $title,
				'description' => $description,
				'content'     => serialize_blocks( array( $block ) ),
				'categories'  => array( 'tmc-page' ),
			)
		);
	}
}
