/**
 * ECS Style Templates — Editor JS
 *
 * Responsibilities:
 *  - Detect when a widget panel opens and find .ecs-st-panel
 *  - Load templates list for the widget type (AJAX)
 *  - Handle Save New / Overwrite / Apply / Link / Unlink / Delete
 *  - Extract style-tab settings from the Elementor container model
 *  - Apply preset settings via $e.run (Elementor command bus)
 */

( function ( $ ) {
	'use strict';

	var cfg  = window.ecsStyleTemplates || {};
	var i18n = cfg.i18n || {};

	// Control types that hold no style value (structural / meta controls).
	var SKIP_TYPES = [ 'section', 'tabs', 'tab', 'popover_toggle', 'raw_html', 'button', 'divider', 'heading' ];

	// Our own control keys — never included in saved style settings.
	var ECS_KEYS = [ 'ecs_style_template_mode', 'ecs_style_template_name', 'ecs_style_templates_ui' ];

	// ── Group-activator helper ────────────────────────────────────────────────

	/**
	 * Ensure group-activator controls (e.g. `typography_typography`) are set to
	 * `'custom'` whenever any sub-control of that group has a non-empty value.
	 *
	 * Without this, Elementor skips CSS generation for group sub-properties
	 * (font-family, font-size, etc.) because the activator is '' (= "use global
	 * / default"), even when concrete values are present in the model.
	 *
	 * Mutates `settings` in-place and returns it.
	 */
	function ensureGroupActivators( settings, controls ) {
		var groupHasValue = {};

		Object.keys( settings ).forEach( function ( k ) {
			var ctrl = controls[ k ];
			if ( ! ctrl || ! ctrl.groupType ) { return; }

			var activator = ( ctrl.groupPrefix || '' ) + ctrl.groupType;
			if ( k === activator ) { return; } // skip the activator itself

			var val     = settings[ k ];
			var isEmpty = ( val === '' || val === null || val === undefined ||
				( typeof val === 'object' && val !== null && 'size' in val &&
				  ( val.size === '' || val.size === null || val.size === undefined ) ) );

			if ( ! isEmpty ) {
				groupHasValue[ activator ] = true;
			} else if ( ! ( activator in groupHasValue ) ) {
				groupHasValue[ activator ] = false;
			}
		} );

		Object.keys( groupHasValue ).forEach( function ( activator ) {
			if ( groupHasValue[ activator ] ) {
				settings[ activator ] = 'custom';
			}
		} );

		return settings;
	}

	// ── Elementor model helpers ───────────────────────────────────────────────

	/**
	 * Get the current Elementor container (element being edited).
	 *
	 * In Elementor 3.x the container lives on editedElementView.container,
	 * not directly on page.options.container.
	 */
	function getContainer() {
		try {
			var page = elementor.getPanelView().getCurrentPageView();
			if ( ! page || ! page.options ) { return null; }

			// Elementor 3.x path.
			var ev = page.options.editedElementView;
			if ( ev && ev.container ) { return ev.container; }

			// Fallback for older / future versions.
			if ( page.options.container ) { return page.options.container; }

			return null;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Get the flat controls definition map for the currently-edited widget.
	 * In Elementor 3.x controls live on page.options.controls (462 entries for Heading),
	 * NOT on container.controls (which may be empty or absent).
	 */
	function getControls() {
		try {
			var page = elementor.getPanelView().getCurrentPageView();
			if ( page && page.options && page.options.controls ) {
				return page.options.controls;
			}
		} catch ( _e ) {}
		return {};
	}

	/**
	 * Collect all Style-tab control values from the container model,
	 * excluding structural controls and our own ECS meta keys.
	 *
	 * Also captures __globals__ entries for style keys so that Global Color /
	 * Global Font references are preserved exactly — mirroring Elementor's own
	 * Copy Style → Paste Style behaviour.
	 */
	function extractStyleSettings( container ) {
		var controls    = getControls();
		var settings    = {};
		// Elementor 3.x stores live globals in container.globals (separate Backbone
		// model). container.settings.get('__globals__') returns [] (PHP empty array).
		var rawGlobals0 = ( container.globals && typeof container.globals.toJSON === 'function' )
			? container.globals.toJSON()
			: ( container.settings.get( '__globals__' ) || {} );
		var allGlobals  = Array.isArray( rawGlobals0 ) ? {} : rawGlobals0;
		var styleGlobals = {};

		Object.entries( controls ).forEach( function ( entry ) {
			var key  = entry[ 0 ];
			var ctrl = entry[ 1 ];

			if ( ( ctrl.tab || '' ) !== 'style' ) { return; }
			if ( ECS_KEYS.indexOf( key ) !== -1 )  { return; }

			// Preserve global refs (e.g. typography_typography → Global Font) BEFORE
			// the SKIP_TYPES guard — popover_toggle carries the ref but has no CSS value.
			if ( Object.prototype.hasOwnProperty.call( allGlobals, key ) ) {
				styleGlobals[ key ] = allGlobals[ key ];
			}

			if ( SKIP_TYPES.indexOf( ctrl.type ) !== -1 ) { return; }

			var val = container.settings.get( key );
			if ( val !== undefined ) {
				// Deep-clone to avoid passing live Backbone object references.
				try {
					settings[ key ] = JSON.parse( JSON.stringify( val ) );
				} catch ( _e ) {
					settings[ key ] = val;
				}
			}
		} );

		// Ensure group activators are set correctly before saving to DB.
		// This guarantees that templates saved from now on already have
		// typography_typography = 'custom' (and equivalents) when needed.
		ensureGroupActivators( settings, controls );

		// Embed global references so Apply / Link can restore them correctly.
		if ( Object.keys( styleGlobals ).length ) {
			settings[ '__globals__' ] = styleGlobals;
		}

		return settings;
	}

	/**
	 * Apply a settings map to the current container.
	 *
	 * Primary path — uses Elementor's native `document/elements/paste-style`
	 * command (identical to the right-click → Paste Style flow).  We build a
	 * clipboard payload, inject it temporarily, fire the command, then restore
	 * the original clipboard.  This reuses Elementor's `isStyleTransferControl`
	 * filter and its globals-handling, making it robust across widget types.
	 *
	 * Fallback path — when `paste-style` is unavailable (older Elementor), we
	 * apply settings manually via `document/elements/settings` +
	 * `document/globals/settings`.
	 *
	 * ECS meta keys (`ecs_style_template_mode`, etc.) are applied separately
	 * because `paste-style` correctly filters them out as non-style controls.
	 */
	function applySettings( container, settingsMap ) {
		var controls        = getControls();
		var templateGlobals = settingsMap.__globals__ || {};

		// ── Separate ECS meta keys from real style settings ───────────────
		var metaSettings  = {};
		var styleSettings = {};
		Object.keys( settingsMap ).forEach( function ( k ) {
			if ( k === '__globals__' ) { return; }
			if ( ECS_KEYS.indexOf( k ) !== -1 ) {
				metaSettings[ k ] = settingsMap[ k ];
			} else {
				styleSettings[ k ] = settingsMap[ k ];
			}
		} );

		// Ensure group activators (e.g. typography_typography = 'custom') so
		// that Elementor generates CSS for all sub-properties.
		ensureGroupActivators( styleSettings, controls );

		// ── Primary: native paste-style via clipboard ──────────────────────
		var widgetType       = container.model && container.model.get( 'widgetType' );
		var originalClipboard = null;
		var pasteStyleOk     = false;

		try {
			originalClipboard = elementorCommon.storage.get( 'clipboard' );
		} catch ( _e ) {}

		var clipboardPayload = {
			type     : 'elementor',
			siteurl  : ( elementorCommon && elementorCommon.config && elementorCommon.config.urls && elementorCommon.config.urls.home ) || location.origin,
			elements : [ {
				elType     : 'widget',
				widgetType : widgetType,
				settings   : Object.assign( {}, styleSettings, { __globals__: templateGlobals } ),
				elements   : [],
			} ],
		};

		try {
			elementorCommon.storage.set( 'clipboard', clipboardPayload );
			// paste-style expects singular `container`, not an array.
			$e.run( 'document/elements/paste-style', { container: container } );
			pasteStyleOk = true;
		} catch ( pasteErr ) {
			// paste-style unavailable or threw — fall through to manual apply.
		} finally {
			// Always restore the original clipboard so we don't clobber the
			// user's own copied element.
			try {
				elementorCommon.storage.set( 'clipboard', originalClipboard );
			} catch ( _e ) {}
		}

		// ── Fallback: manual apply (Elementor < 3.x or paste-style failed) ─
		if ( ! pasteStyleOk ) {
			// Keys being written (already excludes ECS meta).
			var appliedKeys = Object.keys( styleSettings );

			// Read current globals; clear refs for applied keys so local values win.
			var rawGlobals = ( container.globals && typeof container.globals.toJSON === 'function' )
				? container.globals.toJSON()
				: ( container.settings.get( '__globals__' ) || {} );
			var newGlobals = Object.assign( {}, Array.isArray( rawGlobals ) ? {} : rawGlobals );

			appliedKeys.forEach( function ( k ) {
				delete newGlobals[ k ];
				// Also remove the group-linker global (e.g. typography_typography).
				var ctrl = controls[ k ];
				if ( ctrl && ctrl.groupType ) {
					delete newGlobals[ ( ctrl.groupPrefix || '' ) + ctrl.groupType ];
				}
			} );
			Object.assign( newGlobals, templateGlobals );

			$e.run( 'document/elements/settings', {
				containers : [ container ],
				settings   : styleSettings,
				options    : { render: true },
			} );

			try {
				$e.run( 'document/globals/settings', {
					containers : [ container ],
					settings   : newGlobals,
					options    : { render: true },
				} );
			} catch ( _e ) {
				if ( container.globals && typeof container.globals.clear === 'function' ) {
					container.globals.clear( { silent: true } );
					if ( Object.keys( newGlobals ).length ) {
						container.globals.set( newGlobals );
					}
				}
				container.settings.set( '__globals__', newGlobals );
				container.render();
			}
		}

		// ── ECS meta keys (mode / name) — always applied separately ──────────
		if ( Object.keys( metaSettings ).length ) {
			$e.run( 'document/elements/settings', {
				containers : [ container ],
				settings   : metaSettings,
				options    : { render: false },
			} );
		}
	}

	/**
	 * Force the Style tab controls to re-render so they display the freshly
	 * applied values.
	 *
	 * Elementor renders panel control views once when the widget editor opens;
	 * bulk model updates via $e.run do not automatically refresh their UI
	 * (color pickers, typography controls, etc. stay showing the old values).
	 *
	 * Solution: simulate a Content → Style tab round-trip. Elementor re-renders
	 * the active tab's control region when the tab is clicked, picking up the
	 * current model values.
	 */
	function refreshPanelStyleTab() {
		setTimeout( function () {
			try {
				var panel = document.getElementById( 'elementor-panel' );
				if ( ! panel ) { return; }

				function findStyleTab() {
					var tabs  = panel.querySelectorAll( '.elementor-panel-navigation-tab' );
					var found = null;
					tabs.forEach( function ( t ) {
						if ( t.textContent.trim().toLowerCase().includes( 'style' ) ) {
							found = t;
						}
					} );
					return found;
				}

				function findContentTab() {
					var tabs  = panel.querySelectorAll( '.elementor-panel-navigation-tab' );
					var found = null;
					tabs.forEach( function ( t ) {
						if ( t.textContent.trim().toLowerCase().includes( 'content' ) ) {
							found = t;
						}
					} );
					return found;
				}

				function scrollToDteSection() {
					var sectionControl = panel.querySelector( '.elementor-control-ecs_style_templates' );
					if ( ! sectionControl ) { return; }

					var ecsPanel   = panel.querySelector( '.ecs-st-panel' );
					var collapsed  = ! ecsPanel || ecsPanel.offsetHeight === 0;

					if ( collapsed ) {
						var heading = sectionControl.querySelector( 'h3' ) || sectionControl;
						heading.click();
						setTimeout( function () {
							sectionControl.scrollIntoView( { block: 'start', behavior: 'smooth' } );
						}, 150 );
					} else {
						sectionControl.scrollIntoView( { block: 'start', behavior: 'smooth' } );
					}
				}

				var content = findContentTab();
				if ( ! content || ! findStyleTab() ) { return; }

				// Content → Style round-trip re-renders Style controls with current model values.
				// Re-query after content click — Elementor may replace tab DOM nodes on render.
				content.click();
				setTimeout( function () {
					var style = findStyleTab();
					if ( style ) {
						style.click();
						// After Style tab renders, expand ECS section and scroll to it.
						setTimeout( scrollToDteSection, 200 );
					}
				}, 100 );
			} catch ( _e ) {}
		}, 50 );
	}

	// ── AJAX helper ───────────────────────────────────────────────────────────

	function ajax( action, data ) {
		return $.ajax( {
			url  : cfg.ajaxUrl,
			type : 'POST',
			data : $.extend( { action: action, nonce: cfg.nonce }, data ),
		} );
	}

	// ── Panel UI ──────────────────────────────────────────────────────────────

	/**
	 * Populate the <select> with the templates list for a widget type.
	 * Re-queries the panel from the live DOM to avoid stale references
	 * (Elementor may re-render parts of the panel between AJAX start and callback).
	 */
	function loadTemplates( panelEl, widgetType, selectValue ) {
		// Capture the prev value before the AJAX call (from the live element).
		var prevValue = selectValue !== undefined ? selectValue
			: ( panelEl.querySelector( '.ecs-st-select' ) || {} ).value || '';

		ajax( 'ecs_style_templates_list', { widget_type: widgetType } )
			.done( function ( resp ) {
				if ( ! resp.success ) { return; }

				// Re-query the panel in case Elementor replaced its DOM nodes.
				var livePanel = document.querySelector( '.ecs-st-panel[data-widget-type="' + widgetType + '"]' );
				if ( ! livePanel ) { return; }

				var select = livePanel.querySelector( '.ecs-st-select' );
				if ( ! select ) { return; }

				select.innerHTML = '<option value="">\u2014 Select template \u2014</option>';
				( resp.data || [] ).forEach( function ( t ) {
					var opt         = document.createElement( 'option' );
					opt.value       = t.name;
					opt.textContent = t.name;
					select.appendChild( opt );
				} );

				if ( prevValue ) { select.value = prevValue; }
			} );
	}

	/** Refresh the status line and link switch from the container's current settings. */
	function updateStatus( panelEl, container ) {
		var mode       = container.settings.get( 'ecs_style_template_mode' ) || 'none';
		var name       = container.settings.get( 'ecs_style_template_name' ) || '';
		var statusEl   = panelEl.querySelector( '.ecs-st-status' );
		var linkToggle = panelEl.querySelector( '.ecs-st-link-toggle' );

		if ( linkToggle ) {
			linkToggle.checked = ( mode === 'linked' );
		}

		if ( mode === 'linked' ) {
			statusEl.textContent = ( i18n.linkedTo || 'Linked to: ' ) + name;
			statusEl.className   = 'ecs-st-status ecs-st-is-linked';
			if ( name ) {
				var select = panelEl.querySelector( '.ecs-st-select' );
				if ( select ) { select.value = name; }
			}
		} else if ( mode === 'applied' ) {
			statusEl.textContent = i18n.applied || 'Template applied (not linked)';
			statusEl.className   = 'ecs-st-status ecs-st-is-applied';
		} else {
			statusEl.textContent = '';
			statusEl.className   = 'ecs-st-status';
		}
	}

	/** Wire event listeners and load initial state for a newly found panel. */
	function initPanel( panelEl ) {
		var widgetType = panelEl.dataset.widgetType;

		// Skip internal Elementor base widgets (common, common-optimized, global-widget, etc.).
		if ( ! widgetType || widgetType.startsWith( 'common' ) || widgetType === 'global-widget' ) {
			panelEl.setAttribute( 'data-ecs-init', 'skip' );
			return;
		}

		panelEl.setAttribute( 'data-ecs-init', '1' );

		var container = getContainer();
		var savedName = container ? ( container.settings.get( 'ecs_style_template_name' ) || '' ) : '';

		loadTemplates( panelEl, widgetType, savedName );

		if ( container ) {
			updateStatus( panelEl, container );
		}

		// Link switch — toggle triggers link/unlink.
		var linkToggle = panelEl.querySelector( '.ecs-st-link-toggle' );
		if ( linkToggle ) {
			linkToggle.addEventListener( 'change', function () {
				var c = getContainer();
				if ( ! c ) { return; }
				if ( linkToggle.checked ) {
					handleLink( panelEl, widgetType, c );
				} else {
					handleUnlink( panelEl, c );
				}
			} );
		}

		// Single delegated listener for all buttons.
		panelEl.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-action]' );
			if ( ! btn ) { return; }

			e.preventDefault();
			e.stopPropagation();

			var action = btn.dataset.action;
			var c      = getContainer();
			if ( ! c ) { return; }

			switch ( action ) {
				case 'save_new':   handleSaveNew(   panelEl, widgetType, c ); break;
				case 'overwrite':  handleOverwrite( panelEl, widgetType, c ); break;
				case 'apply':      handleApply(     panelEl, widgetType, c ); break;
				case 'delete_tpl': handleDelete(    panelEl, widgetType    ); break;
			}
		} );
	}

	// ── Action handlers ───────────────────────────────────────────────────────

	function handleSaveNew( panelEl, widgetType, container ) {
		var nameInput = panelEl.querySelector( '.ecs-st-name-input' );
		var name      = nameInput.value.trim();

		if ( ! name ) {
			alert( i18n.enterName || 'Please enter a template name.' );
			return;
		}

		var style = extractStyleSettings( container );
		if ( ! Object.keys( style ).length ) {
			alert( i18n.noStyle || 'No style controls found for this widget.' );
			return;
		}

		ajax( 'ecs_style_templates_save_new', {
			widget_type    : widgetType,
			template_name  : name,
			style_settings : JSON.stringify( style ),
		} ).done( function ( resp ) {
			if ( resp.success ) {
				nameInput.value = '';
				loadTemplates( panelEl, widgetType, name );
			} else if ( resp.data === 'template_exists' ) {
				alert( i18n.alreadyExists || 'A template with this name already exists.' );
			} else {
				alert( resp.data || 'Error saving template.' );
			}
		} );
	}

	function handleOverwrite( panelEl, widgetType, container ) {
		var name = panelEl.querySelector( '.ecs-st-select' ).value;
		if ( ! name ) {
			alert( i18n.selectTemplate || 'Please select a template.' );
			return;
		}

		if ( ! confirm( i18n.confirmOverwrite || 'Overwrite this template with current settings?' ) ) {
			return;
		}

		var style = extractStyleSettings( container );

		ajax( 'ecs_style_templates_overwrite', {
			widget_type    : widgetType,
			template_name  : name,
			style_settings : JSON.stringify( style ),
		} ).done( function ( resp ) {
			if ( ! resp.success ) {
				alert( resp.data || 'Error overwriting template.' );
				return;
			}
			// Invalidate CSS cache so all linked widgets re-fetch and reflect
			// the updated template immediately.
			if ( cfg.invalidateTemplate ) {
				cfg.invalidateTemplate( widgetType, name );
			}
		} );
	}

	function handleApply( panelEl, widgetType, container ) {
		var name = panelEl.querySelector( '.ecs-st-select' ).value;
		if ( ! name ) {
			alert( i18n.selectTemplate || 'Please select a template.' );
			return;
		}

		ajax( 'ecs_style_templates_get', {
			widget_type   : widgetType,
			template_name : name,
		} ).done( function ( resp ) {
			if ( ! resp.success ) {
				alert( resp.data || 'Error loading template.' );
				return;
			}

			var merged = Object.assign( {}, resp.data, {
				ecs_style_template_mode : 'applied',
				ecs_style_template_name : name,
			} );

			applySettings( container, merged );
			updateStatus( panelEl, container );
		} );
	}

	function handleLink( panelEl, widgetType, container ) {
		var name = panelEl.querySelector( '.ecs-st-select' ).value;
		if ( ! name ) {
			alert( i18n.selectTemplate || 'Please select a template.' );
			// Revert switch — link didn't happen.
			var tog = panelEl.querySelector( '.ecs-st-link-toggle' );
			if ( tog ) { tog.checked = false; }
			return;
		}

		// Only store the link metadata — do NOT apply template styles to the
		// widget's own model. The widget keeps its own styles intact so that
		// unlinking reverts it cleanly. Visual override is handled by CSS
		// injection (editor preview) and PHP output_preset_css (frontend).
		applySettings( container, {
			ecs_style_template_mode : 'linked',
			ecs_style_template_name : name,
		} );
		updateStatus( panelEl, container );

		// Trigger CSS injection for this widget immediately.
		if ( cfg.invalidateTemplate ) {
			cfg.invalidateTemplate( widgetType, name );
		}
	}

	function handleUnlink( panelEl, container ) {
		// Clear link metadata only — the widget's own styles were never
		// overwritten, so it naturally reverts to its original appearance.
		applySettings( container, {
			ecs_style_template_mode : 'none',
			ecs_style_template_name : '',
		} );
		updateStatus( panelEl, container );
		// Force CSS removal immediately — don't rely on command:after firing.
		if ( cfg.scheduleApply ) { cfg.scheduleApply(); }
	}

	function handleDelete( panelEl, widgetType ) {
		var name   = panelEl.querySelector( '.ecs-st-select' ).value;
		if ( ! name ) {
			alert( i18n.selectTemplate || 'Please select a template.' );
			return;
		}

		if ( ! confirm( i18n.confirmDelete || 'Delete this template?' ) ) {
			return;
		}

		ajax( 'ecs_style_templates_delete', {
			widget_type   : widgetType,
			template_name : name,
		} ).done( function ( resp ) {
			if ( resp.success ) {
				loadTemplates( panelEl, widgetType, '' );
			} else {
				alert( resp.data || 'Error deleting template.' );
			}
		} );
	}

	// ── Initialization ────────────────────────────────────────────────────────

	/**
	 * Find any un-initialized .ecs-st-panel in the panel DOM and init it.
	 * Called by MutationObserver and by the widget-panel-open hook.
	 */
	function checkForPanel() {
		var panelEl = document.querySelector( '.ecs-st-panel:not([data-ecs-init])' );
		if ( panelEl ) {
			initPanel( panelEl );
		}
	}

	/**
	 * Re-init after a widget panel opens (the panel DOM is replaced,
	 * so data-ecs-init from the previous widget is gone automatically).
	 * We add a small delay so Elementor finishes rendering the controls.
	 */
	function onWidgetPanelOpen() {
		setTimeout( checkForPanel, 150 );
	}

	function boot() {
		// MutationObserver catches the panel appearing in the DOM
		// (e.g., user switches to Style tab after panel already opened).
		var panelHost = document.getElementById( 'elementor-panel' );
		if ( panelHost ) {
			new MutationObserver( checkForPanel )
				.observe( panelHost, { childList: true, subtree: true } );
		}

		// Also run immediately in case panel is already open.
		checkForPanel();

		// Hook into the Elementor editor event for each widget open.
		if ( typeof elementor !== 'undefined' && elementor.hooks ) {
			elementor.hooks.addAction( 'panel/open_editor/widget', onWidgetPanelOpen );
		}
	}

	// Boot when Elementor editor is ready.
	$( window ).on( 'elementor:init', boot );

	// Fallback: if elementor:init already fired before our script loaded.
	if ( typeof elementor !== 'undefined' ) {
		boot();
	}

} )( jQuery );

