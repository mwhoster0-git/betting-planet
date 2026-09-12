/**
 * ECS Loop Custom Layout — Editor Preview
 *
 * Runs in the Elementor editor parent frame (not the preview iframe).
 *
 * When a Loop Grid widget has ecs_use_custom_layout=yes:
 *  1. Find it in the preview iframe.
 *  2. Extract the rendered .e-loop-item elements.
 *  3. POST items HTML + template_id to our AJAX handler.
 *  4. Keep the original loop content hidden in .ecs-loop-original.
 *  5. Show the server-rendered custom layout in .ecs-loop-injection.
 *  6. Re-attach EPro's "Edit Template" document handle button.
 *  7. Toggle between original / injection when in-place editing mode fires.
 *
 * State tracking:
 *  widget.dataset.ecsLoopState — djb2(templateId + items HTML)
 *  storedItemsMap              — WeakMap caching items per widget so re-injection
 *                                works even after innerHTML was replaced.
 */

/* global ecsLoopPreview */
( function () {
	'use strict';

	var iframeDoc        = null;
	var refreshTimer     = null;
	var childObserver    = null; // childList observer on preview body
	var classObserver    = null; // attributes/class observer on loop-grid widgets
	var suppressObserver = false;

	/** @type {WeakMap<Element, {templateId: number, items: string[]}>} */
	var storedItemsMap = new WeakMap();

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

		// Loop Grid renders its items via its own server-side AJAX — wait for it.
		setTimeout( injectAll, 1200 );

		if ( ! channelBound ) {
			channelBound = true;
			elementor.channels.data.on( 'command:after', scheduleRefresh );
			elementor.channels.editor.on( 'change', scheduleRefresh );
		}

		bindObservers();
	}

	// ── Observers ─────────────────────────────────────────────────────────────

	/**
	 * childList observer: detect new loop items being inserted (EPro AJAX re-render).
	 * class observer: detect elementor-in-place-template-editable toggling.
	 */
	function bindObservers() {
		var Win = iframeDoc.defaultView;

		if ( childObserver ) {
			childObserver.disconnect();
		}
		childObserver = new Win.MutationObserver( function ( mutations ) {
			if ( suppressObserver ) {
				return;
			}
			var needRefresh = false;
			mutations.forEach( function ( mutation ) {
				if ( mutation.type !== 'childList' || ! mutation.addedNodes.length ) {
					return;
				}
				var target    = mutation.target;
				var inLoopGrid = target.closest && target.closest( '.elementor-widget-loop-grid' );
				if ( ! inLoopGrid ) {
					return;
				}
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType === 1 ) {
						needRefresh = true;
					}
				} );
			} );
			if ( needRefresh ) {
				scheduleRefresh();
			}
		} );
		childObserver.observe( iframeDoc.body, { childList: true, subtree: true } );

		// Watch for elementor-in-place-template-editable class changes
		// only on .elementor-widget-loop-grid elements.
		if ( classObserver ) {
			classObserver.disconnect();
		}
		classObserver = new Win.MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				if ( mutation.type !== 'attributes' || mutation.attributeName !== 'class' ) {
					return;
				}
				var el = mutation.target;
				if ( el.classList && el.classList.contains( 'elementor-widget-loop-grid' ) ) {
					handleInPlaceEditToggle( el );
				}
			} );
		} );
		// Observe entire body but attribute changes only — attributeFilter limits cost.
		classObserver.observe( iframeDoc.body, {
			subtree:         true,
			attributes:      true,
			attributeFilter: [ 'class' ],
		} );
	}

	// ── In-place editing toggle ───────────────────────────────────────────────

	/**
	 * When EPro's "Edit Template" is clicked, it adds elementor-in-place-template-editable
	 * to the widget element. We switch to showing the original loop output so the
	 * in-place editing mode works normally. When the class is removed (edit done),
	 * we restore the custom layout injection.
	 */
	function handleInPlaceEditToggle( widget ) {
		if ( ! widget.classList.contains( 'ecs-loop-custom-preview-active' ) ) {
			return;
		}

		var widgetContainer = widget.querySelector( ':scope > .elementor-widget-container' );
		if ( ! widgetContainer ) {
			return;
		}
		var origWrapper = widgetContainer.querySelector( ':scope > .ecs-loop-original' );
		var injWrapper  = widgetContainer.querySelector( ':scope > .ecs-loop-injection' );
		if ( ! origWrapper || ! injWrapper ) {
			return;
		}

		if ( widget.classList.contains( 'elementor-in-place-template-editable' ) ) {
			// Entering in-place edit: show original loop items, hide our injection.
			origWrapper.style.display = '';
			injWrapper.style.display  = 'none';
		} else {
			// Exiting in-place edit: invalidate state and re-inject.
			origWrapper.style.display = 'none';
			injWrapper.style.display  = '';
			// Invalidate so re-injection fetches fresh items (template may have changed).
			delete widget.dataset.ecsLoopState;
			storedItemsMap.delete( widget );
			scheduleRefresh();
		}
	}

	// ── Debounce ──────────────────────────────────────────────────────────────

	function scheduleRefresh() {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( injectAll, 400 );
	}

	// ── Injection orchestration ────────────────────────────────────────────────

	function injectAll() {
		if ( ! iframeDoc ) {
			return;
		}

		// Clean up widgets that no longer have our setting enabled.
		iframeDoc.querySelectorAll( '.ecs-loop-custom-preview-active' ).forEach( function ( widget ) {
			if ( ! getTemplateId( widget ) ) {
				cleanupWidget( widget );
			}
		} );

		iframeDoc.querySelectorAll( '.elementor-widget-loop-grid' ).forEach( function ( widget ) {
			var templateId = getTemplateId( widget );
			if ( templateId ) {
				injectWidget( widget, templateId );
			} else if ( widget.classList.contains( 'ecs-loop-custom-preview-active' ) ) {
				cleanupWidget( widget );
			}
		} );
	}

	// ── Per-widget injection ───────────────────────────────────────────────────

	function injectWidget( widget, templateId ) {
		// Skip if currently in in-place editing mode.
		if ( widget.classList.contains( 'elementor-in-place-template-editable' ) ) {
			return;
		}

		// Prefer live .e-loop-item nodes rendered by EPro directly in the widget
		// (i.e. not inside our own wrappers, which would cause infinite re-injection).
		var liveItems = Array.from( widget.querySelectorAll( '.e-loop-item' ) )
			.filter( function ( el ) {
				return ! el.closest( '.ecs-loop-injection' ) &&
				       ! el.closest( '.ecs-loop-original' );
			} );

		var items, itemsChanged;

		if ( liveItems.length > 0 ) {
			// Strip any .elementor-document-handle buttons from item HTML so we
			// don't bake stale (event-listener-less) handle elements into the
			// PHP-rendered template output.
			items = liveItems.map( function ( el ) {
				var clone = el.cloneNode( true );
				clone.querySelectorAll( '.elementor-document-handle' ).forEach( function ( h ) {
					h.remove();
				} );
				return clone.outerHTML;
			} );
			itemsChanged = true;
			storedItemsMap.set( widget, { templateId: templateId, items: items } );
		} else {
			var stored = storedItemsMap.get( widget );
			if ( ! stored ) {
				return; // Widget not yet rendered.
			}
			items        = stored.items;
			itemsChanged = ( stored.templateId !== templateId );
			if ( itemsChanged ) {
				storedItemsMap.set( widget, { templateId: templateId, items: items } );
			}
		}

		var stateKey = templateId + ':' + djb2( items.join( '' ) );
		if ( ! itemsChanged && widget.dataset.ecsLoopState === stateKey ) {
			return;
		}
		widget.dataset.ecsLoopState = stateKey;

		var formData = new FormData();
		formData.append( 'action', 'ecs_loop_custom_layout_preview' );
		formData.append( 'nonce',       ecsLoopPreview.nonce );
		formData.append( 'template_id', templateId );
		items.forEach( function ( html ) {
			formData.append( 'items[]', html );
		} );

		fetch( ecsLoopPreview.ajaxUrl, { method: 'POST', body: formData } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				if ( widget.dataset.ecsLoopState !== stateKey ) {
					return;
				}
				if ( ! resp.success ) {
					return;
				}

				var widgetContainer = widget.querySelector( ':scope > .elementor-widget-container' );
				if ( ! widgetContainer ) {
					return;
				}

				suppressObserver = true;

				// ── Dual-content structure ─────────────────────────────────────
				//
				// .elementor-widget-container
				//   └─ .ecs-loop-original  (display:none — preserves DOM for EPro)
				//   └─ .ecs-loop-injection (visible — our custom layout)

				var origWrapper = widgetContainer.querySelector( ':scope > .ecs-loop-original' );
				var injWrapper  = widgetContainer.querySelector( ':scope > .ecs-loop-injection' );
				var doc         = widgetContainer.ownerDocument;

				if ( ! origWrapper ) {
					// First injection: wrap all existing children into .ecs-loop-original.
					origWrapper = doc.createElement( 'div' );
					origWrapper.className = 'ecs-loop-original';
					while ( widgetContainer.firstChild ) {
						origWrapper.appendChild( widgetContainer.firstChild );
					}
					widgetContainer.appendChild( origWrapper );
				}
				origWrapper.style.display = 'none';

				if ( ! injWrapper ) {
					injWrapper = doc.createElement( 'div' );
					injWrapper.className = 'ecs-loop-injection';
					widgetContainer.appendChild( injWrapper );
				}
				injWrapper.innerHTML  = resp.data.html;
				injWrapper.style.display = '';

				widget.classList.add( 'ecs-loop-custom-preview-active' );

				// Re-attach EPro's "Edit Template" document handle button.
				// attachEditDocumentHandle() looks for the first loop item inside
				// this.$element — which now finds items nested in our injection.
				reattachDocumentHandle( widget );

				Promise.resolve().then( function () {
					suppressObserver = false;
				} );
			} )
			.catch( function () {
				if ( widget.dataset.ecsLoopState === stateKey ) {
					delete widget.dataset.ecsLoopState;
				}
			} );
	}

	// ── Cleanup ───────────────────────────────────────────────────────────────

	function cleanupWidget( widget ) {
		var widgetContainer = widget.querySelector( ':scope > .elementor-widget-container' );
		if ( widgetContainer ) {
			var origWrapper = widgetContainer.querySelector( ':scope > .ecs-loop-original' );
			var injWrapper  = widgetContainer.querySelector( ':scope > .ecs-loop-injection' );

			if ( origWrapper ) {
				// Move original children back and remove the wrapper.
				while ( origWrapper.firstChild ) {
					widgetContainer.insertBefore( origWrapper.firstChild, origWrapper );
				}
				origWrapper.remove();
			}
			if ( injWrapper ) {
				injWrapper.remove();
			}
		}

		widget.classList.remove( 'ecs-loop-custom-preview-active' );
		delete widget.dataset.ecsLoopState;
		storedItemsMap.delete( widget );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Re-attach EPro's "Edit Template" document handle button after injection.
	 *
	 * Strategy:
	 *  1. Remove any existing (stale) handles — prevents hasHandle() guard from
	 *     blocking re-attachment.
	 *  2. Call iframeFrontend.elementsHandler.runReadyTrigger(widget) — this
	 *     re-fires 'frontend/element_ready/loop-grid.*' which creates a new
	 *     handler instance and calls attachEditDocumentHandle().  The handle
	 *     lands on the first [data-elementor-type="loop-item"] found in the
	 *     subtree, which is inside hidden .ecs-loop-original.
	 *  3. Move that handle (with its live click listeners intact) to the first
	 *     loop item inside visible .ecs-loop-injection.
	 */
	function reattachDocumentHandle( widget ) {
		try {
			var iframeFrontend = iframeDoc.defaultView.elementorFrontend;
			if ( ! iframeFrontend ) {
				return;
			}

			// Step 1 — clear stale handles so hasHandle() guard doesn't block re-attachment.
			widget.querySelectorAll( '.elementor-document-handle' ).forEach( function ( h ) {
				h.remove();
			} );

			// Step 2 — re-trigger element_ready. EPro's attachEditDocumentHandle()
			// runs but attaches the handle asynchronously (~10 ms later), so we
			// must defer step 3.
			iframeFrontend.elementsHandler.runReadyTrigger( widget );

			// Step 3 (deferred) — move handle + data-editable-elementor-document
			// attribute into the visible injection area.
			//
			// EPro sets data-editable-elementor-document on the element it prepends
			// the handle to.  The preview.css rule
			//   *[data-editable-elementor-document] { position: relative }
			//   *[data-editable-elementor-document] .elementor-document-handle { position: absolute ... }
			// relies on that attribute being on the SAME element as the handle's
			// parent.  We must move it together with the handle node.
			setTimeout( function () {
				try {
					var handle     = widget.querySelector( '.elementor-document-handle' );
					var targetItem = widget.querySelector( '.ecs-loop-injection [data-elementor-type="loop-item"]' );
					if ( handle && targetItem && ! targetItem.contains( handle ) ) {
						// Transfer the attribute from the source to the target.
						var sourceItem = handle.parentElement;
						if ( sourceItem && sourceItem.dataset.editableElementorDocument ) {
							targetItem.dataset.editableElementorDocument = sourceItem.dataset.editableElementorDocument;
							delete sourceItem.dataset.editableElementorDocument;
						}
						targetItem.prepend( handle );
					}
				} catch ( e ) { /* non-critical */ }
			}, 100 );

		} catch ( e ) {
			// Non-critical — Edit Template button may be absent.
		}
	}

	/**
	 * Return the selected template ID for a loop-grid widget, or 0 if not enabled.
	 */
	function getTemplateId( widget ) {
		var elementId = widget.dataset.id;
		if ( ! elementId ) {
			return 0;
		}
		try {
			var container = window.elementor.getContainer( elementId );
			if ( ! container || ! container.settings ) {
				return 0;
			}
			if ( container.settings.get( 'ecs_use_custom_layout' ) !== 'yes' ) {
				return 0;
			}
			var val = container.settings.get( 'ecs_loop_layout_id' );
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
