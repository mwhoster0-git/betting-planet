/**
 * ECS Colour Editor — Default Colours tab UI
 *
 * Implements the Default / Dark Mode tab switcher inside the
 * "Default Colours" kit section in Elementor Site Settings.
 *
 * Behaviour
 * ──────────
 * • Default mode  → show the "Default" colour picker per row,
 *                   hide the "Dark Mode" picker
 * • Dark mode     → show the "Dark Mode" picker per row,
 *                   hide the "Default" picker
 *                   If a Dark Mode picker has no value set, the row
 *                   gets a visual "fallback" indicator showing the
 *                   default colour as a reference swatch.
 *
 * All show/hide is done via CSS classes on the section wrapper so
 * that rows added later (custom colours) are automatically correct.
 */

( function ( $ ) {
	'use strict';

	// ── Constants ──────────────────────────────────────────────────────────

	var SECTION_SELECTOR   = '.elementor-control-section_global_colors';
	var MODE_CTRL_SELECTOR = '.elementor-control-ecs_view_mode';
	var INPUT_SELECTOR     = 'input[name*="elementor-choose-ecs_view_mode"]';

	var REPEATERS = [ 'system_colors', 'custom_colors' ];

	var CLASS_MODE_DEFAULT = 'ecs-mode-default';
	var CLASS_MODE_DARK    = 'ecs-mode-dark';

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Find the Default Colours section in the currently-open panel.
	 */
	function getSection() {
		return $( '#elementor-panel' ).find( SECTION_SELECTOR );
	}

	/**
	 * Apply the chosen mode to the controls-stack ancestor.
	 * Elementor renders section headers and their controls as siblings in the DOM,
	 * so we must apply the class to their common ancestor (elementor-controls-stack)
	 * for CSS descendant selectors to work.
	 */
	function applyMode( mode ) {
		var $section = getSection();
		if ( ! $section.length ) { return; }

		var $target = $section.closest( '.elementor-controls-stack' );
		if ( ! $target.length ) { $target = $section; }

		$target
			.toggleClass( CLASS_MODE_DEFAULT, mode !== 'dark' )
			.toggleClass( CLASS_MODE_DARK,    mode === 'dark' );

		// Sync the radio button checked state so CSS :checked tab styles apply
		$( INPUT_SELECTOR + '[value="' + mode + '"]' ).prop( 'checked', true );

		// Refresh fallback swatches whenever we switch to dark mode
		if ( mode === 'dark' ) {
			refreshFallbackSwatches( $target );
		}
	}

	/**
	 * For each repeater row in dark mode: if the dark_color input is empty,
	 * read the default colour value and show it as a reference swatch so the
	 * designer knows what will be used as the fallback.
	 */
	function refreshFallbackSwatches( $section ) {
		REPEATERS.forEach( function ( repeater ) {
			var $rows = $section.find( '.elementor-control-' + repeater + ' .elementor-repeater-row-controls' );

			$rows.each( function () {
				var $row       = $( this );
				var $darkInput = $row.find( '.elementor-control-dark_color input[type="text"], .elementor-control-dark_color .pickr' );
				var $colorEl   = $row.find( '.elementor-control-color' );
				var $darkEl    = $row.find( '.elementor-control-dark_color' );

				// Read the current hex value of the default colour
				var defaultVal = $colorEl.find( 'input[type="text"]' ).val() || '';

				// If dark_color has no value, mark row as "will fallback"
				var darkVal = $darkEl.find( 'input[type="text"]' ).val() || '';

				$darkEl.toggleClass( 'ecs-dark-fallback', ! darkVal && !! defaultVal );

				if ( ! darkVal && defaultVal ) {
					// Update (or create) the reference swatch
					var $swatch = $darkEl.find( '.ecs-fallback-swatch' );
					if ( ! $swatch.length ) {
						$swatch = $( '<span class="ecs-fallback-swatch"></span>' );
						$darkEl.find( '.elementor-control-field' ).append( $swatch );
					}
					$swatch.css( 'background-color', defaultVal ).attr( 'title', 'Fallback: ' + defaultVal );
				} else {
					$darkEl.find( '.ecs-fallback-swatch' ).remove();
				}
			} );
		} );
	}

	// ── Initialisation ──────────────────────────────────────────────────────

	function init() {
		// 1. Apply initial mode when the panel section first renders
		applyModeFromKit();

		// 2. Re-apply whenever the panel refreshes (navigation or re-render)
		try {
			var panelView = elementor.getPanelView();
			if ( panelView ) {
				panelView.on( 'set:page', applyModeFromKit );
			}
		} catch ( e ) {
			// Panel not ready yet — MutationObserver handles this case
		}

		// 3. Listen for the mode selector CHOOSE control changing via
		//    delegated event on the panel (works for initial render + re-renders)
		$( '#elementor-panel' ).on(
			'change.ecs_colour_mode',
			INPUT_SELECTOR,
			function () {
				var mode = $( MODE_CTRL_SELECTOR ).find( '.elementor-choices input:checked' ).val()
					|| $( this ).val()
					|| 'default';
				applyMode( mode );
			}
		);

		// 4. Also react to Elementor's own choose-control click (it uses labels/radio)
		$( '#elementor-panel' ).on(
			'click.ecs_colour_mode',
			MODE_CTRL_SELECTOR + ' label',
			function () {
				// Give the radio time to update before reading the value
				setTimeout( function () {
					var mode = $( MODE_CTRL_SELECTOR ).find( '.elementor-choices input:checked' ).val() || 'default';
					applyMode( mode );
				}, 0 );
			}
		);

		// 5. Re-check fallback swatches when repeater rows change
		$( '#elementor-panel' ).on(
			'input.ecs_colour_fallback',
			'.elementor-control-system_colors input, .elementor-control-custom_colors input',
			function () {
				var $section = getSection();
				var $target = $section.length ? $section.closest( '.elementor-controls-stack' ) : $();
				if ( $target.hasClass( CLASS_MODE_DARK ) ) {
					refreshFallbackSwatches( $target );
				}
			}
		);

		// 6. MutationObserver: apply mode as soon as the section appears in DOM.
		//    Handles tab navigation within Site Settings where panel:init / set:page
		//    fire before the Default Colours section is actually rendered.
		var panelEl = document.getElementById( 'elementor-panel' );
		if ( panelEl && window.MutationObserver ) {
			new MutationObserver( function () {
				var $section = getSection();
				if ( ! $section.length ) { return; }
				var $target = $section.closest( '.elementor-controls-stack' );
				if ( ! $target.length ) { $target = $section; }
				if ( ! $target.hasClass( CLASS_MODE_DEFAULT ) &&
					 ! $target.hasClass( CLASS_MODE_DARK ) ) {
					applyModeFromKit();
				}
			} ).observe( panelEl, { childList: true, subtree: true } );
		}
	}

	/**
	 * Apply the initial mode when entering the Default Colours section.
	 * Always starts in Default mode — view mode is a UI preference, not persistent data.
	 */
	function applyModeFromKit() {
		// Small delay to let the panel DOM render
		setTimeout( function () {
			applyMode( 'default' );
		}, 100 );
	}

	// ── Boot ────────────────────────────────────────────────────────────────

	$( window ).on( 'elementor:init', function () {
		elementor.on( 'panel:init', function () {
			init();
		} );
	} );

	// Fallback: if elementor:init already fired
	if ( window.elementor ) {
		init();
	}

} )( jQuery );
