<?php
/**
 * Template de página en blanco a pantalla completa para el POS.
 * Sin header/footer del theme: solo wp_head()/wp_footer() para que se carguen
 * los assets encolados, y el contenido de la página (que trae el shortcode).
 *
 * @package Keytill
 */

defined( 'ABSPATH' ) || exit;

// Sin barra de administración en el POS (pantalla completa, operada por teclado).
show_admin_bar( false );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'keytill-fullscreen' ); ?>>
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
	<?php wp_footer(); ?>
</body>
</html>
