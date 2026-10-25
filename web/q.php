<?php
declare( strict_types = 1 );
/**
 * Query test helper script.
 *
 * Executes a test GraphQL query against the local IMDb proxy endpoint.
 *
 * @package jcvignoli\graphql
 * @see \jcvignoli\Graphql\Env
 */
require_once __DIR__ . '/vendor/autoload.php';

use jcvignoli\Graphql\Env;

// Load environment variables
Env::load( __DIR__ );
$envHost  = getenv( 'LOCAL_HOST' );
$endpoint = $_ENV['LOCAL_HOST'] ?? ( is_string( $envHost ) ? $envHost : '' );

$query = '
query GetTitleReleaseDates($id: ID!) {
  title(id: $id) {
    releaseDates(first: 100) {
      edges {
        node {
          day
          month
          year
          country {
            id
            text
          }
          attributes {
            text
          }
        }
      }
    }
  }
}';

$variables = array(
	'id' => 'tt0120737',
);

$ch       = curl_init( $endpoint );
$postData = json_encode(
	array(
		'query'     => $query,
		'variables' => $variables,
	)
);

curl_setopt_array(
	$ch,
	array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST           => true,
		CURLOPT_HTTPHEADER     => array(
			'Content-Type: application/json',
			'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'Accept: application/json',
			'x-imdb-client-name: imdb-web-next-localized',
		),
		CURLOPT_POSTFIELDS     => is_string( $postData ) ? $postData : '',
		CURLOPT_SSL_VERIFYPEER => false,
	)
);

$response = curl_exec( $ch );

$data = is_string( $response ) ? json_decode( $response, true ) : null;

echo '<pre>';
print_r( $data ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
echo '</pre>';
