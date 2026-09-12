/**
 * ECS Editor Preview — Container Custom Layout
 *
 * Runs in the Elementor editor parent frame (not the preview iframe).
 *
 * Approach:
 *  1. Wait for Elementor editor to initialise.
 *  2. When the preview iframe loads, find every .e-con.e-ecs-custom container
 *     and ask the PHP AJAX handler to render the selected ECS Custom Layout
 *     template with placeholder HTML so we know WHERE children go.
 *  3. Parse the AJAX response, strip Elementor model attributes from template
 *     elements, then MOVE the live child DOM elements (real Elementor widgets)
 *     into their corresponding template slots.  The children stay editable.
 *  4. On every Elementor command (settings change, add/remove/move element),
 *     schedule a debounced re-injection (400 ms).
 *  5. If a container leaves ecs-custom mode, restore children to .e-con-inner
 *     and remove the injected template structure.
 *
 * State tracking:
 *  container.dataset.ecsState       — djb2 hash of (layoutId + children HTML)
 *  container.dataset.ecsChildOrder  — comma-separated ordered child IDs
 */

/* global ecsEditorPreview, elementor */
( function () {
	'use strict';

	var iframeDoc          = null;
	var refreshTimer       = null;
	var domObserver        = null;
	var suppressObserver   = false;
	var editingContainerId = null;

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	var initialized  = false;
	var channelBound = false;
	var pollTimer    = null;

	function initListeners() {
		if ( initialized || ! window.elementor ) {
			return;
		}
		initialized = true;
		clearInterval( pollTimer );

		elementor.on( 'preview:loaded', onPreviewLoaded );

		if ( elementor.hooks ) {
			elementor.hooks.addAction( 'panel/open_editor/container', function ( panel, model ) {
				editingContainerId = model && model.get( 'id' );
				setTimeout( syncPanelControls, 100 );
			} );
		}

		var iframe = elementor.$preview && elementor.$preview[ 0 ];
		if ( iframe && iframe.contentDocument && iframe.contentDocument.body &&
		     iframe.contentDocument.body.children.length ) {
			onPreviewLoaded();
		}
	}

	if ( window.jQuery ) {
		jQuery( window ).on( 'elementor:init', initListeners );
	}
	window.addEventListener( 'elementor:init', initListeners );
	pollTimer = setInterval( function () {
		if ( window.elementor ) {
			initListeners();
		}
	}, 100 );

	function onPreviewLoaded() {
		var iframe = elementor.$preview && elementor.$preview[ 0 ];
		if ( ! iframe ) {
			return;
		}
		iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
		if ( ! iframeDoc ) {
			return;
		}
		setTimeout( injectAll, 800 );
		if ( ! channelBound ) {
			channelBound = true;
			// command:after catches undo/redo/add/remove; editor change catches settings panel edits.
			elementor.channels.data.on( 'command:after', function ( component, command ) {
				scheduleRefresh();
				if ( command === 'document/elements/select' ) {
					setTimeout( syncPanelControls, 100 );
				}
			} );
			elementor.channels.editor.on( 'change', function () {
				scheduleRefresh();
				syncPanelControls();
			} );
			// Device mode switch: remove stale custom injections, then sync panel and preview.
			elementor.channels.deviceMode.on( 'change', function () {
				if ( iframeDoc ) {
					iframeDoc.querySelectorAll( '.ecs-preview-active' ).forEach( function ( el ) {
						if ( getResolvedType( el ) !== 'custom' ) {
							cleanupContainer( el );
						}
					} );
				}
				syncPanelControls();
				scheduleRefresh();
			} );
		}
		bindDomObserver();
	}

	// ── MutationObserver: detect new children added by Elementor ─────────────

	/**
	 * Watch for elements being inserted directly into .e-con-inner of any
	 * ecs-custom container.  Elementor renders new widgets asynchronously
	 * (Backbone view), so command:after fires before the DOM is updated.
	 * The observer catches the insertion and schedules a re-injection.
	 */
	function bindDomObserver() {
		if ( domObserver ) {
			domObserver.disconnect();
		}
		if ( ! iframeDoc ) {
			return;
		}
		domObserver = new iframeDoc.defaultView.MutationObserver( function ( mutations ) {
			if ( suppressObserver ) {
				return;
			}
			var needRefresh = false;
			mutations.forEach( function ( mutation ) {
				if ( mutation.type !== 'childList' || ! mutation.addedNodes.length ) {
					return;
				}
				// Only care about nodes added inside a .e-ecs-custom container.
				var target = mutation.target;
				var inCustom = target.closest && target.closest( '.e-ecs-custom' );
				if ( ! inCustom ) {
					return;
				}
				// Only care if an element with data-id was added (a real Elementor element).
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType === 1 && node.dataset && node.dataset.id ) {
						needRefresh = true;
					}
				} );
			} );
			if ( needRefresh ) {
				scheduleRefresh();
			}
		} );

		// Observe the entire preview document for subtree changes.
		domObserver.observe( iframeDoc.body, { childList: true, subtree: true } );
	}

	// ── Debounce ──────────────────────────────────────────────────────────────

	function scheduleRefresh() {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( function () {
			injectAll();
			// Notify ecs-slider.js (iframe) that settings may have changed so
			// it can rebuild sliders with fresh column/speed settings.
			if ( iframeDoc && iframeDoc.defaultView &&
			     typeof iframeDoc.defaultView.ecsSliderSettingsChanged === 'function' ) {
				iframeDoc.defaultView.ecsSliderSettingsChanged();
			}
		}, 400 );
	}

	// ── Injection orchestration ────────────────────────────────────────────────

	function injectAll() {
		if ( ! iframeDoc ) {
			return;
		}

		// Restore containers whose resolved type for the current device is no longer 'custom'.
		iframeDoc.querySelectorAll( '.ecs-preview-active' ).forEach( function ( el ) {
			if ( getResolvedType( el ) !== 'custom' ) {
				cleanupContainer( el );
			}
		} );

		// Inject into containers that carry a *-custom type class (any device breakpoint).
		// injectContainer will call getResolvedType() and skip if the current device
		// doesn't resolve to 'custom' (e.g. desktop=flex but tablet=custom, and we're on desktop).
		iframeDoc.querySelectorAll(
			'.e-con.e-ecs-custom, .e-con.e-ecs-tablet-custom, .e-con.e-ecs-mobile-custom'
		).forEach( injectContainer );
	}

	// ── Per-container injection ────────────────────────────────────────────────

	function injectContainer( container ) {
		var layoutId = getLayoutId( container );
		if ( ! layoutId ) {
			if ( container.classList.contains( 'ecs-preview-active' ) ) {
				cleanupContainer( container );
			}
			return;
		}

		// In editor, check active device mode. If the resolved type for the current
		// device is not 'custom', clean up the injection and show children directly.
		var resolvedType = getResolvedType( container );
		if ( resolvedType !== 'custom' ) {
			if ( container.classList.contains( 'ecs-preview-active' ) ) {
				cleanupContainer( container );
			}
			return;
		}

		var inner    = container.querySelector( ':scope > .e-con-inner' ) || container;
		var childEls = getChildEls( container, inner );
		var htmls    = childEls.map( function ( el ) { return el.outerHTML; } );

		// Skip if nothing changed since last injection.
		// Also include direct inner children IDs so newly added widgets
		// (not yet in ecsChildOrder) always trigger a re-injection.
		var innerIds  = Array.from( inner.querySelectorAll( ':scope > [data-id]' ) )
			.map( function ( el ) { return el.dataset.id; } ).join( ',' );
		var stateKey = layoutId + ':' + djb2( htmls.join( '' ) + '|' + innerIds );
		if ( container.dataset.ecsState === stateKey ) {
			return;
		}
		container.dataset.ecsState = stateKey;

		// Restore children to inner before re-injecting.
		if ( container.classList.contains( 'ecs-preview-active' ) ) {
			restoreToInner( container, inner );
		}

		// Re-read children now that they are back in inner.
		childEls = Array.from( inner.querySelectorAll( ':scope > [data-id]' ) );
		var childIds = childEls.map( function ( el ) { return el.dataset.id; } );
		htmls        = childEls.map( function ( el ) { return el.outerHTML; } );

		var formData = new FormData();
		formData.append( 'action', 'ecs_preview_layout' );
		formData.append( 'nonce', ecsEditorPreview.nonce );
		formData.append( 'layout_id', layoutId );
		htmls.forEach( function ( html ) {
			formData.append( 'children[]', html );
		} );

		fetch( ecsEditorPreview.ajaxUrl, { method: 'POST', body: formData } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				// Guard: another update may have arrived while this was in-flight.
				if ( container.dataset.ecsState !== stateKey ) {
					return;
				}
				if ( ! resp.success ) {
					return;
				}

				var doc     = container.ownerDocument;
				var tempDiv = doc.createElement( 'div' );
				tempDiv.innerHTML = resp.data.html;

				// Move <style> blocks returned by the AJAX renderer into the iframe
				// <head> so they apply correctly without rendering as visible text
				// inside the container.
				Array.from( tempDiv.querySelectorAll( 'style' ) ).forEach( function ( styleEl ) {
					var existing = doc.head.querySelector( '[data-ecs-layout-id="' + layoutId + '"]' );
					if ( existing ) {
						existing.textContent = styleEl.textContent;
					} else {
						styleEl.setAttribute( 'data-ecs-layout-id', layoutId );
						doc.head.appendChild( styleEl );
					}
				} );

				// Build a quick lookup of which IDs belong to live children.
				var childIdSet = Object.create( null );
				childIds.forEach( function ( id ) { childIdSet[ id ] = true; } );

				// Step 1 — find slot nodes by child data-id (before stripping).
				var slots = Object.create( null );
				childIds.forEach( function ( id ) {
					var slot = tempDiv.querySelector( '[data-id="' + id + '"]' );
					if ( slot ) {
						slots[ id ] = slot;
					}
				} );

				// Step 2 — strip Elementor model attributes from template's own
				//           elements so Elementor's click handler won't try to
				//           resolve them against the page model.
				Array.from( tempDiv.querySelectorAll( '[data-id]' ) ).forEach( function ( el ) {
					if ( ! childIdSet[ el.dataset.id ] ) {
						el.removeAttribute( 'data-id' );
						el.removeAttribute( 'data-element_type' );
						el.removeAttribute( 'data-widget_type' );
					}
				} );

				// Suppress the MutationObserver while we move DOM nodes so our
				// own insertions don't trigger a redundant scheduleRefresh().
				suppressObserver = true;

				// Step 3 — move each live child element into its template slot.
				childIds.forEach( function ( id ) {
					var slot   = slots[ id ];
					var liveEl = inner.querySelector( '[data-id="' + id + '"]' );
					if ( slot && liveEl ) {
						// replaceWith() moves liveEl from inner into tempDiv.
						slot.replaceWith( liveEl );
					}
				} );

				// Step 4 — mark every top-level template element for cleanup.
				//           With cycling there may be multiple template instances.
				Array.from( tempDiv.children ).forEach( function ( child ) {
					child.classList.add( 'ecs-injected-structure' );
				} );

				// Step 5 — insert template structure at the start of inner.
				//           Remaining direct children of inner (overflow children
				//           without a slot) stay at the end naturally.
				var refNode = inner.firstChild;
				while ( tempDiv.firstChild ) {
					inner.insertBefore( tempDiv.firstChild, refNode );
				}

				// Persist child order and mark the container as active.
				container.dataset.ecsChildOrder = childIds.join( ',' );
				container.classList.add( 'ecs-preview-active' );

				// Re-enable observer after microtasks flush so MutationObserver
				// callbacks for our own DOM changes are already dispatched.
				Promise.resolve().then( function () {
					suppressObserver = false;
				} );
			} )
			.catch( function () {
				// On network error, clear state so the next refresh retries.
				if ( container.dataset.ecsState === stateKey ) {
					delete container.dataset.ecsState;
				}
			} );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Return the live child elements of a container.
	 *
	 * If children have already been moved into a template structure
	 * (ecsChildOrder is set), locate them anywhere inside the container.
	 * Otherwise return the direct [data-id] children of inner.
	 */
	function getChildEls( container, inner ) {
		var order = container.dataset.ecsChildOrder;
		if ( order ) {
			return order.split( ',' ).filter( Boolean ).map( function ( id ) {
				return container.querySelector( '[data-id="' + id + '"]' );
			} ).filter( Boolean );
		}
		return Array.from( inner.querySelectorAll( ':scope > [data-id]' ) );
	}

	/**
	 * Move live children back to direct children of inner, then remove
	 * the injected template structure.
	 *
	 * Elements that are direct children of inner but NOT in ecsChildOrder are
	 * newly added widgets (Elementor appended them directly to inner while the
	 * template structure was in place).  They are re-appended at the END so
	 * the final child order is: [known children…, new children…].
	 */
	function restoreToInner( container, inner ) {
		var order = container.dataset.ecsChildOrder;
		if ( ! order ) {
			return;
		}

		var knownIds = {};
		order.split( ',' ).filter( Boolean ).forEach( function ( id ) {
			knownIds[ id ] = true;
		} );

		// Collect newly added elements (direct inner children not in ecsChildOrder)
		// before we start moving things around.
		var unknownEls = Array.from( inner.querySelectorAll( ':scope > [data-id]' ) )
			.filter( function ( el ) { return ! knownIds[ el.dataset.id ]; } );

		// Re-append known children to inner in the original order.
		order.split( ',' ).filter( Boolean ).forEach( function ( id ) {
			var el = container.querySelector( '[data-id="' + id + '"]' );
			if ( el ) {
				inner.appendChild( el );
			}
		} );

		// Remove all injected template structures (one per cycling pass).
		inner.querySelectorAll( '.ecs-injected-structure' ).forEach( function ( el ) {
			el.remove();
		} );

		// Append newly added elements at the end.
		unknownEls.forEach( function ( el ) {
			inner.appendChild( el );
		} );
	}

	function cleanupContainer( container ) {
		var inner = container.querySelector( ':scope > .e-con-inner' ) || container;
		restoreToInner( container, inner );
		container.classList.remove( 'ecs-preview-active' );
		delete container.dataset.ecsState;
		delete container.dataset.ecsChildOrder;
	}

	// ── Panel controls sync ───────────────────────────────────────────────────

	/**
	 * Set ecs_active_type on the currently edited container's Backbone model.
	 *
	 * PHP conditions for all slider/custom controls now check 'ecs_active_type'
	 * instead of OR-ing across all breakpoints.  When JS calls settings.set(),
	 * Elementor fires model 'change' and re-evaluates all panel conditions
	 * automatically — no DOM manipulation needed.
	 */
	function syncPanelControls() {
		var eContainer = null;
		try {
			if ( editingContainerId ) {
				eContainer = window.elementor.getContainer( editingContainerId );
			}
			if ( ! eContainer ) {
				var pv  = window.elementor.getPanelView();
				var cpv = pv && ( pv.getCurrentPageView ? pv.getCurrentPageView() : pv.currentPageView );
				var ev  = cpv && ( ( cpv.options && cpv.options.editedElementView ) || cpv.editedElementView );
				if ( ev ) {
					eContainer = ev.getContainer ? ev.getContainer() : ev.container;
					if ( ! eContainer && ev.model ) {
						eContainer = window.elementor.getContainer( ev.model.get( 'id' ) );
					}
				}
			}
		} catch ( e ) {}

		if ( ! eContainer || ! eContainer.settings ) { return; }

		var settings      = eContainer.settings;
		var desktop       = settings.get( 'ecs_container_type' ) || 'flex';
		var tablet        = settings.get( 'ecs_container_type_tablet' ) || desktop;
		var mobile        = settings.get( 'ecs_container_type_mobile' ) || tablet;
		var device        = window.elementor.channels.deviceMode.request( 'currentMode' ) || 'desktop';
		var effectiveType = device === 'mobile' ? mobile : ( device === 'tablet' ? tablet : desktop );

		if ( settings.get( 'ecs_active_type' ) !== effectiveType ) {
			settings.set( 'ecs_active_type', effectiveType );
		}
	}

	/**
	 * Return the resolved ecs_container_type for the current device mode,
	 * inheriting from larger breakpoints when the device-specific value is empty.
	 */
	function getResolvedType( container ) {
		var elementId = container.dataset.id;
		if ( ! elementId ) {
			return 'custom';
		}
		try {
			var device   = window.elementor.channels.deviceMode.request( 'currentMode' ) || 'desktop';
			var settings = window.elementor.getContainer( elementId ).settings;
			var suffixes = { desktop: '', tablet: '_tablet', mobile: '_mobile' };
			var order    = device === 'mobile'  ? [ 'mobile', 'tablet', 'desktop' ]
			             : device === 'tablet'  ? [ 'tablet', 'desktop' ]
			             : [ 'desktop' ];
			for ( var i = 0; i < order.length; i++ ) {
				var val = settings.get( 'ecs_container_type' + suffixes[ order[ i ] ] );
				if ( val ) {
					return val;
				}
			}
			return 'flex';
		} catch ( e ) {
			return 'custom';
		}
	}

	/**
	 * Return the ecs_custom_layout_id setting of a container element by
	 * querying Elementor's in-memory model via elementor.getContainer().
	 */
	function getLayoutId( container ) {
		var elementId = container.dataset.id;
		if ( ! elementId ) {
			return 0;
		}
		try {
			var eContainer = window.elementor.getContainer( elementId );
			var val = eContainer &&
			          eContainer.settings &&
			          eContainer.settings.get( 'ecs_custom_layout_id' );
			return val ? ( parseInt( val, 10 ) || 0 ) : 0;
		} catch ( e ) {
			return 0;
		}
	}

	/**
	 * djb2 hash — fast, good distribution for change-detection fingerprints.
	 */
	function djb2( str ) {
		var h = 5381;
		for ( var i = 0; i < str.length; i++ ) {
			h = ( ( h << 5 ) + h + str.charCodeAt( i ) ) | 0;
		}
		return ( h >>> 0 ).toString( 36 );
	}

} )();
