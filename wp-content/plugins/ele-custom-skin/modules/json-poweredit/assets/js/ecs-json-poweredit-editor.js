/**
 * ECS JSON PowerEdit — Editor Script
 */
( function ( $ ) {
	'use strict';

	var ACCESSIBILITY_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 122.88 122.88" width="13" height="13" fill="currentColor" style="vertical-align:middle;margin-right:4px"><path d="M61.44,0A61.46,61.46,0,1,1,18,18,61.21,61.21,0,0,1,61.44,0Zm-.39,74.18L52.1,98.91a4.94,4.94,0,0,1-2.58,2.83A5,5,0,0,1,42.7,95.5l6.24-17.28a26.3,26.3,0,0,0,1.17-4,40.64,40.64,0,0,0,.54-4.18c.24-2.53.41-5.27.54-7.9s.22-5.18.29-7.29c.09-2.63-.62-2.8-2.73-3.3l-.44-.1-18-3.39A5,5,0,0,1,27.08,46a5,5,0,0,1,5.05-7.74l19.34,3.63c.77.07,1.52.16,2.31.25a57.64,57.64,0,0,0,7.18.53A81.13,81.13,0,0,0,69.9,42c.9-.1,1.75-.21,2.6-.29l18.25-3.42A5,5,0,0,1,94.5,39a5,5,0,0,1,1.3,7,5,5,0,0,1-3.21,2.09L75.15,51.37c-.58.13-1.1.22-1.56.29-1.82.31-2.72.47-2.61,3.06.08,1.89.31,4.15.61,6.51.35,2.77.81,5.71,1.29,8.4.31,1.77.6,3.19,1,4.55s.79,2.75,1.39,4.42l6.11,16.9a5,5,0,0,1-6.82,6.24,4.94,4.94,0,0,1-2.58-2.83L63,74.23,62,72.4l-1,1.78Zm.39-53.52a8.83,8.83,0,1,1-6.24,2.59,8.79,8.79,0,0,1,6.24-2.59Zm36.35,4.43a51.42,51.42,0,1,0,15,36.35,51.27,51.27,0,0,0-15-36.35Z"/></svg>';

	var MODAL_ID      = 'ecs-jpe-modal';
	var BTN_CLASS     = 'ecs-jpe-btn';
	var BTN_DONE_ATTR = 'data-ecs-jpe';

	// Sheet row drag-and-drop + context menu state
	var dragSrcRow    = null;
	var menuTargetRow = null;

	// ── Helpers ───────────────────────────────────────────────────────────────

	function getActiveContainer() {
		try {
			var c = window.elementor.getPanelView().content.currentView.options.container;
			if ( c ) { return c; }
		} catch ( _e ) {}
		try {
			var ev = window.elementor.getPanelView().content.currentView.options.editedElementView;
			if ( ev && ev.container ) { return ev.container; }
		} catch ( _e ) {}
		try {
			var model = window.elementor.getPanelView().content.currentView.model;
			var id    = model && model.get && model.get( 'id' );
			if ( id ) { return window.elementor.getContainer( id ); }
		} catch ( _e ) {}
		return null;
	}

	function validate( parsed ) {
		if ( ! Array.isArray( parsed ) ) {
			return 'Root must be a JSON array ( [ … ] ).';
		}
		for ( var i = 0; i < parsed.length; i++ ) {
			var item = parsed[ i ];
			if ( item === null || typeof item !== 'object' || Array.isArray( item ) ) {
				return 'Each element must be an object ( { … } ). Problem at index ' + i + '.';
			}
		}
		return null;
	}

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// ── TSV utilities ─────────────────────────────────────────────────────────

	function detectDelimiter( text ) {
		var firstLine = text.split( '\n' )[ 0 ] || '';
		var tabs      = ( firstLine.match( /\t/g ) || [] ).length;
		var commas    = ( firstLine.match( /,/g )  || [] ).length;
		return tabs >= commas ? '\t' : ',';
	}

	function parseDsvLine( line, sep ) {
		var cells = [];
		var i = 0;
		while ( i <= line.length ) {
			if ( i === line.length ) { cells.push( '' ); break; }
			if ( line[ i ] === '"' ) {
				var cell = '';
				i++;
				while ( i < line.length ) {
					if ( line[ i ] === '"' && line[ i + 1 ] === '"' ) { cell += '"'; i += 2; }
					else if ( line[ i ] === '"' ) { i++; break; }
					else { cell += line[ i++ ]; }
				}
				cells.push( cell );
				if ( line[ i ] === sep ) { i++; } else { break; }
			} else {
				var end = line.indexOf( sep, i );
				if ( end === -1 ) { cells.push( line.slice( i ) ); break; }
				cells.push( line.slice( i, end ) );
				i = end + 1;
			}
		}
		return cells;
	}

	// Parse DSV text → array of row objects (plain strings, no schema conversion).
	function parseDsvToRows( dsv ) {
		var sep   = detectDelimiter( dsv );
		var lines = dsv.split( '\n' );
		var rows  = lines.map( function ( l ) { return parseDsvLine( l, sep ); } );
		if ( rows.length < 2 ) { return { headers: rows[ 0 ] || [], data: [] }; }
		var headers = rows[ 0 ];
		var data = rows.slice( 1 ).filter( function ( cells ) {
			return cells.some( function ( c ) { return c.trim() !== ''; } );
		} ).map( function ( cells ) {
			var obj = {};
			headers.forEach( function ( h, idx ) {
				if ( h ) { obj[ h ] = cells[ idx ] !== undefined ? cells[ idx ] : ''; }
			} );
			return obj;
		} );
		return { headers: headers, data: data };
	}

	// ── Schema-aware conversion ───────────────────────────────────────────────

	function buildFieldSchema( headers, schemaRows ) {
		var schema = {};
		if ( ! schemaRows || ! schemaRows.length ) { return schema; }
		headers.forEach( function ( h ) {
			for ( var i = 0; i < schemaRows.length; i++ ) {
				var v = schemaRows[ i ][ h ];
				if ( v !== undefined && v !== null && typeof v === 'object' && ! Array.isArray( v ) ) {
					schema[ h ] = v;
					break;
				}
			}
		} );
		return schema;
	}

	// Convert raw string rows → Elementor-ready objects:
	//  - JSON-looking strings → JSON.parse
	//  - Plain string in a URL-type field  → {url: value, ...rest of template}
	//  - Plain string in an icon-type field → {value: value, library: tmpl.library}
	function applySchemaToRows( rows, schemaRows ) {
		if ( ! rows.length ) { return rows; }
		var headers = Object.keys( rows[ 0 ] );
		var schema  = buildFieldSchema( headers, schemaRows );

		return rows.map( function ( row ) {
			var obj = {};
			headers.forEach( function ( h ) {
				var v = row[ h ];

				if ( v && typeof v === 'string' && ( v[ 0 ] === '{' || v[ 0 ] === '[' ) ) {
					try { obj[ h ] = JSON.parse( v ); return; } catch ( _ ) {}
				}

				var tmpl = schema[ h ];
				if ( tmpl ) {
					var merged = {};
					Object.keys( tmpl ).forEach( function ( k ) { merged[ k ] = tmpl[ k ]; } );
					if ( 'url' in tmpl ) {
						merged.url = v;
						obj[ h ] = merged;
						return;
					}
					if ( 'value' in tmpl && 'library' in tmpl ) {
						merged.value = v;
						obj[ h ] = merged;
						return;
					}
				}

				obj[ h ] = v;
			} );
			return obj;
		} );
	}

	// ── Sheet table helpers ───────────────────────────────────────────────────

	function makeCell( text, isHeader ) {
		var cell = document.createElement( isHeader ? 'th' : 'td' );
		var div  = document.createElement( 'div' );
		div.className       = 'ecs-jpe-cell';
		div.contentEditable = 'true';
		div.spellcheck      = false;
		div.textContent     = text;
		cell.appendChild( div );
		return cell;
	}

	function makeRowNum( label ) {
		var isHeader = ( label === '#' );
		var cell = document.createElement( isHeader ? 'th' : 'td' );
		cell.className = 'ecs-jpe-sheet-rn';
		if ( isHeader ) {
			cell.textContent = '#';
		} else {
			var btn = document.createElement( 'button' );
			btn.type      = 'button';
			btn.className = 'ecs-jpe-rn-btn';
			btn.setAttribute( 'data-n', String( label ) );
			btn.textContent = '⋮';
			btn.title     = 'Row options';
			cell.appendChild( btn );
		}
		return cell;
	}

	function updateRowNums( tableEl ) {
		tableEl.querySelectorAll( 'tbody tr' ).forEach( function ( tr, i ) {
			var btn = tr.querySelector( '.ecs-jpe-rn-btn' );
			if ( btn ) {
				btn.setAttribute( 'data-n', String( i + 1 ) );
				btn.title = 'Row ' + ( i + 1 ) + ' options';
			}
		} );
	}

	// ── Row operations ────────────────────────────────────────────────────────

	function makeEmptyRowEl( refRow ) {
		var colCount = refRow.querySelectorAll( 'td:not(.ecs-jpe-sheet-rn)' ).length;
		var tr = document.createElement( 'tr' );
		tr.appendChild( makeRowNum( 1 ) );
		for ( var i = 0; i < colCount; i++ ) { tr.appendChild( makeCell( '', false ) ); }
		return tr;
	}

	function duplicateRow( tr ) {
		var newTr = tr.cloneNode( true );
		tr.after( newTr );
		bindRowDrag( newTr );
		bindRowMenuBtn( newTr );
		updateRowNums( tr.closest( 'table' ) );
	}

	function insertRowAbove( tr ) {
		var newTr = makeEmptyRowEl( tr );
		tr.before( newTr );
		bindRowDrag( newTr );
		bindRowMenuBtn( newTr );
		updateRowNums( tr.closest( 'table' ) );
	}

	function insertRowBelow( tr ) {
		var newTr = makeEmptyRowEl( tr );
		tr.after( newTr );
		bindRowDrag( newTr );
		bindRowMenuBtn( newTr );
		updateRowNums( tr.closest( 'table' ) );
	}

	function deleteRow( tr ) {
		var tbody = tr.parentNode;
		if ( tbody.children.length <= 1 ) {
			tr.querySelectorAll( '.ecs-jpe-cell' ).forEach( function ( c ) { c.textContent = ''; } );
			return;
		}
		tr.remove();
		updateRowNums( tbody.closest( 'table' ) );
	}

	// ── Row context menu ─────────────────────────────────────────────────────

	function getOrCreateRowMenu() {
		var menu = document.getElementById( 'ecs-jpe-row-menu' );
		if ( menu ) { return menu; }

		menu = document.createElement( 'div' );
		menu.id = 'ecs-jpe-row-menu';
		menu.className = 'ecs-jpe-row-menu';
		menu.innerHTML = [
			'<button data-rma="duplicate">Duplicate Row</button>',
			'<button data-rma="insert-above">Insert New Above</button>',
			'<button data-rma="insert-below">Insert New Below</button>',
			'<hr class="ecs-jpe-row-menu-sep">',
			'<button data-rma="delete" class="ecs-jpe-row-menu-delete">Delete Row</button>',
		].join( '' );
		document.body.appendChild( menu );

		menu.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-rma]' );
			if ( ! btn || ! menuTargetRow ) { closeRowMenu(); return; }
			var action = btn.getAttribute( 'data-rma' );
			var row = menuTargetRow;
			closeRowMenu();
			if ( action === 'duplicate' )    { duplicateRow( row ); }
			if ( action === 'insert-above' ) { insertRowAbove( row ); }
			if ( action === 'insert-below' ) { insertRowBelow( row ); }
			if ( action === 'delete' )       { deleteRow( row ); }
		} );

		document.addEventListener( 'mousedown', function ( e ) {
			if ( menu.classList.contains( 'ecs-jpe-row-menu--open' ) &&
			     ! menu.contains( e.target ) &&
			     ! e.target.classList.contains( 'ecs-jpe-rn-btn' ) ) {
				closeRowMenu();
			}
		} );

		return menu;
	}

	function openRowMenu( tr, anchorEl ) {
		menuTargetRow = tr;
		var menu = getOrCreateRowMenu();
		menu.style.visibility = 'hidden';
		menu.classList.add( 'ecs-jpe-row-menu--open' );
		var rect    = anchorEl.getBoundingClientRect();
		var menuH   = menu.offsetHeight;
		var spaceB  = window.innerHeight - rect.bottom;
		menu.style.top  = ( spaceB < menuH + 4 && rect.top > menuH + 4 )
			? ( rect.top - menuH - 4 ) + 'px'
			: ( rect.bottom + 4 ) + 'px';
		menu.style.left = rect.left + 'px';
		menu.style.visibility = '';
	}

	function closeRowMenu() {
		var menu = document.getElementById( 'ecs-jpe-row-menu' );
		if ( menu ) { menu.classList.remove( 'ecs-jpe-row-menu--open' ); }
		menuTargetRow = null;
	}

	// ── Row drag-and-drop ─────────────────────────────────────────────────────

	function bindRowDrag( tr ) {
		var handle = tr.querySelector( '.ecs-jpe-rn-btn' );
		if ( ! handle ) { return; }

		handle.addEventListener( 'mousedown', function () {
			tr.draggable = true;
		} );

		tr.addEventListener( 'dragstart', function ( e ) {
			dragSrcRow = tr;
			tr.classList.add( 'ecs-jpe-dragging' );
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', '' );
		} );

		tr.addEventListener( 'dragend', function () {
			tr.draggable = false;
			dragSrcRow   = null;
			tr.classList.remove( 'ecs-jpe-dragging' );
			var tbody = tr.parentNode;
			if ( tbody ) {
				tbody.querySelectorAll( '.ecs-jpe-drag-over' ).forEach( function ( r ) {
					r.classList.remove( 'ecs-jpe-drag-over' );
				} );
			}
		} );

		tr.addEventListener( 'dragover', function ( e ) {
			if ( ! dragSrcRow || dragSrcRow === tr ) { return; }
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			tr.parentNode.querySelectorAll( '.ecs-jpe-drag-over' ).forEach( function ( r ) {
				r.classList.remove( 'ecs-jpe-drag-over' );
			} );
			tr.classList.add( 'ecs-jpe-drag-over' );
		} );

		tr.addEventListener( 'dragleave', function ( e ) {
			if ( ! tr.contains( e.relatedTarget ) ) {
				tr.classList.remove( 'ecs-jpe-drag-over' );
			}
		} );

		tr.addEventListener( 'drop', function ( e ) {
			if ( ! dragSrcRow || dragSrcRow === tr ) { return; }
			e.preventDefault();
			var tbody  = tr.parentNode;
			var rows   = Array.from( tbody.children );
			var srcIdx = rows.indexOf( dragSrcRow );
			var dstIdx = rows.indexOf( tr );
			if ( srcIdx < dstIdx ) {
				tbody.insertBefore( dragSrcRow, tr.nextSibling );
			} else {
				tbody.insertBefore( dragSrcRow, tr );
			}
			tr.classList.remove( 'ecs-jpe-drag-over' );
			updateRowNums( tbody.closest( 'table' ) );
		} );
	}

	function bindRowMenuBtn( tr ) {
		var btn = tr.querySelector( '.ecs-jpe-rn-btn' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var menu = document.getElementById( 'ecs-jpe-row-menu' );
			if ( menuTargetRow === tr && menu && menu.classList.contains( 'ecs-jpe-row-menu--open' ) ) {
				closeRowMenu();
			} else {
				openRowMenu( tr, btn );
			}
		} );
	}

	function populateSheet( sheetEl, data ) {
		var tableEl = sheetEl.querySelector( '.ecs-jpe-sheet-table' );
		var thead   = tableEl.querySelector( 'thead' );
		var tbody   = tableEl.querySelector( 'tbody' );
		thead.innerHTML = '';
		tbody.innerHTML = '';

		var keys = [];
		if ( Array.isArray( data ) ) {
			data.forEach( function ( row ) {
				if ( row && typeof row === 'object' ) {
					Object.keys( row ).forEach( function ( k ) {
						if ( keys.indexOf( k ) === -1 ) { keys.push( k ); }
					} );
				}
			} );
		}
		if ( ! keys.length ) { keys = [ 'column1' ]; }

		// Header row
		var trh = document.createElement( 'tr' );
		trh.appendChild( makeRowNum( '#' ) );
		keys.forEach( function ( k ) { trh.appendChild( makeCell( k, true ) ); } );
		thead.appendChild( trh );

		// Data rows
		if ( Array.isArray( data ) && data.length ) {
			data.forEach( function ( row, i ) {
				var tr = document.createElement( 'tr' );
				tr.appendChild( makeRowNum( i + 1 ) );
				keys.forEach( function ( k ) {
					var v    = row[ k ];
					var text = ( v === null || v === undefined ) ? '' :
					           ( typeof v === 'object' ) ? JSON.stringify( v ) : String( v );
					tr.appendChild( makeCell( text, false ) );
				} );
				tbody.appendChild( tr );
				bindRowDrag( tr );
				bindRowMenuBtn( tr );
			} );
		} else {
			var tr = document.createElement( 'tr' );
			tr.appendChild( makeRowNum( 1 ) );
			keys.forEach( function () { tr.appendChild( makeCell( '', false ) ); } );
			tbody.appendChild( tr );
			bindRowDrag( tr );
			bindRowMenuBtn( tr );
		}
	}

	function readSheet( sheetEl ) {
		var tableEl     = sheetEl.querySelector( '.ecs-jpe-sheet-table' );
		var headerCells = tableEl.querySelectorAll( 'thead tr th:not(.ecs-jpe-sheet-rn) .ecs-jpe-cell' );
		var headers     = Array.from( headerCells ).map( function ( c ) { return c.textContent.trim(); } );

		var rows = [];
		tableEl.querySelectorAll( 'tbody tr' ).forEach( function ( tr ) {
			var cells = tr.querySelectorAll( 'td:not(.ecs-jpe-sheet-rn) .ecs-jpe-cell' );
			var obj   = {};
			headers.forEach( function ( h, i ) {
				if ( h ) { obj[ h ] = cells[ i ] ? cells[ i ].textContent : ''; }
			} );
			if ( Object.keys( obj ).some( function ( k ) { return obj[ k ].trim() !== ''; } ) ) {
				rows.push( obj );
			}
		} );
		return rows;
	}

	// Fill only tbody rows, preserving existing thead columns.
	function fillSheetRows( sheetEl, data, keys ) {
		var tableEl = sheetEl.querySelector( '.ecs-jpe-sheet-table' );
		var tbody   = tableEl.querySelector( 'tbody' );
		tbody.innerHTML = '';

		if ( Array.isArray( data ) && data.length ) {
			data.forEach( function ( row, i ) {
				var tr = document.createElement( 'tr' );
				tr.appendChild( makeRowNum( i + 1 ) );
				keys.forEach( function ( k ) {
					var v    = row[ k ];
					var text = ( v === null || v === undefined ) ? '' :
					           ( typeof v === 'object' ) ? JSON.stringify( v ) : String( v );
					tr.appendChild( makeCell( text, false ) );
				} );
				tbody.appendChild( tr );
				bindRowDrag( tr );
				bindRowMenuBtn( tr );
			} );
		} else {
			var tr = document.createElement( 'tr' );
			tr.appendChild( makeRowNum( 1 ) );
			keys.forEach( function () { tr.appendChild( makeCell( '', false ) ); } );
			tbody.appendChild( tr );
			bindRowDrag( tr );
			bindRowMenuBtn( tr );
		}
	}

	function pasteIntoSheet( sheetEl, text ) {
		// Always preserve existing columns — never overwrite headers from clipboard.
		var existingHeaders = Array.from(
			sheetEl.querySelectorAll( '.ecs-jpe-sheet-table thead th:not(.ecs-jpe-sheet-rn) .ecs-jpe-cell' )
		).map( function ( c ) { return c.textContent.trim(); } );

		if ( ! existingHeaders.length ) { return; }

		var sep  = detectDelimiter( text );
		var rows = text.split( '\n' )
			.map( function ( l ) { return parseDsvLine( l.replace( /\r$/, '' ), sep ); } )
			.filter( function ( cells ) { return cells.some( function ( c ) { return c.trim() !== ''; } ); } );

		if ( ! rows.length ) { return; }

		// Decide if first row is a header row: any cell matches an existing column name.
		var firstRow         = rows[ 0 ].map( function ( c ) { return c.trim(); } );
		var hasHeaderRow     = firstRow.some( function ( c ) { return existingHeaders.indexOf( c ) !== -1; } );
		var pasteHeaders     = hasHeaderRow ? firstRow : existingHeaders;
		var dataRows         = hasHeaderRow ? rows.slice( 1 ) : rows;

		// Map each clipboard row to existing column keys.
		var data = dataRows.map( function ( cells ) {
			var obj = {};
			existingHeaders.forEach( function ( h, posIdx ) {
				var namedIdx = pasteHeaders.indexOf( h );
				var val = namedIdx !== -1 ? ( cells[ namedIdx ] || '' ) :
				          ( cells[ posIdx ] !== undefined ? cells[ posIdx ] : '' );
				obj[ h ] = val;
			} );
			return obj;
		} );

		fillSheetRows( sheetEl, data, existingHeaders );
	}

	function bindSheetEvents( sheetEl ) {
		var tableEl = sheetEl.querySelector( '.ecs-jpe-sheet-table' );

		// Paste anywhere on the table: fill from clipboard TSV/CSV
		tableEl.addEventListener( 'paste', function ( e ) {
			e.preventDefault();
			var text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' );
			pasteIntoSheet( sheetEl, text );
		} );

		// Tab key: move between cells
		tableEl.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Tab' ) { return; }
			e.preventDefault();
			var cells = Array.from( tableEl.querySelectorAll( '.ecs-jpe-cell' ) );
			var idx   = cells.indexOf( document.activeElement );
			if ( idx === -1 ) { return; }
			var next = cells[ e.shiftKey ? idx - 1 : idx + 1 ];
			if ( next ) {
				next.focus();
				// Move cursor to end
				var range = document.createRange();
				range.selectNodeContents( next );
				range.collapse( false );
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( range );
			}
		} );

		// Add row button
		sheetEl.querySelector( '.ecs-jpe-sheet-add-row' ).addEventListener( 'click', function () {
			var thead    = tableEl.querySelector( 'thead tr' );
			var colCount = thead.querySelectorAll( 'th:not(.ecs-jpe-sheet-rn)' ).length;
			var tbody    = tableEl.querySelector( 'tbody' );
			var tr = document.createElement( 'tr' );
			tr.appendChild( makeRowNum( 1 ) );
			for ( var i = 0; i < colCount; i++ ) {
				tr.appendChild( makeCell( '', false ) );
			}
			tbody.appendChild( tr );
			bindRowDrag( tr );
			bindRowMenuBtn( tr );
			updateRowNums( tableEl );
		} );
	}

	// ── Tree rendering ────────────────────────────────────────────────────────

	function valuePreview( val ) {
		if ( val === null )              return '<span class="ecs-jpe-tv-null">null</span>';
		if ( typeof val === 'boolean' )  return '<span class="ecs-jpe-tv-bool">' + val + '</span>';
		if ( typeof val === 'number' )   return '<span class="ecs-jpe-tv-num">'  + val + '</span>';
		if ( typeof val === 'string' )   return '<span class="ecs-jpe-tv-str">"' + esc( val.length > 60 ? val.slice( 0, 60 ) + '…' : val ) + '"</span>';
		if ( Array.isArray( val ) )      return '<span class="ecs-jpe-tv-meta">[' + val.length + ']</span>';
		if ( typeof val === 'object' )   return '<span class="ecs-jpe-tv-meta">{' + Object.keys( val ).length + '}</span>';
		return '';
	}

	function renderNode( val, keyLabel, depth ) {
		var indent = depth * 16;

		if ( val !== null && typeof val === 'object' ) {
			var isArr    = Array.isArray( val );
			var entries  = isArr ? val.map( function( v, i ) { return [ i, v ]; } ) : Object.entries( val );
			var count    = entries.length;
			var brackets = isArr ? [ '[', ']' ] : [ '{', '}' ];
			var preview  = entries.slice( 0, 2 ).map( function( e ) { return valuePreview( e[1] ); } ).join( ', ' );

			var childrenHtml = entries.map( function( entry ) {
				var k = isArr ? ( 'Item ' + ( parseInt( entry[0] ) + 1 ) ) : entry[0];
				return renderNode( entry[1], k, depth + 1 );
			} ).join( '' );

			return '<div class="ecs-jpe-tn" style="--jpe-indent:' + indent + 'px">' +
				'<div class="ecs-jpe-tn-row">' +
					'<button class="ecs-jpe-tn-toggle" type="button" aria-expanded="true">▾</button>' +
					( keyLabel !== undefined ? '<span class="ecs-jpe-tn-key">' + esc( String( keyLabel ) ) + '</span><span class="ecs-jpe-tn-sep">: </span>' : '' ) +
					'<span class="ecs-jpe-tv-meta">' + brackets[0] + '</span>' +
					'<span class="ecs-jpe-tn-count">' + count + ( isArr ? ' items' : ' props' ) + '</span>' +
					'<span class="ecs-jpe-tn-preview">' + preview + '</span>' +
				'</div>' +
				'<div class="ecs-jpe-tn-children">' + childrenHtml + '</div>' +
				'<div class="ecs-jpe-tn-close">' + brackets[1] + '</div>' +
			'</div>';
		}

		return '<div class="ecs-jpe-tl" style="--jpe-indent:' + indent + 'px">' +
			( keyLabel !== undefined ? '<span class="ecs-jpe-tn-key">' + esc( String( keyLabel ) ) + '</span><span class="ecs-jpe-tn-sep">: </span>' : '' ) +
			valuePreview( val ) +
		'</div>';
	}

	function renderTree( data ) {
		if ( ! Array.isArray( data ) || data.length === 0 ) {
			return '<p class="ecs-jpe-tree-empty">No items</p>';
		}
		return data.map( function( item, i ) {
			return renderNode( item, 'Item ' + ( i + 1 ), 0 );
		} ).join( '' );
	}

	function bindTreeEvents( treeEl ) {
		treeEl.addEventListener( 'click', function( e ) {
			var btn = e.target.closest( '.ecs-jpe-tn-toggle' );
			if ( ! btn ) return;
			var node     = btn.closest( '.ecs-jpe-tn' );
			var children = node.querySelector( ':scope > .ecs-jpe-tn-children' );
			var close    = node.querySelector( ':scope > .ecs-jpe-tn-close' );
			var expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
			btn.setAttribute( 'aria-expanded', ! expanded );
			btn.textContent     = expanded ? '▸' : '▾';
			children.style.display = expanded ? 'none' : '';
			if ( close ) close.style.display = expanded ? 'none' : '';
			var preview = node.querySelector( ':scope > .ecs-jpe-tn-row .ecs-jpe-tn-preview' );
			if ( preview ) preview.style.display = expanded ? '' : 'none';
		} );
	}

	// ── Modal ─────────────────────────────────────────────────────────────────

	function getOrCreateModal() {
		var existing = document.getElementById( MODAL_ID );
		if ( existing ) { return existing; }

		var modal = document.createElement( 'div' );
		modal.id  = MODAL_ID;
		modal.innerHTML = [
			'<div class="ecs-jpe-backdrop"></div>',
			'<div class="ecs-jpe-dialog">',
			'  <div class="ecs-jpe-header">',
			'    <div class="ecs-jpe-header-icon">{}</div>',
			'    <span class="ecs-jpe-title">JSON PowerEdit</span>',
			'    <div class="ecs-jpe-header-actions">',
			'      <button class="ecs-jpe-close" title="Close">&times;</button>',
			'    </div>',
			'  </div>',
			'  <div class="ecs-jpe-meta"></div>',
			'  <textarea class="ecs-jpe-textarea" spellcheck="false"></textarea>',
			'  <div class="ecs-jpe-tree-toolbar" style="display:none">',
			'    <button class="ecs-jpe-action" data-tree-action="expand">Expand All</button>',
			'    <button class="ecs-jpe-action" data-tree-action="collapse">Collapse All</button>',
			'  </div>',
			'  <div class="ecs-jpe-tree" style="display:none"></div>',
			'  <div class="ecs-jpe-sheet" style="display:none">',
			'    <div class="ecs-jpe-sheet-hint">',
			'      Paste from Excel or Google Sheets (Ctrl+V anywhere on the table).',
			'      First row = field names. URLs, images, and icons auto-convert from plain text.',
			'    </div>',
			'    <div class="ecs-jpe-sheet-scroll">',
			'      <table class="ecs-jpe-sheet-table"><thead></thead><tbody></tbody></table>',
			'    </div>',
			'    <button class="ecs-jpe-action ecs-jpe-sheet-add-row" type="button">+ Add Row</button>',
			'  </div>',
			'  <div class="ecs-jpe-error"></div>',
			'  <div class="ecs-jpe-toolbar">',
			'    <button class="ecs-jpe-action" data-action="tree" id="ecs-jpe-tree-btn">Accessibility</button>',
			'    <button class="ecs-jpe-action" data-action="sheet">Spreadsheet</button>',
			'    <button class="ecs-jpe-action" data-action="format">Format</button>',
			'    <button class="ecs-jpe-action" data-action="copy">Copy</button>',
			'    <button class="ecs-jpe-action" data-action="reset">Reset</button>',
			'    <button class="ecs-jpe-action" data-action="clear">Clear</button>',
			'    <button class="ecs-jpe-action ecs-jpe-apply" data-action="apply">Apply</button>',
			'  </div>',
			'</div>',
		].join( '' );

		document.body.appendChild( modal );

		var tb = modal.querySelector( '#ecs-jpe-tree-btn' );
		if ( tb ) { tb.innerHTML = ACCESSIBILITY_SVG + 'Accessibility'; tb.removeAttribute( 'id' ); }

		var treeEl      = modal.querySelector( '.ecs-jpe-tree' );
		var treeToolbar = modal.querySelector( '.ecs-jpe-tree-toolbar' );
		var sheetEl     = modal.querySelector( '.ecs-jpe-sheet' );

		treeToolbar.addEventListener( 'click', function( e ) {
			var act = e.target.dataset.treeAction;
			if ( act === 'expand' )   setAllNodes( treeEl, true );
			if ( act === 'collapse' ) setAllNodes( treeEl, false );
		} );

		bindTreeEvents( treeEl );
		bindSheetEvents( sheetEl );

		return modal;
	}

	function setAllNodes( treeEl, expand ) {
		treeEl.querySelectorAll( '.ecs-jpe-tn' ).forEach( function( node ) {
			var btn      = node.querySelector( ':scope > .ecs-jpe-tn-row .ecs-jpe-tn-toggle' );
			var children = node.querySelector( ':scope > .ecs-jpe-tn-children' );
			var close    = node.querySelector( ':scope > .ecs-jpe-tn-close' );
			var preview  = node.querySelector( ':scope > .ecs-jpe-tn-row .ecs-jpe-tn-preview' );
			if ( ! btn ) return;
			btn.setAttribute( 'aria-expanded', expand );
			btn.textContent = expand ? '▾' : '▸';
			if ( children ) children.style.display = expand ? '' : 'none';
			if ( close )    close.style.display    = expand ? '' : 'none';
			if ( preview )  preview.style.display  = expand ? 'none' : '';
		} );
	}

	function openModal( controlKey, widgetLabel, currentVal, container ) {
		var modal       = getOrCreateModal();
		var textarea    = modal.querySelector( '.ecs-jpe-textarea' );
		var treeEl      = modal.querySelector( '.ecs-jpe-tree' );
		var treeToolbar = modal.querySelector( '.ecs-jpe-tree-toolbar' );
		var sheetEl     = modal.querySelector( '.ecs-jpe-sheet' );
		var metaEl      = modal.querySelector( '.ecs-jpe-meta' );
		var errorEl     = modal.querySelector( '.ecs-jpe-error' );

		var originalJson = JSON.stringify( currentVal, null, 2 );
		var isTreeMode   = false;
		var isSheetMode  = false;

		textarea.value      = originalJson;
		metaEl.textContent  = widgetLabel + '  ·  ' + controlKey + '  ·  ' + currentVal.length + ' item(s)';
		errorEl.style.display = 'none';

		textarea.style.display    = '';
		treeEl.style.display      = 'none';
		treeToolbar.style.display = 'none';
		sheetEl.style.display     = 'none';

		var treeBtn  = modal.querySelector( '[data-action="tree"]' );
		var sheetBtn = modal.querySelector( '[data-action="sheet"]' );
		if ( treeBtn )  { treeBtn.innerHTML = ACCESSIBILITY_SVG + 'Accessibility'; treeBtn.classList.remove( 'ecs-jpe-action--active' ); }
		if ( sheetBtn ) { sheetBtn.classList.remove( 'ecs-jpe-action--active' ); }

		modal.classList.add( 'ecs-jpe-open' );
		textarea.focus();

		function setError( msg ) {
			errorEl.textContent   = msg;
			errorEl.style.display = msg ? 'block' : 'none';
		}

		function switchToTree() {
			var parsed;
			try { parsed = JSON.parse( textarea.value ); } catch ( e ) { setError( 'Invalid JSON — fix before switching to Tree view.' ); return; }
			isTreeMode                = true;
			isSheetMode               = false;
			treeEl.innerHTML          = renderTree( parsed );
			textarea.style.display    = 'none';
			treeEl.style.display      = '';
			treeToolbar.style.display = '';
			sheetEl.style.display     = 'none';
			treeBtn.innerHTML         = '{ } Raw JSON';
			treeBtn.classList.add( 'ecs-jpe-action--active' );
			sheetBtn.classList.remove( 'ecs-jpe-action--active' );
			setError( '' );
		}

		function switchToSheet() {
			var parsed;
			try { parsed = JSON.parse( textarea.value ); } catch ( e ) { parsed = currentVal; }
			if ( ! Array.isArray( parsed ) ) { parsed = []; }
			isSheetMode               = true;
			isTreeMode                = false;
			populateSheet( sheetEl, parsed );
			textarea.style.display    = 'none';
			treeEl.style.display      = 'none';
			treeToolbar.style.display = 'none';
			sheetEl.style.display     = '';
			sheetBtn.classList.add( 'ecs-jpe-action--active' );
			treeBtn.innerHTML         = ACCESSIBILITY_SVG + 'Accessibility';
			treeBtn.classList.remove( 'ecs-jpe-action--active' );
			setError( '' );
		}

		function switchToRaw() {
			if ( isSheetMode ) {
				var rows    = readSheet( sheetEl );
				var applied = applySchemaToRows( rows, currentVal );
				textarea.value = JSON.stringify( applied, null, 2 );
			}
			isTreeMode                = false;
			isSheetMode               = false;
			textarea.style.display    = '';
			treeEl.style.display      = 'none';
			treeToolbar.style.display = 'none';
			sheetEl.style.display     = 'none';
			treeBtn.innerHTML         = ACCESSIBILITY_SVG + 'Accessibility';
			treeBtn.classList.remove( 'ecs-jpe-action--active' );
			sheetBtn.classList.remove( 'ecs-jpe-action--active' );
		}

		function handleAction( e ) {
			var action = e.target.dataset.action;
			if ( ! action ) { return; }

			if ( action === 'tree' ) {
				if ( isSheetMode ) { switchToRaw(); return; }
				isTreeMode ? switchToRaw() : switchToTree();
				return;
			}

			if ( action === 'sheet' ) {
				if ( isTreeMode ) { switchToRaw(); }
				isSheetMode ? switchToRaw() : switchToSheet();
				return;
			}

			if ( action === 'format' ) {
				if ( isSheetMode ) { return; }
				try {
					var parsed = JSON.parse( textarea.value );
					textarea.value = JSON.stringify( parsed, null, 2 );
					setError( '' );
				} catch ( parseErr ) {
					setError( 'Invalid JSON — cannot format. ' + parseErr.message );
				}
				return;
			}

			if ( action === 'copy' ) {
				var text;
				if ( isSheetMode ) {
					// Export as TSV
					var rows = readSheet( sheetEl );
					if ( rows.length ) {
						var keys = Object.keys( rows[ 0 ] );
						var lines = [ keys.join( '\t' ) ];
						rows.forEach( function ( row ) {
							lines.push( keys.map( function ( k ) {
								var v = row[ k ];
								return v.indexOf( '\t' ) !== -1 || v.indexOf( '"' ) !== -1
									? '"' + v.replace( /"/g, '""' ) + '"'
									: v;
							} ).join( '\t' ) );
						} );
						text = lines.join( '\n' );
					} else {
						text = '';
					}
				} else {
					text = textarea.value;
				}
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text )
						.then( function () { flashButton( e.target, 'Copied!' ); } )
						.catch( function () { legacyCopyText( text ); } );
				} else {
					legacyCopyText( text );
				}
				return;
			}

			if ( action === 'reset' ) {
				textarea.value = originalJson;
				setError( '' );
				if ( isTreeMode )  { switchToTree();  return; }
				if ( isSheetMode ) {
					populateSheet( sheetEl, currentVal );
				}
				return;
			}

			if ( action === 'clear' ) {
				if ( isSheetMode ) {
					populateSheet( sheetEl, [] );
				} else {
					textarea.value = '[]';
					if ( isTreeMode ) { switchToTree(); }
				}
				setError( '' );
				return;
			}

			if ( action === 'apply' ) {
				var raw;
				if ( isSheetMode ) {
					var rows = readSheet( sheetEl );
					if ( ! rows.length ) {
						setError( 'No data found. Make sure the table has at least one data row.' );
						return;
					}
					raw = applySchemaToRows( rows, currentVal );
				} else {
					try {
						raw = JSON.parse( textarea.value );
					} catch ( jsonErr ) {
						if ( isTreeMode ) { switchToRaw(); }
						setError( 'Invalid JSON: ' + jsonErr.message );
						return;
					}
				}

				var validationError = validate( raw );
				if ( validationError ) { setError( validationError ); return; }
				applyToContainer( container, controlKey, raw );
				closeModal( modal );
			}
		}

		var toolbar    = modal.querySelector( '.ecs-jpe-toolbar' );
		var newToolbar = toolbar.cloneNode( true );
		toolbar.parentNode.replaceChild( newToolbar, toolbar );
		newToolbar.addEventListener( 'click', handleAction );

		treeBtn  = modal.querySelector( '[data-action="tree"]' );
		sheetBtn = modal.querySelector( '[data-action="sheet"]' );

		modal.querySelector( '.ecs-jpe-close' ).onclick    = function () { closeModal( modal ); };
		modal.querySelector( '.ecs-jpe-backdrop' ).onclick = function () { closeModal( modal ); };
	}

	function closeModal( modal ) {
		modal.classList.remove( 'ecs-jpe-open' );
	}

	function flashButton( btn, label ) {
		var orig = btn.textContent;
		btn.textContent = label;
		setTimeout( function () { btn.textContent = orig; }, 1200 );
	}

	function legacyCopyText( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( _e ) {}
		document.body.removeChild( ta );
	}

	// ── Apply to Elementor ────────────────────────────────────────────────────

	function applyToContainer( container, controlKey, newValue ) {
		var collection    = container.settings.get( controlKey );
		var existingCount = ( collection && collection.models ) ? collection.models.length : 0;

		for ( var i = existingCount - 1; i >= 0; i-- ) {
			try {
				$e.run( 'document/repeater/remove', { container: container, name: controlKey, index: i } );
			} catch ( _ ) {}
		}

		newValue.forEach( function ( row, idx ) {
			try {
				$e.run( 'document/repeater/insert', {
					container : container,
					name      : controlKey,
					model     : row,
					options   : { at: idx },
				} );
			} catch ( _ ) {}
		} );

		try {
			$e.internal( 'document/save/set-is-modified', { status: true } );
		} catch ( _ ) {
			try { window.elementor.saver.setFlagEditorChange(); } catch ( _ ) {}
		}
	}

	// ── Button injection ──────────────────────────────────────────────────────

	function injectButton( controlEl ) {
		if ( controlEl.hasAttribute( BTN_DONE_ATTR ) ) { return; }
		controlEl.setAttribute( BTN_DONE_ATTR, '1' );

		var controlKey = controlEl.dataset.setting;
		if ( ! controlKey ) {
			controlEl.classList.forEach( function ( cls ) {
				if ( controlKey ) { return; }
				var m = cls.match( /^elementor-control-([a-zA-Z0-9_]+)$/ );
				if ( m ) { controlKey = m[ 1 ]; }
			} );
		}
		if ( ! controlKey ) { return; }

		var btn = document.createElement( 'button' );
		btn.className   = BTN_CLASS;
		btn.type        = 'button';
		btn.textContent = 'Edit JSON';

		btn.addEventListener( 'click', function () {
			var container = getActiveContainer();
			if ( ! container ) { return; }

			var rawVal = container.settings.get( controlKey );
			var val    = rawVal;
			if ( val && typeof val.toJSON === 'function' ) { val = val.toJSON(); }
			if ( ! Array.isArray( val ) ) { val = []; }

			var widgetType  = container.model && container.model.get( 'widgetType' );
			var widgetLabel = widgetType || 'Widget';
			try {
				var cache = window.elementor.widgetsCache[ widgetType ];
				if ( cache && cache.title ) { widgetLabel = cache.title; }
			} catch ( _e ) {}

			openModal( controlKey, widgetLabel, val, container );
		} );

		var addRow = controlEl.querySelector( '.elementor-repeater-add' );
		if ( addRow ) {
			addRow.parentNode.insertBefore( btn, addRow.nextSibling );
		} else {
			controlEl.appendChild( btn );
		}
	}

	function scanPanel() {
		var panel = document.getElementById( 'elementor-panel' );
		if ( ! panel ) { return; }
		panel.querySelectorAll( '.elementor-control-type-repeater:not([' + BTN_DONE_ATTR + '])' )
			.forEach( injectButton );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			var modal = document.getElementById( MODAL_ID );
			if ( modal && modal.classList.contains( 'ecs-jpe-open' ) ) { closeModal( modal ); }
		}
	} );

	function init() {
		var panel = document.getElementById( 'elementor-panel' );
		if ( ! panel ) { return; }
		new MutationObserver( function () { scanPanel(); } ).observe( panel, { childList: true, subtree: true } );
		scanPanel();
	}

	jQuery( window ).on( 'elementor:init', init );
	var pollTimer = setInterval( function () {
		if ( window.elementor && window.elementor.getPanelView ) { clearInterval( pollTimer ); init(); }
	}, 200 );

}( jQuery ) );
