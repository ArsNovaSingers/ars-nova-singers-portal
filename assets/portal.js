/**
 * Ars Nova Singers Portal — front-end tabs.
 *
 * - Main tabs: [data-ansp-tab] buttons ↔ [data-ansp-panel] sections.
 * - Project sub-tabs: [data-ansp-subtab] ↔ [data-ansp-subpanel] inside a
 *   [data-ansp-subtabs] container.
 * - Hash deep-linking (#tab-roster) and left/right arrow-key navigation.
 * - Material tag filter (v1.2.0): [data-ansp-tagfilter] checkboxes narrow
 *   the sibling [data-ansp-materials] list live. A material shows when it
 *   has NO tags (general) or at least ONE of its tags is selected (OR
 *   semantics). Selection lives only in the DOM — nothing is persisted.
 *
 * No dependencies.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.getElementById( 'ansp-portal' );
		if ( ! root ) {
			return;
		}

		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[data-ansp-tab]' ) );
		var panels = Array.prototype.slice.call( root.querySelectorAll( '[data-ansp-panel]' ) );

		function showTab( id, updateHash ) {
			var found = false;
			panels.forEach( function ( panel ) {
				if ( panel.getAttribute( 'data-ansp-panel' ) === id ) {
					found = true;
				}
			} );
			if ( ! found ) {
				return;
			}

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-ansp-tab' ) === id;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', active ? '0' : '-1' );
			} );
			panels.forEach( function ( panel ) {
				var active = panel.getAttribute( 'data-ansp-panel' ) === id;
				panel.classList.toggle( 'is-active', active );
				if ( active ) {
					panel.removeAttribute( 'hidden' );
				} else {
					panel.setAttribute( 'hidden', 'hidden' );
				}
			} );

			if ( updateHash && window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#tab-' + id );
			}
		}

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				showTab( tab.getAttribute( 'data-ansp-tab' ), true );
				tab.focus();
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				var next = null;
				if ( 'ArrowRight' === e.key ) {
					next = tabs[ ( i + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === e.key ) {
					next = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
				}
				if ( next ) {
					e.preventDefault();
					showTab( next.getAttribute( 'data-ansp-tab' ), true );
					next.focus();
				}
			} );
		} );

		// Restore tab from the URL hash (#tab-roster).
		if ( window.location.hash && 0 === window.location.hash.indexOf( '#tab-' ) ) {
			showTab( window.location.hash.substring( 5 ), false );
		}

		// ---- "Compose with AI" bio helper (Gemini via admin-ajax) --------
		var aiButton = document.getElementById( 'ansp-ai-compose' );
		if ( aiButton ) {
			aiButton.addEventListener( 'click', function () {
				var cfg = window.anspPortal || {};
				var bioField = document.getElementById( 'ansp_bio' );
				var notesField = document.getElementById( 'ansp-ai-notes' );
				var status = document.getElementById( 'ansp-ai-status' );
				var spinner = aiButton.querySelector( '.ansp-ai-spinner' );

				function setStatus( text, isError ) {
					if ( status ) {
						status.textContent = text || '';
						status.classList.toggle( 'is-error', !! isError );
					}
				}

				if ( ! bioField ) {
					return;
				}
				if ( ! cfg.hasGeminiKey ) {
					setStatus( cfg.noKeyMessage || "AI compose isn't set up yet — add a Gemini API key in Singers Portal → Settings.", true );
					return;
				}

				aiButton.disabled = true;
				if ( spinner ) {
					spinner.removeAttribute( 'hidden' );
				}
				setStatus( cfg.composing || 'Composing…', false );

				var body = new window.URLSearchParams();
				body.append( 'action', 'ansp_ai_bio' );
				body.append( 'nonce', cfg.aiNonce || '' );
				body.append( 'notes', notesField ? notesField.value : '' );

				window.fetch( cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString()
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( data ) {
						if ( data && data.success && data.data && data.data.bio ) {
							bioField.value = data.data.bio;
							bioField.focus();
							setStatus( '', false );
						} else {
							var message = ( data && data.data && data.data.message ) ? data.data.message : ( cfg.aiError || 'Something went wrong.' );
							setStatus( message, true );
						}
					} )
					.catch( function () {
						setStatus( cfg.aiError || 'Something went wrong.', true );
					} )
					.then( function () {
						aiButton.disabled = false;
						if ( spinner ) {
							spinner.setAttribute( 'hidden', 'hidden' );
						}
					} );
			} );
		}

		// ---- Material tag filter (v1.2.0) --------------------------------
		var filterScopes = Array.prototype.slice.call( root.querySelectorAll( '[data-ansp-material-filter-scope]' ) );
		filterScopes.forEach( function ( scope ) {
			var filter = scope.querySelector( '[data-ansp-tagfilter]' );
			var list = scope.querySelector( '[data-ansp-materials]' );
			if ( ! filter || ! list ) {
				return; // Project without tags: no filter rendered, show all.
			}

			var checkboxes = Array.prototype.slice.call( filter.querySelectorAll( '[data-ansp-tagfilter-tag]' ) );
			var emptyNote = scope.querySelector( '[data-ansp-tagfilter-empty]' );

			function applyFilter() {
				var selected = {};
				checkboxes.forEach( function ( box ) {
					if ( box.checked ) {
						selected[ box.getAttribute( 'data-ansp-tagfilter-tag' ) ] = true;
					}
				} );

				var anyVisible = false;
				var items = Array.prototype.slice.call( list.querySelectorAll( '[data-ansp-tags]' ) );
				items.forEach( function ( item ) {
					var raw = item.getAttribute( 'data-ansp-tags' ) || '';
					var tags = raw ? raw.split( '|' ) : [];
					// No tags → general material, always shown.
					var show = 0 === tags.length;
					if ( ! show ) {
						// OR semantics: one selected tag is enough.
						show = tags.some( function ( tag ) {
							return !! selected[ tag ];
						} );
					}
					item.classList.toggle( 'is-filtered-out', ! show );
					if ( show ) {
						item.removeAttribute( 'hidden' );
						anyVisible = true;
					} else {
						item.setAttribute( 'hidden', 'hidden' );
					}
				} );

				if ( emptyNote ) {
					if ( anyVisible || 0 === items.length ) {
						emptyNote.setAttribute( 'hidden', 'hidden' );
					} else {
						emptyNote.removeAttribute( 'hidden' );
					}
				}
			}

			function setAll( state ) {
				checkboxes.forEach( function ( box ) {
					box.checked = state;
				} );
				applyFilter();
			}

			checkboxes.forEach( function ( box ) {
				box.addEventListener( 'change', applyFilter );
			} );

			var allBtn = filter.querySelector( '[data-ansp-tagfilter-all]' );
			var noneBtn = filter.querySelector( '[data-ansp-tagfilter-none]' );
			if ( allBtn ) {
				allBtn.addEventListener( 'click', function () {
					setAll( true );
				} );
			}
			if ( noneBtn ) {
				noneBtn.addEventListener( 'click', function () {
					setAll( false );
				} );
			}
			// Default: everything checked server-side → everything visible.
		} );

		// ---- Project sub-tabs (delegated: one handler per container) -----
		var subContainers = Array.prototype.slice.call( root.querySelectorAll( '[data-ansp-subtabs]' ) );
		subContainers.forEach( function ( container ) {
			container.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest ? e.target.closest( '[data-ansp-subtab]' ) : null;
				if ( ! btn || ! container.contains( btn ) ) {
					return;
				}
				var id = btn.getAttribute( 'data-ansp-subtab' );

				Array.prototype.slice.call( container.querySelectorAll( '[data-ansp-subtab]' ) ).forEach( function ( b ) {
					var active = b === btn;
					b.classList.toggle( 'is-active', active );
					b.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				Array.prototype.slice.call( container.querySelectorAll( '[data-ansp-subpanel]' ) ).forEach( function ( panel ) {
					var active = panel.getAttribute( 'data-ansp-subpanel' ) === id;
					panel.classList.toggle( 'is-active', active );
					if ( active ) {
						panel.removeAttribute( 'hidden' );
					} else {
						panel.setAttribute( 'hidden', 'hidden' );
					}
				} );
			} );
		} );
	} );
} )();
