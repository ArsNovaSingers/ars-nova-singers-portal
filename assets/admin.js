/**
 * Ars Nova Singers Portal — admin JS.
 *
 * 1. Repeatable materials rows on the ans_project edit screen:
 *    clones the <script type="text/html" id="tmpl-ansp-material-row">
 *    template, replacing __INDEX__ with the next free index.
 * 2. Tags chips (v1.2.0): enhances each row's plain tags text input into
 *    removable chips. Typing a tag then Enter or comma adds a chip; the
 *    hidden original input keeps the canonical comma-separated value that
 *    is submitted. The input's datalist offers suggestions (voice parts +
 *    content types) but tags are free-form and unlimited. Without JS the
 *    plain comma-separated text input still works.
 * 3. Generic tab switching for elements using .ansp-admin-tabs +
 *    .nav-tab[data-ansp-admin-tab] ↔ .ansp-admin-tab-panel[data-ansp-admin-panel].
 */
/* global jQuery */
jQuery( function ( $ ) {
	'use strict';

	// ------------------------------------------------------------------
	// Materials repeater
	// ------------------------------------------------------------------
	var $rows = $( '#ansp-materials-rows' );
	var $tmpl = $( '#tmpl-ansp-material-row' );

	// ------------------------------------------------------------------
	// Tags chips input (one per material row)
	// ------------------------------------------------------------------
	function parseTags( value ) {
		var seen = {};
		var tags = [];
		String( value || '' ).split( ',' ).forEach( function ( tag ) {
			tag = tag.replace( /^\s+|\s+$/g, '' );
			if ( ! tag ) {
				return;
			}
			var key = tag.toLowerCase();
			if ( seen[ key ] ) {
				return;
			}
			seen[ key ] = true;
			tags.push( tag );
		} );
		return tags;
	}

	function initTagsField( $field ) {
		if ( $field.data( 'anspTagsInit' ) ) {
			return;
		}
		$field.data( 'anspTagsInit', true );

		var $store = $field.find( '.ansp-tags-input' );
		var $chips = $field.find( '[data-ansp-tags-chips]' );
		if ( ! $store.length || ! $chips.length ) {
			return;
		}

		// Split into a hidden canonical store + a visible entry box. The
		// store input keeps its name and submits the comma-separated value.
		var $entry = $( '<input>', {
			type: 'text',
			'class': 'widefat ansp-tags-entry',
			list: $store.attr( 'list' ) || null,
			autocomplete: 'off',
			'aria-label': $store.attr( 'aria-label' ) || null,
			placeholder: $store.attr( 'placeholder' ) || null
		} );
		$store.after( $entry );
		$field.addClass( 'is-enhanced' );

		function render() {
			var tags = parseTags( $store.val() );
			$store.val( tags.join( ', ' ) );
			$chips.empty();
			tags.forEach( function ( tag ) {
				var $chip = $( '<span>', { 'class': 'ansp-admin-tag-chip' } ).text( tag );
				$( '<button>', {
					type: 'button',
					'class': 'ansp-admin-tag-remove',
					'aria-label': 'Remove tag: ' + tag,
					text: '×'
				} ).attr( 'data-ansp-tag', tag ).appendTo( $chip );
				$chips.append( $chip );
			} );
		}

		function addFromEntry() {
			var value = $entry.val().replace( /^\s+|\s+$/g, '' ).replace( /,+$/, '' );
			if ( ! value ) {
				$entry.val( '' );
				return;
			}
			$store.val( $store.val() ? $store.val() + ', ' + value : value );
			$entry.val( '' );
			render();
		}

		$entry.on( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ',' === e.key ) {
				e.preventDefault();
				addFromEntry();
			} else if ( 'Backspace' === e.key && ! $entry.val() ) {
				// Backspace in an empty box removes the last chip.
				var tags = parseTags( $store.val() );
				tags.pop();
				$store.val( tags.join( ', ' ) );
				render();
			}
		} );
		// Datalist picks fire `change`; also catch blur so a typed tag
		// isn't silently lost when Tom clicks Update without pressing Enter.
		$entry.on( 'change blur', addFromEntry );

		$chips.on( 'click', '.ansp-admin-tag-remove', function () {
			var remove = String( $( this ).attr( 'data-ansp-tag' ) ).toLowerCase();
			var tags = parseTags( $store.val() ).filter( function ( tag ) {
				return tag.toLowerCase() !== remove;
			} );
			$store.val( tags.join( ', ' ) );
			render();
		} );

		render();
	}

	function initAllTagsFields( $scope ) {
		$scope.find( '[data-ansp-tags-field]' ).each( function () {
			initTagsField( $( this ) );
		} );
	}

	if ( $rows.length && $tmpl.length ) {
		// Next index = 1 + highest index currently in the DOM (avoids name
		// collisions after removals).
		function nextIndex() {
			var max = -1;
			$rows.find( 'input, select' ).each( function () {
				var name = $( this ).attr( 'name' ) || '';
				var m = name.match( /^ansp_materials\[(\d+)\]/ );
				if ( m ) {
					max = Math.max( max, parseInt( m[ 1 ], 10 ) );
				}
			} );
			return max + 1;
		}

		$( '#ansp-add-material' ).on( 'click', function () {
			var html = $tmpl.html().replace( /__INDEX__/g, String( nextIndex() ) );
			$rows.append( html );
			// Init the chips UI on the new row (already-initialised rows are
			// skipped via the anspTagsInit data flag).
			initAllTagsFields( $rows );
		} );

		$rows.on( 'click', '.ansp-remove-material', function () {
			if ( window.confirm( 'Remove this material? (It is deleted when you save the project.)' ) ) {
				$( this ).closest( 'tr' ).remove();
			}
		} );

		initAllTagsFields( $rows );
	}

	// ------------------------------------------------------------------
	// Generic admin tabs
	// ------------------------------------------------------------------
	$( '.ansp-admin-tabs' ).each( function () {
		var $wrap = $( this );
		$wrap.on( 'click', '[data-ansp-admin-tab]', function ( e ) {
			e.preventDefault();
			var id = $( this ).data( 'anspAdminTab' );
			$wrap.find( '[data-ansp-admin-tab]' ).removeClass( 'nav-tab-active' );
			$( this ).addClass( 'nav-tab-active' );
			$wrap.find( '[data-ansp-admin-panel]' ).removeClass( 'is-active' );
			$wrap.find( '[data-ansp-admin-panel="' + id + '"]' ).addClass( 'is-active' );
		} );
	} );
} );
