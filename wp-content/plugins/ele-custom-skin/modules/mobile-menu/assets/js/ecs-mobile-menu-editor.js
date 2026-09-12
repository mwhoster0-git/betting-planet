/**
 * ECS Mobile Menu — Editor CSS-Variable Fix + widgetsCache patch
 *
 * (1) CSS-Variable Fix
 * Elementor's JS CSS generator does not handle multi-declaration CSS-variable
 * values from selectors_dictionary (e.g. "--a:1;--b:2;--c:3"), so the
 * --ecs-nav-main-display / --ecs-nav-main-dir / --ecs-nav-toggle-display
 * variables are never written into the editor's inline <style> tag.
 *
 * This script runs in the parent editor frame, reads the effective `layout`
 * value for the current device from Elementor's model, and sets the three
 * CSS custom properties directly on each nav-menu widget wrapper element
 * inside the preview iframe via element.style.setProperty().
 *
 * Inline CSS variables have the highest possible specificity, so they
 * correctly override the stylesheet fallbacks, giving the same visual result
 * in the editor that the PHP-generated post-{id}.css delivers on the frontend.
 *
 * (2) widgetsCache patch — Breakpoint=None condition fix
 * Elementor Pro adds condition: { 'dropdown!': 'none' } to full_width,
 * text_align, toggle, and toggle_align, causing them to disappear when
 * Breakpoint is set to "None". Since widgetsCache is built from serialized
 * PHP data at page load, PHP remove_control() changes are not reflected.
 * We patch the live cache object directly after elementor is ready.
 */

