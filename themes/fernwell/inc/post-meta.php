<?php
/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Search & Social Meta
 * Purpose: Native metabox on posts for per-post overrides of what the theme
 * already emits (title tag, meta description, canonical, social image,
 * robots), plus the filters that make the head output honor them. The theme
 * owns its schema and meta tags; this is the control surface. No SEO plugin.
 *
 * Location:
 *   /wp-content/themes/fernwell/inc/post-meta.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta getters. Empty string/zero means "no override, use the default."
 */
function fernwell_post_seo_title( $post_id ) {
	return trim( (string) get_post_meta( $post_id, '_fernwell_seo_title', true ) );
}

function fernwell_post_meta_description( $post_id ) {
	return trim( (string) get_post_meta( $post_id, '_fernwell_meta_desc', true ) );
}

function fernwell_post_canonical( $post_id ) {
	return trim( (string) get_post_meta( $post_id, '_fernwell_canonical', true ) );
}

function fernwell_post_noindex( $post_id ) {
	return (bool) get_post_meta( $post_id, '_fernwell_noindex', true );
}

/**
 * Social image override as array( url, width, height ) or null.
 */
function fernwell_post_social_image( $post_id ) {
	$attachment_id = (int) get_post_meta( $post_id, '_fernwell_social_image_id', true );
	if ( ! $attachment_id ) {
		return null;
	}
	$src = wp_get_attachment_image_src( $attachment_id, 'full' );
	return $src ? array( $src[0], $src[1], $src[2] ) : null;
}

/**
 * Metabox.
 */
function fernwell_meta_box_register() {
	add_meta_box( 'fernwell-search-social', 'Search & Social', 'fernwell_meta_box_render', 'post', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'fernwell_meta_box_register' );

function fernwell_meta_box_render( $post ) {
	wp_nonce_field( 'fernwell_meta_save', 'fernwell_meta_nonce' );

	$seo_title = fernwell_post_seo_title( $post->ID );
	$meta_desc = fernwell_post_meta_description( $post->ID );
	$canonical = fernwell_post_canonical( $post->ID );
	$noindex   = fernwell_post_noindex( $post->ID );
	$image_id  = (int) get_post_meta( $post->ID, '_fernwell_social_image_id', true );
	$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'medium' ) : null;
	?>
	<style>
		#fernwell-search-social .fw-grid { display: grid; grid-template-columns: 33% 33%; column-gap: 4%; align-items: start; }
		#fernwell-search-social .fw-field { margin: 14px 0; }
		#fernwell-search-social .fw-field--title { grid-column: 1; grid-row: 1; }
		#fernwell-search-social .fw-field--desc { grid-column: 2; grid-row: 1; }
		#fernwell-search-social .fw-field--preview { grid-column: 2; grid-row: 2 / span 3; }
		#fernwell-search-social .fw-share-preview { border: 1px solid #dcdcde; border-radius: 8px; overflow: hidden; background: #fff; max-width: 420px; }
		#fernwell-search-social .fw-share-preview__img img { width: 100%; aspect-ratio: 1200 / 630; object-fit: cover; display: block; }
		#fernwell-search-social .fw-share-preview__body { padding: 10px 12px; }
		#fernwell-search-social .fw-share-preview__site { color: #646970; font-size: 11px; text-transform: uppercase; letter-spacing: .02em; }
		#fernwell-search-social .fw-share-preview__title { font-weight: 600; font-size: 14px; margin-top: 2px; color: #1d2327; }
		#fernwell-search-social .fw-share-preview__desc { color: #50575e; font-size: 12px; margin-top: 2px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
		#fernwell-search-social .fw-field--canonical { grid-column: 1; grid-row: 2; }
		#fernwell-search-social .fw-field--image { grid-column: 1; grid-row: 3; }
		#fernwell-search-social .fw-field--noindex { grid-column: 1; grid-row: 4; }
		#fernwell-search-social label { display: block; font-weight: 600; margin-bottom: 4px; }
		#fernwell-search-social input[type="text"], #fernwell-search-social input[type="url"], #fernwell-search-social textarea { width: 100%; }
		@media (max-width: 782px) {
			#fernwell-search-social .fw-grid { display: block; }
		}
		#fernwell-search-social .fw-hint { color: #646970; font-size: 12px; margin-top: 4px; }
		#fernwell-search-social .fw-count.over { color: #b32d2e; font-weight: 600; }
		#fw-social-image-preview img { max-width: 220px; height: auto; display: block; margin-bottom: 8px; border-radius: 4px; }
	</style>

	<div class="fw-grid">
	<div class="fw-field fw-field--title">
		<label for="fw-seo-title">SEO title</label>
		<input type="text" id="fw-seo-title" name="fernwell_seo_title" value="<?php echo esc_attr( $seo_title ); ?>" data-fw-count="60" placeholder="<?php echo esc_attr( get_the_title( $post ) ); ?>">
		<p class="fw-hint">Overrides the title tag and social title. The "| Fernwell Answers" suffix stays. <span class="fw-count"><?php echo esc_html( strlen( $seo_title ) ); ?></span>/60 characters.</p>
	</div>

	<div class="fw-field fw-field--desc">
		<label for="fw-meta-desc">Meta description</label>
		<textarea id="fw-meta-desc" name="fernwell_meta_desc" rows="3" data-fw-count="155"><?php echo esc_textarea( $meta_desc ); ?></textarea>
		<p class="fw-hint">Overrides the excerpt-based description in the meta description, social tags, and the Article schema abstract. <span class="fw-count"><?php echo esc_html( strlen( $meta_desc ) ); ?></span>/155 characters.</p>
	</div>

	<?php
	$default_card = fernwell_answers_card();
	if ( ! $default_card ) {
		$default_card = get_theme_file_uri( 'assets/og-card.png' );
	}
	$fallback_desc = trim( wp_strip_all_tags( get_post_field( 'post_excerpt', $post ) ) );
	?>
	<div class="fw-field fw-field--preview">
		<label>Share preview</label>
		<div class="fw-share-preview" id="fw-share-preview"
			data-default-image="<?php echo esc_url( $default_card ); ?>"
			data-post-title="<?php echo esc_attr( get_the_title( $post ) ); ?>"
			data-fallback-desc="<?php echo esc_attr( $fallback_desc ); ?>"
			data-suffix="Fernwell Answers">
			<div class="fw-share-preview__img"><img id="fw-share-preview-img" src="<?php echo esc_url( $image_src ? $image_src[0] : $default_card ); ?>" alt=""></div>
			<div class="fw-share-preview__body">
				<div class="fw-share-preview__site"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
				<div class="fw-share-preview__title" id="fw-share-preview-title"></div>
				<div class="fw-share-preview__desc" id="fw-share-preview-desc"></div>
			</div>
		</div>
		<p class="fw-hint">How a shared link renders. Updates as you type; the image follows the social image field.</p>
	</div>

	<div class="fw-field fw-field--canonical">
		<label for="fw-canonical">Canonical URL</label>
		<input type="url" id="fw-canonical" name="fernwell_canonical" value="<?php echo esc_attr( $canonical ); ?>" placeholder="<?php echo esc_attr( get_permalink( $post ) ); ?>">
		<p class="fw-hint">Only set when this post's canonical home is a different URL.</p>
	</div>

	<div class="fw-field fw-field--image">
		<label>Social image</label>
		<div id="fw-social-image-preview"><?php if ( $image_src ) : ?><img src="<?php echo esc_url( $image_src[0] ); ?>" alt=""><?php endif; ?></div>
		<input type="hidden" id="fw-social-image-id" name="fernwell_social_image_id" value="<?php echo esc_attr( $image_id ? $image_id : '' ); ?>">
		<button type="button" class="button" id="fw-social-image-select"><?php echo $image_id ? 'Change image' : 'Select image'; ?></button>
		<button type="button" class="button" id="fw-social-image-remove" <?php echo $image_id ? '' : 'style="display:none"'; ?>>Remove</button>
		<p class="fw-hint">Overrides the default FMG card in og:image and twitter:image. 1200&times;630 works best.</p>
	</div>

	<div class="fw-field fw-field--noindex">
		<label for="fw-noindex"><input type="checkbox" id="fw-noindex" name="fernwell_noindex" value="1" <?php checked( $noindex ); ?>> Ask engines not to index this post</label>
		<p class="fw-hint">Adds noindex to the robots meta tag. Crawlers can still read the page.</p>
	</div>
	</div>
	<?php
}

