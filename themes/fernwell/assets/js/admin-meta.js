/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Search & Social metabox behavior
 * Purpose: Live character counters, the media-library picker for the
 * social image field, and the live share preview (title, description, and
 * card image mirror what the theme will emit).
 *
 * Location:
 *   /wp-content/themes/fernwell/assets/js/admin-meta.js
 */
( function () {
	'use strict';

	// Character counters on [data-fw-count] fields.
	document.querySelectorAll( '[data-fw-count]' ).forEach( function ( field ) {
		var limit   = parseInt( field.getAttribute( 'data-fw-count' ), 10 );
		var hint    = field.parentElement.querySelector( '.fw-count' );
		if ( ! hint ) {
			return;
		}
		var update = function () {
			hint.textContent = String( field.value.length );
			hint.classList.toggle( 'over', field.value.length > limit );
		};
		field.addEventListener( 'input', update );
		update();
	} );

	// Share preview: mirrors the theme's emitted title and description.
	var preview      = document.getElementById( 'fw-share-preview' );
	var titleField   = document.getElementById( 'fw-seo-title' );
	var descField    = document.getElementById( 'fw-meta-desc' );
	var previewImg   = document.getElementById( 'fw-share-preview-img' );
	var previewTitle = document.getElementById( 'fw-share-preview-title' );
	var previewDesc  = document.getElementById( 'fw-share-preview-desc' );

	function updateSharePreview() {
		if ( ! preview ) {
			return;
		}
		var title = ( titleField && titleField.value.trim() ) || preview.getAttribute( 'data-post-title' ) || '';
		var desc  = ( descField && descField.value.trim() ) || preview.getAttribute( 'data-fallback-desc' ) || '';
		previewTitle.textContent = title + ' | ' + preview.getAttribute( 'data-suffix' );
		previewDesc.textContent  = desc;
	}
	if ( preview ) {
		[ titleField, descField ].forEach( function ( field ) {
			if ( field ) {
				field.addEventListener( 'input', updateSharePreview );
			}
		} );
		updateSharePreview();
	}

	// Social image picker.
	var selectBtn    = document.getElementById( 'fw-social-image-select' );
	var removeBtn    = document.getElementById( 'fw-social-image-remove' );
	var idInput      = document.getElementById( 'fw-social-image-id' );
	var fieldPreview = document.getElementById( 'fw-social-image-preview' );
	if ( ! selectBtn || ! window.wp || ! wp.media ) {
		return;
	}

	var frame = null;
	selectBtn.addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = wp.media( {
				title: 'Select social image',
				library: { type: 'image' },
				multiple: false,
				button: { text: 'Use this image' }
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var thumb      = ( attachment.sizes && attachment.sizes.medium ) ? attachment.sizes.medium.url : attachment.url;
				idInput.value  = attachment.id;
				fieldPreview.innerHTML = '<img src="' + thumb + '" alt="">';
				selectBtn.textContent = 'Change image';
				removeBtn.style.display = '';
				if ( previewImg ) {
					previewImg.src = thumb;
				}
			} );
		}
		frame.open();
	} );

	removeBtn.addEventListener( 'click', function () {
		idInput.value = '';
		fieldPreview.innerHTML = '';
		selectBtn.textContent = 'Select image';
		removeBtn.style.display = 'none';
		if ( previewImg && preview ) {
			previewImg.src = preview.getAttribute( 'data-default-image' );
		}
	} );
} )();