/* global elementor */
( function () {
	'use strict';

	var VAR_MAP = {
		horizontal: { display: 'flex',  dir: 'row',    toggle: 'none' },
		vertical:   { display: 'flex',  dir: 'column', toggle: 'none' },
		dropdown:   { display: 'none',  dir: 'row',    toggle: 'flex' },
	};

	var initialized  = false;
	var channelBound = false;
	var pollTimer    = null;
	var refreshTimer = null;
	var iframeDoc    = null;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	// ── widgetsCache patch ────────────────────────────────────────────────────

	/**
	 * Remove the 'dropdown!' condition from nav-menu controls that should
	 * remain visible even when Breakpoint = "None".
	 *
	 * elementor.widgetsCache is a plain JS object populated from serialized
	 * PHP data — mutating it directly is safe and takes effect immediately
	 * for all subsequent panel renders.
	 */
	function patchWidgetsCache() {
		var cache = elementor.widgetsCache && elementor.widgetsCache[ 'nav-menu' ];
		if ( ! cache || ! cache.controls ) {
			return;
		}

		// Remove the 'dropdown!' visibility condition from controls that should
		// remain visible even when Breakpoint = "None".
		[ 'full_width', 'text_align', 'toggle', 'toggle_align' ].forEach( function ( key ) {
			var ctrl = cache.controls[ key ];
			if ( ctrl && ctrl.condition ) {
				delete ctrl.condition[ 'dropdown!' ];
			}
		} );

		// Add prefix_class to toggle_align so Elementor's editor JS generates
		// the ecs-toggle-align-{value} class on the widget wrapper element.
		// PHP add_control() sets prefix_class but widgetsCache is built from
		// serialized data before our hook runs, so we must patch it here.
		var toggleAlign = cache.controls[ 'toggle_align' ];
		if ( toggleAlign ) {
			toggleAlign.prefix_class = 'ecs-toggle-align-';
		}
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	function init() {
		if ( initialized || ! window.elementor ) {
			return;
		}
		initialized = true;
		clearInterval( pollTimer );

		patchWidgetsCache();

		elementor.on( 'preview:loaded', onPreviewLoaded );

		var iframe = elementor.$preview && elementor.$preview[ 0 ];
		if ( iframe && iframe.contentDocument && iframe.contentDocument.body &&
		     iframe.contentDocument.body.children.length ) {
			onPreviewLoaded();
		}
	}

	if ( window.jQuery ) {
		jQuery( window ).on( 'elementor:init', init );
	}
	window.addEventListener( 'elementor:init', init );
	pollTimer = setInterval( function () {
		if ( window.elementor ) {
			init();
		}
	}, 100 );

	function onPreviewLoaded() {
		var iframe = elementor.$preview && elementor.$preview[ 0 ];
		if ( ! iframe ) {
			return;
		}
		iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
		setTimeout( applyAll, 600 );

		if ( ! channelBound ) {
			channelBound = true;
			elementor.channels.deviceMode.on( 'change',        scheduleApply );
			elementor.channels.data.on(       'command:after', scheduleApply );
			elementor.channels.editor.on(     'change',        scheduleApply );
		}
	}

	// ── Debounce ──────────────────────────────────────────────────────────────

	function scheduleApply() {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( applyAll, 200 );
	}

	// ── Apply CSS variables to all nav-menu widgets ────────────────────────────

	function applyAll() {
		if ( ! iframeDoc ) {
			return;
		}
		var device = elementor.channels.deviceMode.request( 'currentMode' ) || 'desktop';

		iframeDoc.querySelectorAll( '.elementor-widget-nav-menu' ).forEach( function ( el ) {
			applyToWidget( el, device );
		} );
	}

	function applyToWidget( el, device ) {
		var id = el.dataset.id;
		if ( ! id ) {
			return;
		}
		try {
			var container = window.elementor.getContainer( id );
			if ( ! container || ! container.settings ) {
				return;
			}
			var layout = getSettingForDevice( container.settings, 'layout', device );

			// Force Breakpoint: override layout to dropdown at the Elementor breakpoint.
			var forceBreakpoint = container.settings.get( 'ecs_force_breakpoint' ) === 'force-breakpoint';
			if ( forceBreakpoint ) {
				var bp = container.settings.get( 'dropdown' ) || 'none';
				var atBreakpoint = ( bp === 'mobile' && device === 'mobile' ) ||
				                   ( bp === 'tablet' && ( device === 'tablet' || device === 'mobile' ) );
				if ( atBreakpoint ) {
					layout = 'dropdown';
				}
			}

			var vars = VAR_MAP[ layout ] || VAR_MAP.horizontal;

			el.style.setProperty( '--ecs-nav-main-display',   vars.display );
			el.style.setProperty( '--ecs-nav-main-dir',       vars.dir     );
			el.style.setProperty( '--ecs-nav-toggle-display', vars.toggle  );
		} catch ( e ) {
			// widget may not be in the Elementor model yet (e.g. during initial render)
		}
	}

	/**
	 * Return the effective layout value for a device, cascading:
	 *   mobile → tablet → desktop
	 *
	 * An empty / falsy responsive value means "inherit from parent device".
	 */
	function getSettingForDevice( settings, key, device ) {
		if ( device === 'mobile' ) {
			var mobileVal = settings.get( key + '_mobile' );
			if ( mobileVal ) { return mobileVal; }
			// No explicit ECS mobile layout → respect Elementor's native Breakpoint.
			var nativeBp = settings.get( 'dropdown' ) || 'none';
			if ( nativeBp === 'mobile' || nativeBp === 'tablet' ) { return 'dropdown'; }
			return settings.get( key + '_tablet' ) || settings.get( key ) || 'horizontal';
		}
		if ( device === 'tablet' ) {
			var tabletVal = settings.get( key + '_tablet' );
			if ( tabletVal ) { return tabletVal; }
			// No explicit ECS tablet layout → respect Elementor's native Breakpoint.
			var nativeBp2 = settings.get( 'dropdown' ) || 'none';
			if ( nativeBp2 === 'tablet' ) { return 'dropdown'; }
			return settings.get( key ) || 'horizontal';
		}
		return settings.get( key ) || 'horizontal';
	}

} )();
