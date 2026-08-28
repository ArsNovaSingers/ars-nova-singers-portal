/**
 * The "Sheet music" panel on a project.
 *
 * Draws the folder picker and the waiting list, and talks to admin-ajax. All
 * the real decisions live server-side; this is the surface.
 *
 * Deliberately plain: no framework, no build step. It is one meta box in
 * wp-admin, and a build pipeline for it would be a bigger commitment than the
 * feature.
 */
( function () {
	'use strict';

	var cfg = window.anspSheetMusic;
	if ( ! cfg ) {
		return;
	}

	var root = document.querySelector( '[data-ansp-sm]' );
	if ( ! root ) {
		return;
	}

	var elCurrent = root.querySelector( '[data-ansp-sm-current]' );
	var elPicker  = root.querySelector( '[data-ansp-sm-picker]' );
	var elCrumb   = root.querySelector( '[data-ansp-sm-crumb]' );
	var elFolders = root.querySelector( '[data-ansp-sm-folders]' );
	var elPaste   = root.querySelector( '[data-ansp-sm-paste]' );
	var elStatus  = root.querySelector( '[data-ansp-sm-status]' );
	var elList    = root.querySelector( '[data-ansp-sm-list]' );
	var btnScan   = root.querySelector( '[data-ansp-sm-scan]' );

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'post_id', cfg.postId );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( ( j && j.data && j.data.message ) || 'Something went wrong.' );
				}
				return j.data;
			} );
	}

	function say( message, kind ) {
		elStatus.className = 'ansp-sm-status' + ( kind ? ' is-' + kind : '' );
		elStatus.textContent = message || '';
	}

	function busy( button, on, labelWhenBusy ) {
		if ( ! button ) {
			return;
		}
		if ( on ) {
			button.dataset.label = button.textContent;
			button.textContent = labelWhenBusy || 'Working…';
			button.disabled = true;
		} else {
			button.textContent = button.dataset.label || button.textContent;
			button.disabled = false;
		}
	}

	/* ---- folder picker ------------------------------------------------ */

	function browse( parent ) {
		elFolders.innerHTML = '<li class="ansp-sm-muted">Loading…</li>';
		post( 'ansp_sm_browse', { parent: parent || '' } )
			.then( function ( d ) {
				elCrumb.innerHTML = '';
				if ( d.at ) {
					var up = document.createElement( 'button' );
					up.type = 'button';
					up.className = 'button-link';
					up.textContent = '← ' + ( d.at.parents && d.at.parents.length ? 'Up one level' : 'All drives' );
					up.addEventListener( 'click', function () {
						browse( d.at.parents && d.at.parents.length ? d.at.parents[ 0 ] : '' );
					} );
					elCrumb.appendChild( up );

					var here = document.createElement( 'span' );
					here.className = 'ansp-sm-here';
					here.textContent = d.at.name;
					elCrumb.appendChild( here );

					var pick = document.createElement( 'button' );
					pick.type = 'button';
					pick.className = 'button button-primary button-small';
					pick.textContent = 'Use this folder';
					pick.addEventListener( 'click', function () {
						choose( d.at.id, d.at.name, pick );
					} );
					elCrumb.appendChild( pick );
				}

				elFolders.innerHTML = '';
				if ( ! d.folders.length ) {
					elFolders.innerHTML = '<li class="ansp-sm-muted">No folders inside this one.</li>';
					return;
				}
				d.folders.forEach( function ( f ) {
					var li = document.createElement( 'li' );
					var a = document.createElement( 'button' );
					a.type = 'button';
					a.className = 'button-link';
					a.textContent = '📁 ' + f.name;
					a.addEventListener( 'click', function () { browse( f.id ); } );
					li.appendChild( a );
					elFolders.appendChild( li );
				} );
			} )
			.catch( function ( e ) {
				elFolders.innerHTML = '';
				say( e.message, 'error' );
			} );
	}

	function choose( folder, name, button ) {
		busy( button, true, 'Checking…' );
		post( 'ansp_sm_save_folder', { folder: folder, name: name || '' } )
			.then( function ( d ) {
				elCurrent.innerHTML = '';
				var strong = document.createElement( 'strong' );
				strong.textContent = d.name || d.id;
				var span = document.createElement( 'span' );
				span.className = 'ansp-sm-muted';
				span.textContent = d.id;
				elCurrent.appendChild( strong );
				elCurrent.appendChild( document.createTextNode( ' ' ) );
				elCurrent.appendChild( span );
				elPicker.hidden = true;
				if ( btnScan ) {
					btnScan.disabled = false;
				}
				say( 'Root folder set. Now scan it.', 'ok' );
			} )
			.catch( function ( e ) { say( e.message, 'error' ); } )
			.then( function () { busy( button, false ); } );
	}

	root.querySelector( '[data-ansp-sm-setfolder]' ).addEventListener( 'click', function () {
		elPicker.hidden = ! elPicker.hidden;
		if ( ! elPicker.hidden && ! elFolders.children.length ) {
			browse( '' );
		}
	} );

	root.querySelector( '[data-ansp-sm-usepaste]' ).addEventListener( 'click', function ( e ) {
		choose( elPaste.value, '', e.target );
	} );

	/* ---- the waiting list --------------------------------------------- */

	function row( item ) {
		var box = document.createElement( 'div' );
		box.className = 'ansp-sm-row' + ( item.is_big ? ' is-big' : '' );

		var head = document.createElement( 'div' );
		head.className = 'ansp-sm-rowhead';
		head.innerHTML =
			'<span class="ansp-sm-file">' + esc( item.source_name ) + '</span>' +
			'<span class="ansp-sm-meta">' + esc( item.size_human ) +
			( item.pages ? ' · ' + item.pages + ' pages' : '' ) +
			( item.project ? ' · ' + esc( item.project ) : '' ) + '</span>';
		box.appendChild( head );

		/*
		 * The warning sits on the row it is about. That is the whole point of
		 * this panel existing instead of a separate screen: file size is
		 * something you notice about a file while adding it, not an errand.
		 */
		if ( item.is_big && ! item.optimised ) {
			var warn = document.createElement( 'div' );
			warn.className = 'ansp-sm-warn';
			warn.innerHTML = '<span class="ansp-sm-warnicon">⚠</span> <span>This file is large (' +
				esc( item.size_human ) + '). It will be slow for singers to download, and above 32 MB ' +
				'it cannot be synced to a tablet at all.</span> ';
			var opt = document.createElement( 'button' );
			opt.type = 'button';
			opt.className = 'button button-secondary button-small';
			opt.textContent = 'Optimize';
			opt.addEventListener( 'click', function () {
				busy( opt, true, 'Optimising…' );
				say( 'Making ' + item.source_name + ' smaller. This can take a minute.', '' );
				post( 'ansp_sm_optimise', { staging_id: item.staging_id } )
					.then( function ( d ) {
						var r = d.result || {};
						if ( r.changed ) {
							say( item.source_name + ' is now ' + Math.round( r.saved_ratio * 100 ) +
								'% smaller. Check the name and add it.', 'ok' );
						} else {
							say( item.source_name + ': ' + ( r.outcome || 'nothing to gain here.' ), '' );
						}
						draw( d );
					} )
					.catch( function ( e ) { say( e.message, 'error' ); busy( opt, false ); } );
			} );
			warn.appendChild( opt );
			box.appendChild( warn );
		}

		if ( item.optimised ) {
			var good = document.createElement( 'div' );
			good.className = 'ansp-sm-good';
			good.textContent = '✓ Made smaller before publishing — was ' + item.was_human +
				', saved ' + item.saved_human + '.';
			box.appendChild( good );
		}

		var nameRow = document.createElement( 'div' );
		nameRow.className = 'ansp-sm-name';

		if ( 'new_edition' === item.decision ) {
			nameRow.innerHTML = '<span class="ansp-sm-muted">Replaces an existing piece — ' +
				esc( item.why || 'same music, new file' ) + '. The published name does not change.</span>';
		} else {
			var label = document.createElement( 'label' );
			label.textContent = 'Publish as';
			var input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'regular-text';
			input.value = item.proposed || '';
			input.setAttribute( 'data-ansp-sm-canonical', '' );
			label.appendChild( input );
			nameRow.appendChild( label );
			if ( item.why ) {
				var why = document.createElement( 'p' );
				why.className = 'ansp-sm-muted';
				why.textContent = item.why;
				nameRow.appendChild( why );
			}
		}
		box.appendChild( nameRow );

		var actions = document.createElement( 'div' );
		actions.className = 'ansp-sm-actions';

		var add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'button button-primary';
		add.textContent = 'new_edition' === item.decision ? 'Add this version' : 'Add this piece';
		add.addEventListener( 'click', function () {
			var input = box.querySelector( '[data-ansp-sm-canonical]' );
			busy( add, true, 'Adding…' );
			post( 'ansp_sm_approve', {
				staging_id: item.staging_id,
				decision: item.decision === 'new_edition' ? 'new_edition' : 'new_work',
				work_id: item.work_id || '',
				canonical: input ? input.value : ''
			} )
				.then( function ( d ) {
					say( 'Added ' + ( ( d.result && d.result.canonical ) || item.source_name ) + '.', 'ok' );
					draw( d );
				} )
				.catch( function ( e ) { say( e.message, 'error' ); busy( add, false ); } );
		} );
		actions.appendChild( add );

		var skip = document.createElement( 'button' );
		skip.type = 'button';
		skip.className = 'button';
		skip.textContent = 'Skip';
		skip.addEventListener( 'click', function () {
			busy( skip, true, 'Skipping…' );
			post( 'ansp_sm_approve', { staging_id: item.staging_id, decision: 'reject' } )
				.then( function ( d ) {
					say( 'Skipped ' + item.source_name + '. Nothing was changed.', '' );
					draw( d );
				} )
				.catch( function ( e ) { say( e.message, 'error' ); busy( skip, false ); } );
		} );
		actions.appendChild( skip );

		box.appendChild( actions );
		return box;
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : s;
		return d.innerHTML;
	}

	function draw( data ) {
		elList.innerHTML = '';
		var items = ( data && data.items ) || [];
		if ( ! items.length ) {
			var p = document.createElement( 'p' );
			p.className = 'ansp-sm-muted';
			p.textContent = 'Nothing waiting. Scan the folder to look for new music.';
			elList.appendChild( p );
			return;
		}
		items.forEach( function ( item ) {
			elList.appendChild( row( item ) );
		} );
	}

	if ( btnScan ) {
		btnScan.addEventListener( 'click', function () {
			busy( btnScan, true, 'Scanning…' );
			say( 'Reading the folder. Large scores take a while to check.', '' );
			post( 'ansp_sm_scan', {} )
				.then( function ( d ) {
					var s = d.scan || {};
					var found = ( s.results || [] ).filter( function ( r ) { return 'staged' === r.outcome; } ).length;
					say(
						found
							? found + ' new file' + ( 1 === found ? '' : 's' ) + ' to look at below.'
							: 'Nothing new — everything in that folder has already been added.',
						'ok'
					);
					draw( d );
				} )
				.catch( function ( e ) { say( e.message, 'error' ); } )
				.then( function () { busy( btnScan, false ); } );
		} );
	}

	// Draw whatever is already waiting when the project is opened.
	post( 'ansp_sm_pending', {} ).then( draw ).catch( function () {} );
} )();