function fernwell_meta_box_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->post_type ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script( 'fernwell-admin-meta', get_theme_file_uri( 'assets/js/admin-meta.js' ), array(), null, true );
}
add_action( 'admin_enqueue_scripts', 'fernwell_meta_box_assets' );

function fernwell_meta_box_save( $post_id ) {
	if ( ! isset( $_POST['fernwell_meta_nonce'] ) || ! wp_verify_nonce( $_POST['fernwell_meta_nonce'], 'fernwell_meta_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = array(
		'_fernwell_seo_title'       => sanitize_text_field( wp_unslash( $_POST['fernwell_seo_title'] ?? '' ) ),
		'_fernwell_meta_desc'       => sanitize_textarea_field( wp_unslash( $_POST['fernwell_meta_desc'] ?? '' ) ),
		'_fernwell_canonical'       => esc_url_raw( wp_unslash( $_POST['fernwell_canonical'] ?? '' ) ),
		'_fernwell_social_image_id' => absint( $_POST['fernwell_social_image_id'] ?? 0 ),
		'_fernwell_noindex'         => empty( $_POST['fernwell_noindex'] ) ? '' : '1',
	);

	foreach ( $fields as $key => $value ) {
		if ( '' === $value || 0 === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
add_action( 'save_post_post', 'fernwell_meta_box_save' );

/**
 * Front-end wiring: make the theme's head output honor the overrides.
 * Runs after fernwell_document_title_parts (priority 11) so the SEO title
 * replaces the question in the title part while the section suffix stays.
 */
function fernwell_meta_document_title( $parts ) {
	if ( is_singular( 'post' ) ) {
		$override = fernwell_post_seo_title( get_queried_object_id() );
		if ( $override ) {
			$parts['title'] = $override;
		}
	}
	return $parts;
}
add_filter( 'document_title_parts', 'fernwell_meta_document_title', 11 );

function fernwell_meta_canonical( $canonical_url, $post ) {
	$override = fernwell_post_canonical( $post->ID );
	return $override ? $override : $canonical_url;
}
add_filter( 'get_canonical_url', 'fernwell_meta_canonical', 10, 2 );

function fernwell_meta_robots( $robots ) {
	if ( is_singular( 'post' ) && fernwell_post_noindex( get_queried_object_id() ) ) {
		$robots['noindex'] = true;
	}
	return $robots;
}
add_filter( 'wp_robots', 'fernwell_meta_robots' );
