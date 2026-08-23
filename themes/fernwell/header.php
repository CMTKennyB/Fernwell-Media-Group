<?php
/**
 * © 2026 Kenneth Raymond — All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Site Header
 * Purpose: Document head, FMG brand lockup, and primary navigation.
 *
 * Location:
 *   /wp-content/themes/fernwell/header.php
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#main">Skip to content</a>

<header class="site">
  <div class="wrap nav">
    <a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
      <span class="tile">FMG</span>
      <span class="words">
        <span class="top">Fernwell</span><br>
        <span class="bot">Media Group</span>
      </span>
    </a>
    <button class="nav-toggle" aria-expanded="false" aria-controls="site-nav">
      <span class="nav-toggle__bars" aria-hidden="true"><span></span><span></span><span></span></span>
      <span class="screen-reader-text">Menu</span>
    </button>
    <nav class="links" id="site-nav" aria-label="Primary">
      <a href="<?php echo esc_url( home_url( '/#how' ) ); ?>">How it works</a>
      <a href="<?php echo esc_url( home_url( '/#services' ) ); ?>">Services</a>
      <a href="<?php echo esc_url( home_url( '/#faq' ) ); ?>">FAQ</a>
      <a href="<?php echo esc_url( home_url( '/#about' ) ); ?>">About</a>
      <a class="cta" href="mailto:hello@fernwellgroup.com?subject=Request%20your%20audit">Request your audit</a>
    </nav>
  </div>
</header>

<main id="main">
