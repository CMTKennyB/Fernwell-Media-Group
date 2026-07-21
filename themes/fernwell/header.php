<?php
/**
 * Site header.
 *
 * @package Fernwell
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

<header class="site">
  <div class="wrap nav">
    <a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
      <span class="tile">FMG</span>
      <span class="words">
        <span class="top">Fernwell</span><br>
        <span class="bot">Media Group</span>
      </span>
    </a>
    <nav class="links">
      <a href="<?php echo esc_url( home_url( '/#how' ) ); ?>">How it works</a>
      <a href="<?php echo esc_url( home_url( '/#services' ) ); ?>">Services</a>
      <a href="<?php echo esc_url( home_url( '/#faq' ) ); ?>">FAQ</a>
      <a href="<?php echo esc_url( home_url( '/#about' ) ); ?>">About</a>
      <a class="cta" href="<?php echo esc_url( home_url( '/#contact' ) ); ?>">Get an audit</a>
    </nav>
  </div>
</header>