/**
 * ECS Style Templates — Editor Preview CSS
 *
 * In the Elementor editor, widgets are rendered by JavaScript (Backbone),
 * not by PHP. So our PHP `output_preset_css()` hook never fires in the editor.
 *
 * This section runs in the parent editor frame, iterates every widget in the
 * preview iframe, and for linked widgets injects <style id="ecs-st-{id}">
 * into the iframe's <head>. These tags survive Backbone re-renders and use
 * !important to override Elementor's own per-widget elementor-style-{id} tags.
 *
 * CSS generation mirrors PHP output_preset_css() / apply_css_template().
 */
( function () {
	'use strict';

	var cfg = window.ecsStyleTemplates || {};

	// Template data cache: { 'widgetType::templateName': settingsObject|null }
	var tplCache     = {};
	var initialized  = false;
	var channelBound = false;
	var iframeDoc    = null;
	var refreshTimer = null;

	// ── CSS generation (mirrors PHP output_preset_css) ─────────────────────

	function resolveGlobalRef( ref ) {
		var m = String( ref ).match( /globals\/colors\?id=([a-zA-Z0-9_-]+)/ );
		return m ? 'var(--e-global-color-' + m[ 1 ] + ')' : null;
	}

	function applyCssTemplate( template, cssValue, rawValue ) {
		var css = template;

		if ( cssValue !== null && cssValue !== undefined && cssValue !== '' ) {
			css = css.replace( /\{\{VALUE\}\}/g, cssValue );
		} else if ( ! rawValue && rawValue !== 0 ) {
			return null;
		}

		if ( rawValue !== null && typeof rawValue === 'object' && ! Array.isArray( rawValue ) ) {
			var unit = rawValue.unit || '';

			if ( 'size' in rawValue ) {
				if ( rawValue.size === '' || rawValue.size === null || rawValue.size === undefined ) {
					return null;
				}
				css = css.replace( /\{\{SIZE\}\}/g, rawValue.size )
				         .replace( /\{\{UNIT\}\}/g, unit );
			}

			[ 'TOP', 'RIGHT', 'BOTTOM', 'LEFT' ].forEach( function ( side ) {
				var k = side.toLowerCase();
				if ( k in rawValue ) {
					if ( rawValue[ k ] === '' || rawValue[ k ] === null ) {
						return;
					}
					css = css.replace( new RegExp( '\\{\\{' + side + '\\}\\}', 'g' ), rawValue[ k ] );
				}
			} );

			if ( 'url' in rawValue ) {
				css = css.replace( /\{\{URL\}\}/g, rawValue.url || '' );
			}

			css = css.replace( /\{\{UNIT\}\}/g, unit );
		}

		if ( css.indexOf( '{{' ) > -1 ) { return null; }
		if ( /:\s*;/.test( css ) )       { return null; }
		return css;
	}

	function generateCss( widgetId, widgetType, preset ) {
		var controls = ( elementor.widgetsCache && elementor.widgetsCache[ widgetType ] )
			? ( elementor.widgetsCache[ widgetType ].controls || {} )
			: {};

		var wrapper = '.elementor-element.elementor-element-' + widgetId;
		var globals = ( preset && preset.__globals__ ) ? preset.__globals__ : {};
		var lines   = [];

		var keys = Object.keys( globals );
		Object.keys( preset || {} ).forEach( function ( k ) {
			if ( k !== '__globals__' && keys.indexOf( k ) === -1 ) {
				keys.push( k );
			}
		} );

		keys.forEach( function ( key ) {
			if ( key.indexOf( 'ecs_' ) === 0 ) { return; }
			// Skip responsive variants — they need @media wrappers (not implemented here).
			if ( /_(?:tablet|mobile|widescreen|laptop)$/.test( key ) ) { return; }

			var control = controls[ key ];
			if ( ! control ) { return; }

			// ── Typography global: expand to sub-control CSS vars ─────────────────
			if ( globals[ key ] ) {
				var typoMatch = String( globals[ key ] ).match( /globals\/typography\?id=([a-zA-Z0-9_-]+)/ );
				if ( typoMatch ) {
					var typoId      = typoMatch[ 1 ];
					var groupPrefix = key.replace( /_typography$/, '' );
					Object.keys( controls ).forEach( function ( subKey ) {
						if ( subKey === key ) { return; }
						if ( subKey.indexOf( groupPrefix + '_' ) !== 0 ) { return; }
						if ( /_(?:tablet|mobile|widescreen|laptop)$/.test( subKey ) ) { return; }
						var subCtrl = controls[ subKey ];
						if ( ! subCtrl || ! subCtrl.selectors ) { return; }
						var suffix  = subKey.slice( groupPrefix.length + 1 );
						var cssProp = suffix.replace( /_/g, '-' );
						var cssVar  = 'var(--e-global-typography-' + typoId + '-' + cssProp + ')';
						Object.keys( subCtrl.selectors ).forEach( function ( selectorTpl ) {
							var selector = selectorTpl.replace( /\{\{WRAPPER\}\}/g, wrapper );
							if ( selector.indexOf( '{{' ) > -1 ) { return; }
							lines.push( selector + ' { ' + cssProp + ': ' + cssVar + ' !important; }' );
						} );
					} );
					return;
				}
			}

			if ( ! control.selectors ) { return; }

			var cssValue, rawValue;
			if ( globals[ key ] ) {
				cssValue = resolveGlobalRef( globals[ key ] );
				rawValue = null;
			} else {
				rawValue = preset[ key ];
				cssValue = ( typeof rawValue === 'string' ) ? rawValue : null;
				// For color controls with no value, use 'inherit' to reset
				// any custom color the linked widget might have saved.
				if ( control.type === 'color' && ( cssValue === '' || cssValue === null ) ) {
					cssValue = 'inherit';
					rawValue = null;
				}
			}

			Object.keys( control.selectors ).forEach( function ( selectorTpl ) {
				var cssTpl = control.selectors[ selectorTpl ];
				var css    = applyCssTemplate( cssTpl, cssValue, rawValue );
				if ( ! css ) { return; }

				var selector = selectorTpl.replace( /\{\{WRAPPER\}\}/g, wrapper );
				if ( selector.indexOf( '{{' ) > -1 ) { return; }

				// Add !important to each declaration.
				css = css.replace( /(\w[^;{]*)(;)/g, '$1 !important$2' );
				if ( css.charAt( css.length - 1 ) !== ';' &&
				     css.charAt( css.length - 1 ) !== '}' ) {
					css += ' !important';
				}

				lines.push( selector + ' { ' + css + ' }' );
			} );
		} );

		return lines.join( ' ' );
	}

	// ── Style tag injection ────────────────────────────────────────────────

	function injectStyleTag( widgetId, css ) {
		if ( ! iframeDoc ) { return; }

		var id       = 'ecs-st-' + widgetId;
		var existing = iframeDoc.getElementById( id );
		if ( existing ) { existing.remove(); }
		if ( ! css ) { return; }

		var style = iframeDoc.createElement( 'style' );
		style.id  = id;
		style.textContent = css;
		iframeDoc.head.appendChild( style );
	}

	// ── Per-widget application ─────────────────────────────────────────────

	function applyToWidget( widgetId ) {
		var container = null;
		try { container = window.elementor.getContainer( widgetId ); } catch ( _e ) {}
		if ( ! container || ! container.settings ) { return; }

		var mode = container.settings.get( 'ecs_style_template_mode' );
		if ( mode !== 'linked' ) {
			injectStyleTag( widgetId, '' ); // remove any existing override
			return;
		}

		var tplName    = container.settings.get( 'ecs_style_template_name' );
		var widgetType = container.model && container.model.get( 'widgetType' );
		if ( ! tplName || ! widgetType ) { return; }

		var cacheKey = widgetType + '::' + tplName;

		if ( tplCache[ cacheKey ] ) {
			injectStyleTag( widgetId, generateCss( widgetId, widgetType, tplCache[ cacheKey ] ) );
			return;
		}
		if ( tplCache[ cacheKey ] === null ) { return; } // loading in progress

		tplCache[ cacheKey ] = null; // mark as loading

		jQuery.post( cfg.ajaxUrl, {
			action        : 'ecs_style_templates_get',
			nonce         : cfg.nonce,
			widget_type   : widgetType,
			template_name : tplName,
		}, function ( resp ) {
			if ( resp && resp.success && resp.data ) {
				tplCache[ cacheKey ] = resp.data;
				// Re-apply all widgets now that the cache is populated;
				// other widgets sharing this template were skipped during loading.
				applyAll();
			}
		} );
	}

	function applyAll() {
		if ( ! iframeDoc ) { return; }
		iframeDoc.querySelectorAll( '.elementor-widget[data-id]' ).forEach( function ( el ) {
			applyToWidget( el.dataset.id );
		} );
	}

	// ── Debounce ───────────────────────────────────────────────────────────

	function scheduleApply() {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( applyAll, 250 );
	}

	// ── Public API (used by the panel IIFE above) ──────────────────────────

	/**
	 * Invalidate the cache for a specific template and re-apply all linked widgets.
	 * Called by handleOverwrite() after a successful save so all linked widgets
	 * instantly reflect the updated template styles.
	 */
	function invalidateTemplate( widgetType, templateName ) {
		var cacheKey = widgetType + '::' + templateName;
		delete tplCache[ cacheKey ];
		scheduleApply();
	}

	cfg.invalidateTemplate = invalidateTemplate;
	cfg.scheduleApply      = scheduleApply;

	// ── Bootstrap ─────────────────────────────────────────────────────────

	function onPreviewLoaded() {
		var iframe = elementor.$preview && elementor.$preview[ 0 ];
		if ( ! iframe ) { return; }
		iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
		setTimeout( applyAll, 700 );

		if ( ! channelBound ) {
			channelBound = true;
			elementor.channels.deviceMode.on( 'change',        scheduleApply );
			elementor.channels.data.on(       'command:after', scheduleApply );
			elementor.channels.editor.on(     'change',        scheduleApply );
		}
	}

	function init() {
		if ( initialized || ! window.elementor ) { return; }
		initialized = true;

		elementor.on( 'preview:loaded', onPreviewLoaded );

		var iframe = elementor.$preview && elementor.$preview[ 0 ];
		if ( iframe && iframe.contentDocument && iframe.contentDocument.body &&
		     iframe.contentDocument.body.children.length ) {
			onPreviewLoaded();
		}
	}

	if ( window.jQuery ) { jQuery( window ).on( 'elementor:init', init ); }
	window.addEventListener( 'elementor:init', init );
	var poll = setInterval( function () {
		if ( window.elementor ) { init(); clearInterval( poll ); }
	}, 100 );

} )();
