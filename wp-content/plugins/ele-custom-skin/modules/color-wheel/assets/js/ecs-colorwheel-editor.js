/* global ecsColorWheel, jQuery */
( function ( $ ) {
	'use strict';

	// ── Color math ────────────────────────────────────────────────────────────

	function hexToHsl( hex ) {
		hex = hex.replace( /^#/, '' );
		if ( hex.length === 3 ) {
			hex = hex.split( '' ).map( function ( c ) { return c + c; } ).join( '' );
		}
		var r = parseInt( hex.substr( 0, 2 ), 16 ) / 255;
		var g = parseInt( hex.substr( 2, 2 ), 16 ) / 255;
		var b = parseInt( hex.substr( 4, 2 ), 16 ) / 255;
		var max = Math.max( r, g, b ), min = Math.min( r, g, b );
		var h, s, l = ( max + min ) / 2;
		if ( max === min ) {
			h = s = 0;
		} else {
			var d = max - min;
			s = l > 0.5 ? d / ( 2 - max - min ) : d / ( max + min );
			switch ( max ) {
				case r: h = ( ( g - b ) / d + ( g < b ? 6 : 0 ) ) / 6; break;
				case g: h = ( ( b - r ) / d + 2 ) / 6; break;
				case b: h = ( ( r - g ) / d + 4 ) / 6; break;
			}
		}
		return [ h * 360, s * 100, l * 100 ];
	}

	function hslToHex( h, s, l ) {
		h = ( ( h % 360 ) + 360 ) % 360;
		s /= 100; l /= 100;
		var c = ( 1 - Math.abs( 2 * l - 1 ) ) * s;
		var x = c * ( 1 - Math.abs( ( h / 60 ) % 2 - 1 ) );
		var m = l - c / 2;
		var r = 0, g = 0, b = 0;
		if      ( h < 60  ) { r = c; g = x; b = 0; }
		else if ( h < 120 ) { r = x; g = c; b = 0; }
		else if ( h < 180 ) { r = 0; g = c; b = x; }
		else if ( h < 240 ) { r = 0; g = x; b = c; }
		else if ( h < 300 ) { r = x; g = 0; b = c; }
		else                { r = c; g = 0; b = x; }
		var toHex = function ( v ) {
			return Math.round( ( v + m ) * 255 ).toString( 16 ).padStart( 2, '0' );
		};
		return '#' + toHex( r ) + toHex( g ) + toHex( b );
	}

	function rgbaToHex( rgba ) {
		var m = rgba.match( /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i );
		if ( ! m ) { return null; }
		return '#' +
			parseInt( m[1] ).toString(16).padStart( 2, '0' ) +
			parseInt( m[2] ).toString(16).padStart( 2, '0' ) +
			parseInt( m[3] ).toString(16).padStart( 2, '0' );
	}

	function generatePalette( hex, type ) {
		var hsl = hexToHsl( hex );
		var h = hsl[0], s = hsl[1], l = hsl[2];
		switch ( type ) {
			case 'complementary':
				return [ hex, hslToHex( h + 180, s, l ) ];
			case 'monochromatic':
				return [ 15, 30, 50, 65, 80 ].map( function ( lightness ) {
					return hslToHex( h, s, lightness );
				} );
			case 'analogous':
				return [ -60, -30, 0, 30, 60 ].map( function ( offset ) {
					return hslToHex( h + offset, s, l );
				} );
			case 'triadic':
				return [ 0, 120, 240 ].map( function ( offset ) {
					return hslToHex( h + offset, s, l );
				} );
			case 'tetradic':
				return [ 0, 90, 180, 270 ].map( function ( offset ) {
					return hslToHex( h + offset, s, l );
				} );
			default:
				return [ hex ];
		}
	}

	// ── Constants ─────────────────────────────────────────────────────────────

	var HARMONY_TYPES = [
		{ id: 'analogous',     label: 'Analog'  },
		{ id: 'complementary', label: 'Compl.'  },
		{ id: 'monochromatic', label: 'Mono'    },
		{ id: 'triadic',       label: 'Triadic' },
		{ id: 'tetradic',      label: 'Tetra'   },
	];

	var CW_ICON = '<span class="ecs-cw-icon" aria-hidden="true"></span>';

	// ── State ──────────────────────────────────────────────────────────────────

	var $activeControl  = null;   // .elementor-control-type-color (classic only)
	var activeKey       = null;   // Elementor control key (classic only)
	var pickerMode      = null;   // 'pickr' | 'atomic'
	var activeType      = 'analogous';
	var currentColor    = '#3a86ff';
	var generatedColors = [];
	var panelExpanded   = false;
	var isApplying      = false;   // guard against feedback loop when we set the picker value
	var pcrBtnObserver  = null;    // MutationObserver for active .pcr-button style changes
	var atomicPollId    = null;    // interval for polling Atomic hex input
	var atomicPollLast  = null;    // last seen Atomic color (to debounce unchanged values)

	// ── Helpers ────────────────────────────────────────────────────────────────

	function normalizeHex( val ) {
		val = ( val || '' ).trim().replace( /^#/, '' );
		if ( /^([0-9a-f]{3}|[0-9a-f]{6})$/i.test( val ) ) { return '#' + val; }
		return null;
	}

	function getControlKey( $control ) {
		var skip = [
			'elementor-control', 'elementor-control-type-color',
			'elementor-control-responsive-desktop', 'elementor-control-responsive-tablet',
			'elementor-control-responsive-mobile', 'elementor-control-dynamic-enabled',
			'elementor-control-dynamic-settings', 'elementor-control-label-block',
			'elementor-control-unit-1', 'elementor-control-tag-area',
		];
		var classes = ( $control[0].className || '' ).split( /\s+/ );
		for ( var i = 0; i < classes.length; i++ ) {
			var cls = classes[ i ];
			if ( cls.startsWith( 'elementor-control-' ) && skip.indexOf( cls ) === -1 &&
			     ! cls.startsWith( 'elementor-control-type-' ) &&
			     ! cls.startsWith( 'elementor-control-responsive-' ) ) {
				return cls.replace( 'elementor-control-', '' );
			}
		}
		return null;
	}

	function readPcrColor( $control ) {
		var $btn = $control.find( '.pcr-button' ).first();
		if ( ! $btn.length ) { return null; }
		var raw = $btn[0].style.getPropertyValue( '--pcr-color' ).trim();
		if ( ! raw ) { return null; }
		if ( raw.startsWith( '#' ) ) { return normalizeHex( raw ); }
		return rgbaToHex( raw );
	}

	// ── Elementor API apply (classic widgets) ─────────────────────────────────

	function applyToElementor( hex ) {
		if ( ! activeKey || ! window.$e || ! window.elementor ) { return false; }
		try {
			var page = elementor.getPanelView().getCurrentPageView();
			if ( ! page ) { return false; }
			var modelId = page.model && page.model.get( 'id' );
			if ( ! modelId ) { return false; }
			var container = elementor.getContainer( modelId );
			if ( ! container ) { return false; }
			$e.run( 'document/elements/settings', {
				container: container,
				settings:  { [ activeKey ]: hex },
				options:   { external: true },
			} );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	// ── React input apply (Atomic Widgets) ────────────────────────────────────

	function getAtomicHexInput() {
		var popup = document.getElementById( 'eui-color-picker-popover' );
		if ( ! popup ) { return null; }
		// The hex input: type=text, not aria-hidden, not the alpha/opacity input
		return popup.querySelector(
			'input[type="text"]:not([aria-hidden="true"]):not(.MuiInputBase-inputAdornedEnd)'
		);
	}

	function applyToAtomicPicker( hex ) {
		var input = getAtomicHexInput();
		if ( ! input ) { return false; }
		// React controlled input needs the native value setter to trigger onChange
		var setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
		setter.call( input, hex.replace( '#', '' ) );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		return true;
	}

	// ── Panel HTML ─────────────────────────────────────────────────────────────

	function buildPanelHtml() {
		var tabs = HARMONY_TYPES.map( function ( t ) {
			var active = t.id === activeType ? ' is-active' : '';
			return '<button type="button" class="ecs-cw-tab' + active + '" data-type="' + t.id + '">' + t.label + '</button>';
		} ).join( '' );

		var toggleClass = panelExpanded ? ' is-open' : '';
		var contentHidden = panelExpanded ? '' : ' hidden';

		return [
			'<div class="ecs-cw-pickr-panel">',
			'  <button type="button" class="ecs-cw-pickr-toggle' + toggleClass + '">',
			'    ' + CW_ICON + ' Color Wheel',
			'    <span class="ecs-cw-toggle-arrow">&#9660;</span>',
			'  </button>',
			'  <div class="ecs-cw-pickr-content"' + contentHidden + '>',
			'    <div class="ecs-cw-tabs">' + tabs + '</div>',
			'    <div class="ecs-cw-swatches"></div>',
			'    <div class="ecs-cw-save-row">',
			'      <input type="text" class="ecs-cw-palette-name" placeholder="Palette name" maxlength="60">',
			'      <button type="button" class="ecs-cw-save-btn">Save</button>',
			'    </div>',
			'    <div class="ecs-cw-saved-title">Saved palettes</div>',
			'    <div class="ecs-cw-saved-list"></div>',
			'  </div>',
			'</div>',
		].join( '' );
	}

	// ── Active popup container ─────────────────────────────────────────────────

	// Returns the jQuery-wrapped element that contains our panel
	function getActiveContainer() {
		if ( pickerMode === 'atomic' ) {
			var popup = document.getElementById( 'eui-color-picker-popover' );
			return popup ? $( popup ).find( '.MuiPopover-paper' ) : $();
		}
		// Pickr: find visible .pcr-app
		var $app = $( '.pcr-app.visible' );
		if ( $app.length ) { return $app.first(); }
		return $( '.pcr-app' ).filter( function () {
			return $( this ).css( 'visibility' ) !== 'hidden' &&
			       $( this ).css( 'display' ) !== 'none';
		} ).first();
	}

	// ── Live sync helpers ─────────────────────────────────────────────────────

	// Pickr: observe .pcr-button style — fires on drag, global-color click, anything.
	// The synchronous callback does nothing except reset a timer; the actual color read
	// happens lazily inside the debounced callback so the hot path has near-zero cost.
	function watchActivePcrButton() {
		if ( pcrBtnObserver ) { pcrBtnObserver.disconnect(); pcrBtnObserver = null; }
		if ( ! $activeControl || ! $activeControl.length ) { return; }
		var btn  = $activeControl.find( '.pcr-button' )[0];
		if ( ! btn ) { return; }
		var $ctrl = $activeControl;
		pcrBtnObserver = new MutationObserver( function () {
			if ( isApplying || ! panelExpanded ) { return; }
			clearTimeout( syncDebounce );
			syncDebounce = setTimeout( function () {
				var hex = readPcrColor( $ctrl );
				if ( ! hex || hex === currentColor ) { return; }
				currentColor = hex;
				var $c = getActiveContainer();
				if ( $c.length ) { renderSwatches( $c, generatePalette( currentColor, activeType ) ); }
			}, 80 );
		} );
		pcrBtnObserver.observe( btn, { attributes: true, attributeFilter: [ 'style' ] } );
	}

	// Atomic: poll the hex input while the popover is visible (react-colorful drag doesn't fire input events)
	function isAtomicPopupVisible() {
		var popup = document.getElementById( 'eui-color-picker-popover' );
		return popup ? ( popup.offsetWidth > 0 && popup.offsetHeight > 0 ) : false;
	}

	function startAtomicColorPoll() {
		if ( atomicPollId ) { clearInterval( atomicPollId ); atomicPollId = null; }
		atomicPollLast = null;
		atomicPollId = setInterval( function () {
			if ( ! isAtomicPopupVisible() ) {
				clearInterval( atomicPollId );
				atomicPollId = null;
				return;
			}
			if ( isApplying || ! panelExpanded ) { return; }
			var input = getAtomicHexInput();
			if ( ! input ) { return; }
			var raw = input.value.trim();
			var hex = normalizeHex( raw.length === 6 ? '#' + raw : raw );
			if ( hex && hex !== atomicPollLast ) {
				atomicPollLast = hex;
				syncFromPicker( hex );
			}
		}, 150 );
	}

	// ── Reposition popup if Color Wheel panel overflows viewport ─────────────

	function repositionIfNeeded( $container ) {
		setTimeout( function () {
			var el = $container[0];
			if ( ! el ) { return; }
			var rect     = el.getBoundingClientRect();
			var margin   = 8;
			var overflow = rect.bottom - ( window.innerHeight - margin );
			if ( overflow <= 0 ) { return; }
			var topStyle = parseFloat( el.style.top );
			var baseTop  = isNaN( topStyle ) ? rect.top : topStyle;
			el.style.top = Math.max( margin, baseTop - overflow ) + 'px';
		}, 0 );
	}

	// ── Inject / refresh ───────────────────────────────────────────────────────

	function injectPanel( $container ) {
		if ( ! $container.length ) { return; }
		if ( $container.find( '.ecs-cw-pickr-panel' ).length ) {
			refreshPanel( $container );
			if ( panelExpanded ) { repositionIfNeeded( $container ); }
			return;
		}
		$container.append( buildPanelHtml() );
		refreshPanel( $container );
		if ( panelExpanded ) { repositionIfNeeded( $container ); }
	}

	function refreshPanel( $container ) {
		$container.find( '.ecs-cw-tab' ).removeClass( 'is-active' );
		$container.find( '.ecs-cw-tab[data-type="' + activeType + '"]' ).addClass( 'is-active' );
		renderSwatches( $container, generatePalette( currentColor, activeType ) );
		loadPalettes( function ( palettes ) { renderSaved( $container, palettes ); } );
	}

	// ── Rendering ─────────────────────────────────────────────────────────────

	function renderSwatches( $container, colors ) {
		generatedColors = colors;
		var $wrap = $container.find( '.ecs-cw-swatches' ).empty();
		colors.forEach( function ( hex ) {
			$wrap.append(
				$( '<button type="button" class="ecs-cw-swatch" title="' + hex + '">' )
					.css( 'background-color', hex )
					.data( 'hex', hex )
			);
		} );
	}

	function renderSaved( $container, palettes ) {
		var $list = $container.find( '.ecs-cw-saved-list' ).empty();
		if ( ! palettes.length ) {
			$list.append( '<p class="ecs-cw-no-saved">No palettes saved yet.</p>' );
			return;
		}
		palettes.forEach( function ( palette ) {
			var $row = $( '<div class="ecs-cw-saved-row">' );
			$row.append( $( '<span class="ecs-cw-saved-name">' ).text( palette.name ) );

			var $swatchWrap = $( '<span class="ecs-cw-saved-swatches">' );
			palette.colors.forEach( function ( hex ) {
				$swatchWrap.append(
					$( '<button type="button" class="ecs-cw-swatch ecs-cw-swatch--sm" title="' + hex + '">' )
						.css( 'background-color', hex )
						.data( 'hex', hex )
				);
			} );
			$row.append( $swatchWrap );

			$row.append(
				$( '<button type="button" class="ecs-cw-del-btn" title="Delete palette">&times;</button>' )
					.data( 'paletteName', palette.name )
			);

			$list.append( $row );
		} );
	}

	// ── Apply color ────────────────────────────────────────────────────────────

	function applyColor( hex ) {
		currentColor = hex;
		isApplying   = true;

		if ( pickerMode === 'atomic' ) {
			applyToAtomicPicker( hex );
		} else {
			// Classic Elementor: push to model + update Pickr UI
			applyToElementor( hex );
			if ( $activeControl ) {
				$activeControl.find( '.pcr-button' ).first()[0].style.setProperty( '--pcr-color', hex );
			}
			var $pcrApp = getActiveContainer();
			if ( $pcrApp.length ) {
				var $result = $pcrApp.find( '.pcr-result' );
				if ( $result.length ) {
					$result.val( hex.replace( '#', '' ) );
					$result[0].dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
			}
		}

		// Re-render swatches with new base color
		var $c = getActiveContainer();
		if ( $c.length ) {
			renderSwatches( $c, generatePalette( hex, activeType ) );
		}

		setTimeout( function () { isApplying = false; }, 100 );
	}

	// Called when the user types in the picker hex input — syncs swatches to new color
	var syncDebounce = null;
	function syncFromPicker( hex ) {
		if ( isApplying || ! panelExpanded ) { return; }
		hex = normalizeHex( hex );
		if ( ! hex || hex === currentColor ) { return; }
		currentColor = hex;
		clearTimeout( syncDebounce );
		syncDebounce = setTimeout( function () {
			var $c = getActiveContainer();
			if ( $c.length ) {
				renderSwatches( $c, generatePalette( currentColor, activeType ) );
			}
		}, 80 );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	function loadPalettes( cb ) {
		$.post( ecsColorWheel.ajaxUrl, {
			action: 'ecs_cw_get_palettes',
			nonce:  ecsColorWheel.nonce,
		}, function ( res ) {
			if ( res.success && cb ) { cb( res.data ); }
		} );
	}

	function savePalette( name, colors ) {
		$.post( ecsColorWheel.ajaxUrl, {
			action: 'ecs_cw_save_palette',
			nonce:  ecsColorWheel.nonce,
			name:   name,
			colors: colors,
		}, function ( res ) {
			if ( res.success ) {
				var $c = getActiveContainer();
				if ( $c.length ) {
					renderSaved( $c, res.data );
					$c.find( '.ecs-cw-palette-name' ).val( '' );
				}
			}
		} );
	}

	function deletePalette( name ) {
		$.post( ecsColorWheel.ajaxUrl, {
			action: 'ecs_cw_delete_palette',
			nonce:  ecsColorWheel.nonce,
			name:   name,
		}, function ( res ) {
			if ( res.success ) {
				var $c = getActiveContainer();
				if ( $c.length ) { renderSaved( $c, res.data ); }
			}
		} );
	}

	// ── Init ──────────────────────────────────────────────────────────────────

	function init() {

		// ── Classic Elementor: Pickr button click ──────────────────────────────
		$( document ).on( 'click', '#elementor-panel .elementor-control-type-color .pcr-button', function () {
			pickerMode     = 'pickr';
			var $control   = $( this ).closest( '.elementor-control-type-color' );
			$activeControl = $control;
			activeKey      = getControlKey( $control );
			currentColor   = readPcrColor( $control ) || '#3a86ff';
			watchActivePcrButton();

			var attempts = 0;
			var poller = setInterval( function () {
				attempts++;
				var $container = getActiveContainer();
				if ( $container.length ) {
					clearInterval( poller );
					injectPanel( $container );
				} else if ( attempts >= 15 ) {
					clearInterval( poller );
				}
			}, 20 );
		} );

		// ── Atomic Widgets: click on color swatch button ──────────────────────
		// Handles both: popup already in DOM (reused) and popup added dynamically
		$( document ).on( 'click', '#elementor-panel .MuiColorField-root button', function () {
			pickerMode     = 'atomic';
			$activeControl = null;
			activeKey      = null;

			var attempts = 0;
			var poller = setInterval( function () {
				attempts++;
				var popup = document.getElementById( 'eui-color-picker-popover' );
				var paper = popup && popup.querySelector( '.MuiPopover-paper' );
				// Paper is ready when it has the react-colorful picker inside it
				if ( paper && paper.querySelector( '.react-colorful' ) ) {
					clearInterval( poller );
					var input = getAtomicHexInput();
					if ( input && input.value.trim() ) {
						currentColor = normalizeHex( input.value ) || '#3a86ff';
					} else {
						currentColor = '#3a86ff';
					}
					injectPanel( $( paper ) );
					startAtomicColorPoll();
				} else if ( attempts >= 20 ) {
					clearInterval( poller );
				}
			}, 25 );
		} );

		// Fallback: MutationObserver for when popup is added to DOM for the first time
		new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( m ) {
				m.addedNodes.forEach( function ( node ) {
					if ( node.nodeType !== 1 || node.id !== 'eui-color-picker-popover' ) { return; }
					// Already handled by the click handler above; this is a safety net
					if ( pickerMode !== 'atomic' ) {
						pickerMode = 'atomic';
						setTimeout( function () {
							var $paper = $( node ).find( '.MuiPopover-paper' );
							if ( $paper.length ) { injectPanel( $paper ); }
						}, 100 );
					}
				} );
			} );
		} ).observe( document.body, { childList: true } );

		// ── Shared delegated events ────────────────────────────────────────────

		// Toggle open / closed
		$( document ).on( 'click', '.ecs-cw-pickr-toggle', function ( e ) {
			e.stopPropagation();
			var $toggle  = $( this );
			var $content = $toggle.next( '.ecs-cw-pickr-content' );
			panelExpanded = ! panelExpanded;
			$toggle.toggleClass( 'is-open', panelExpanded );
			if ( panelExpanded ) {
				$content.removeAttr( 'hidden' );
				repositionIfNeeded( getActiveContainer() );
			} else {
				$content.attr( 'hidden', '' );
			}
		} );

		// Harmony type tab
		$( document ).on( 'click', '.ecs-cw-tab', function ( e ) {
			e.stopPropagation();
			var $tab      = $( this );
			var $panel    = $tab.closest( '.ecs-cw-pickr-panel' );
			activeType    = $tab.data( 'type' );
			$panel.find( '.ecs-cw-tab' ).removeClass( 'is-active' );
			$tab.addClass( 'is-active' );
			renderSwatches( $panel.parent(), generatePalette( currentColor, activeType ) );
		} );

		// Swatch click
		$( document ).on( 'click', '.ecs-cw-swatch', function ( e ) {
			e.stopPropagation();
			var hex = $( this ).data( 'hex' );
			if ( hex ) { applyColor( hex ); }
		} );

		// Save palette
		$( document ).on( 'click', '.ecs-cw-save-btn', function ( e ) {
			e.stopPropagation();
			var $container = getActiveContainer();
			var name = $container.find( '.ecs-cw-palette-name' ).val().trim();
			if ( ! name ) {
				$container.find( '.ecs-cw-palette-name' ).focus();
				return;
			}
			savePalette( name, generatedColors );
		} );

		// Delete palette
		$( document ).on( 'click', '.ecs-cw-del-btn', function ( e ) {
			e.stopPropagation();
			var name = $( this ).data( 'paletteName' );
			if ( name ) { deletePalette( name ); }
		} );

		// Live sync — Pickr: user moves the color picker or types in the hex input
		$( document ).on( 'input', '.pcr-app .pcr-result', function () {
			var raw = $( this ).val().trim();
			syncFromPicker( raw.length === 6 ? '#' + raw : raw );
		} );

		// Live sync — Atomic Widgets: user changes hue/saturation or types hex
		$( document ).on(
			'input',
			'#eui-color-picker-popover input[type="text"]:not([aria-hidden="true"]):not(.MuiInputBase-inputAdornedEnd)',
			function () {
				var raw = $( this ).val().trim();
				syncFromPicker( raw.length === 6 ? '#' + raw : raw );
			}
		);

		// Prevent palette-name input events from bubbling into Pickr / React
		$( document ).on( 'keydown keyup input', '.ecs-cw-palette-name', function ( e ) {
			e.stopPropagation();
		} );
	}

	if ( window.elementor ) {
		init();
	} else {
		$( window ).on( 'elementor:init', init );
		var poll = setInterval( function () {
			if ( window.elementor ) { clearInterval( poll ); init(); }
		}, 200 );
	}

} )( jQuery );
