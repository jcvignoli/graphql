<?php
declare( strict_types = 1 );
/**
 * IMDb GraphQL Proxy API
 *
 * Overview:
 * ---------
 * This endpoint acts as a lightweight proxy to the IMDb GraphQL API (https://api.graphql.imdb.com)
 * and manages local file-based caching for schema type queries under the `cache/` directory.
 *
 * How it works & Available Options:
 * ---------------------------------
 * 1. GET ?list_types=1
 *    Returns a JSON object listing all cached type filenames in the `cache/` directory,
 *    sorted in ascending order by file modification timestamp (`filemtime`, oldest first).
 *    Used by `ImdbApiRefreshCache` for FIFO (First-In, First-Out) cache rotation.
 *    Response: {"status": "ok", "types": ["TypeName1", "TypeName2", ...]}
 *
 * 2. GET ?type=TypeName&refresh=1
 *    Bypasses local cache for a specific GraphQL type (`TypeName`), fetches the latest type definition
 *    directly from IMDb's GraphQL API, overwrites `cache/TypeName`, and returns the JSON payload.
 *    Response: {"status": "ok", "type": "TypeName", "data": {...}}
 *
 * 3. GET ?refresh=1
 *    Forces a refresh when querying the schema, writing raw body responses directly to `cache/$typeName`.
 *
 * 4. POST (Standard GraphQL proxy request)
 *    Forwards incoming JSON POST GraphQL queries directly to `https://api.graphql.imdb.com`,
 *    returning the response headers and body. See q.php as an example.
 *
 * Adapted from Tom Boothman
 * https://github.com/tboothman/imdbphp/tree/master/graphql
 */

require __DIR__ . '/vendor/autoload.php';

use jcvignoli\Graphql\Env;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

// Load environment variables
Env::load( __DIR__ );

header( 'Content-Type: application/json' );

// List all cached type names sorted by modification time (oldest first)
if ( isset( $_GET['list_types'] ) ) {
	$cacheDir    = 'cache';
	$cachedFiles = [];
	if ( is_dir( $cacheDir ) ) {
		$files     = array_diff( scandir( $cacheDir ), [ '.', '..', '.placeholder' ] );
		$fileTimes = [];
		foreach ( $files as $file ) {
			$filePath = $cacheDir . '/' . $file;
			if ( is_file( $filePath ) ) {
				$fileTimes[ $file ] = filemtime( $filePath );
			}
		}
		// Sort ascending by modification time (oldest first)
		asort( $fileTimes );
		$cachedFiles = array_keys( $fileTimes );
	}
	echo json_encode( [ 'status' => 'ok', 'types' => array_values( $cachedFiles ) ] );
	return;
}

// Refresh a single type directly without traversing the entire schema
if ( isset( $_GET['type'] ) && isset( $_GET['refresh'] ) ) {
	$typeName = preg_replace( '/[^a-zA-Z0-9_]/', '', $_GET['type'] );
	if ( $typeName !== '' ) {
		$lastError  = null;
		$lastErrors = null;
		$type       = typeQuery( $typeName, true, $lastError, $lastErrors );
		// Upstream (IMDb) failure
		if ( $type === null ) {
			http_response_code( 502 );
			$response = [
				'status' => 'failed',
				'type'   => $typeName,
				'data'   => null,
			];
			if ( $lastError !== null ) {
				$response['error'] = $lastError;
			}
			if ( $lastErrors !== null ) {
				$response['errors'] = $lastErrors;
			}
			echo json_encode( $response, JSON_PRETTY_PRINT );
			return;
		}
		echo json_encode( [ 'status' => 'ok', 'type' => $typeName, 'data' => $type ] );
		return;
	}
}

$body = file_get_contents( 'php://input' );

if ( $body === false ) {
	echo 'input couldn\'t be accessed';
	exit(1);
}

writelog( $body );

if ( trim( $body ) === '' ) {
	$body = json_encode(
		[
			'query' => 'query { __schema { queryType { name } } }',
		]
	);
}

// Override request for schema with a custom response
if ($body !== false && strpos( $body, '__schema' ) !== false) {
	$schemaErrors    = [];
	$queryTypeError  = null;
	$queryTypeErrors = null;
	$queryType       = typeQuery( 'Query', false, $queryTypeError, $queryTypeErrors );

	if ( $queryTypeErrors !== null ) {
		foreach ( $queryTypeErrors as $err ) {
			$schemaErrors[] = $err;
		}
	} elseif ( $queryTypeError !== null ) {
		$schemaErrors[] = [ 'message' => $queryTypeError ];
	}

	$types = iterativelyFetchTypes( [ 'Query', 'Mutation' ], $schemaErrors );

	$responseBody = [
		'data' => [
			'__schema' => [
				'mutationType' => [
					'name' => 'Mutation',
				],
				'queryType' => $queryType,
				'types' => $types,
			],
		],
	];

	if ( ! empty( $schemaErrors ) ) {
		$uniqueErrors = [];
		$seen         = [];
		foreach ( $schemaErrors as $err ) {
			$msg = is_array( $err ) ? ( $err['message'] ?? json_encode( $err ) ) : ( ( is_object( $err ) && isset( $err->message ) ) ? $err->message : json_encode( $err ) );
			if ( ! isset( $seen[ $msg ] ) ) {
				$seen[ $msg ] = true;
				$uniqueErrors[] = $err;
			}
		}
		$responseBody['errors'] = $uniqueErrors;
		http_response_code( 500 );
	}

	echo json_encode( $responseBody, JSON_PRETTY_PRINT );
	return;
}

$res = graphqlRequest( $body );
foreach ($res->getHeaders() as $name => $values) {
	foreach ($values as $value) {
		header( sprintf( '%s: %s', $name, $value ), false );
	}
}

echo $res->getBody();

/**
 * Extract incoming headers to forward to IMDb's GraphQL API.
 * Forwarding client headers (x-imdb-*, accept-language, user-agent, etc.) ensures localized text
 * (titleText, nameText, etc.) matches the requesting client's locale and context.
 *
 * @return array<string, string>
 */
function getForwardableHeaders(): array {
	$headers = [];

	if ( function_exists( 'getallheaders' ) ) {
		$all = getallheaders();
		if ( is_array( $all ) ) {
			foreach ( $all as $k => $v ) {
				$headers[ strtolower( (string) $k ) ] = (string) $v;
			}
		}
	}

	foreach ( $_SERVER as $key => $value ) {
		if ( str_starts_with( $key, 'HTTP_' ) ) {
			$headerName             = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
			$headers[ $headerName ] = (string) $value;
		}
	}

	// Always default required headers if not explicitly supplied by client
	$defaultHeaders = [
		'content-type'       => 'application/json',
		'user-agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
		'x-imdb-client-name' => 'imdb-web-next-localized',
	];

	// Forward client headers starting with 'x-imdb-', 'accept-language', or specific allowed headers
	$forwardable = [
		'content-type',
		'user-agent',
		'accept-language',
		'authorization',
	];

	$finalHeaders = [];
	foreach ( $headers as $k => $v ) {
		if ( in_array( $k, $forwardable, true ) || str_starts_with( $k, 'x-imdb-' ) ) {
			$finalHeaders[ $k ] = $v;
		}
	}

	$merged = array_merge( $defaultHeaders, $finalHeaders );

	$imdbAuthToken = $_ENV['IMDB_AUTH_TOKEN'] ?? getenv( 'IMDB_AUTH_TOKEN' ) ?: null;
	if ( empty( $merged['authorization'] ) && ! empty( $imdbAuthToken ) ) {
		$merged['authorization'] = $imdbAuthToken;
	}

	return $merged;
}

/** @param array<string, string>|string $body */
function graphqlRequest( array|string $body ): ResponseInterface {
	$client = new Client(
		[
			'timeout' => 10.0,
		]
	);

	$headers = getForwardableHeaders();

	return $client->request(
		'POST',
		'https://api.graphql.imdb.com',
		[
			'body'    => is_array( $body ) ? json_encode( $body ) : $body,
			'headers' => $headers,
		]
	);
}

/**
 * @param array $seedTypes
 * @param array $schemaErrors Output/accumulated errors
 * @return stdClass[]
 */
function iterativelyFetchTypes( array $seedTypes, array &$schemaErrors = [] ): array {
	$todo = $seedTypes;
	$done = [];
	$result = [];

	$addToQueue = function ( $newType ) use ( &$todo, &$done ) {
		if ( ! in_array( $newType, $done, true ) && ! in_array( $newType, $todo, true )) {
			$todo[] = $newType;
		}
	};

	while (count( $todo )) {
		$typeName   = array_shift( $todo );
		$done[]      = $typeName;
		$lastError  = null;
		$lastErrors = null;
		$type       = typeQuery( $typeName, false, $lastError, $lastErrors );

		if ( ! $type ) {
			if ( $lastErrors !== null ) {
				foreach ( $lastErrors as $err ) {
					$schemaErrors[] = $err;
				}
			} elseif ( $lastError !== null ) {
				$schemaErrors[] = [ 'message' => $lastError ];
			}
			continue;
		}

		$recurseTypeNames = function ( stdClass $src ) use ( $addToQueue ) {
			if (isset( $src->name ) && $src->name !== null) {
				$addToQueue( $src->name );
			}
			if (isset( $src->ofType ) && $src->ofType !== null) {
				if (isset( $src->ofType->name ) && $src->ofType->name !== null) {
					$addToQueue( $src->ofType->name );
				}
				if (isset( $src->ofType->ofType ) && $src->ofType->ofType !== null) {
					if (isset( $src->ofType->ofType->name ) && $src->ofType->ofType->name !== null) {
						$addToQueue( $src->ofType->ofType->name );
					}
					if (isset( $src->ofType->ofType->ofType ) && $src->ofType->ofType->ofType !== null) {
						if (isset( $src->ofType->ofType->ofType->name ) && $src->ofType->ofType->ofType->name !== null) {
							$addToQueue( $src->ofType->ofType->ofType->name );
						}
					}
				}
			}
		};

		if (isset( $type->fields ) && is_array( $type->fields )) {
			foreach ($type->fields as $field) {
				$recurseTypeNames( $field->type );

				if (isset( $field->args ) && is_array( $field->args )) {
					foreach ($field->args as $arg) {
						$recurseTypeNames( $arg->type );
					}
				}
			}
		}

		if (isset( $type->interfaces ) && is_array( $type->interfaces )) {
			foreach ($type->interfaces as $interface) {
				$recurseTypeNames( $interface );
			}
		}

		if (isset( $type->inputFields ) && is_array( $type->inputFields )) {
			foreach ($type->inputFields as $inputFields) {
				$recurseTypeNames( $inputFields->type );
			}
		}

		if (isset( $type->possibleTypes ) && is_array( $type->possibleTypes )) {
			foreach ($type->possibleTypes as $possibleTypes) {
				$recurseTypeNames( $possibleTypes );
			}
		}

		$result[] = $type;
	}
	return $result;
}

/**
 * @param string $typeName
 * @param bool $forceRefresh
 * @param string|null $lastError Output parameter for error message string
 * @param array|null $lastErrors Output parameter for GraphQL errors array
 * @return stdClass|null
 */
function typeQuery( string $typeName, bool $forceRefresh = false, ?string &$lastError = null, ?array &$lastErrors = null ): ?stdClass {
	$lastError  = null;
	$lastErrors = null;
	$query = <<<EOF
query Type(\$type: String!) {
  __type(name: \$type) {
    ...FullType
  }
}

fragment FullType on __Type {
      kind
      name
      description
      fields(includeDeprecated: true) {
        name
        description
        args { ...InputValue }
        type { ...TypeRef }
        isDeprecated
        deprecationReason
      }
      inputFields { ...InputValue }
      interfaces { ...TypeRef }
      enumValues(includeDeprecated: true) {
        name
        description
        isDeprecated
        deprecationReason
      }
      possibleTypes { ...TypeRef }
}

fragment InputValue on __InputValue {
      name
      description
      defaultValue
      type { ...TypeRef }
}

fragment TypeRef on __Type {
      kind
      name
      ofType {
        kind
        name
        ofType {
          kind
          name
          ofType {
            kind
            name
            ofType {
              kind
              name
              ofType {
                kind
                name
                ofType {
                  kind
                  name
                  ofType {
                    kind
                    name
                    ofType {
                      kind
                      name
                    }
                  }
                }
              }
            }
          }
        }
      }
}
EOF;

	$request = [
		'operationName' => 'Type',
		'query'         => $query,
		'variables'     => [
			'type' => $typeName,
		],
	];

	if ( ! is_dir( 'cache' ) ) {
		mkdir( 'cache', 0777, true );
	}

	$forceRefresh  = $forceRefresh || isset( $_GET['refresh'] );
	$cacheFileName = "cache/$typeName";

	if ( file_exists( $cacheFileName ) && ! $forceRefresh ) {
		$json = json_decode( file_get_contents( $cacheFileName ) );
		return $json->data->__type ?? null;
	}

	try {
		$res     = graphqlRequest( json_encode( $request ) );
		$rawBody = (string) $res->getBody();
		
		$json = json_decode( $rawBody );
		if ( isset( $json->data->__type ) && $json->data->__type !== null ) {
			file_put_contents( $cacheFileName, $rawBody );
			return $json->data->__type;
		}

		if ( isset( $json->errors ) && is_array( $json->errors ) && count( $json->errors ) > 0 ) {
			$lastErrors = $json->errors;
			$lastError  = $json->errors[0]->message ?? "Introspection failed for type: $typeName";
		} else {
			$lastError  = "Introspection failed for type: $typeName";
			$lastErrors = [ [ 'message' => $lastError ] ];
		}
		writelog( "Introspection failed for type: $typeName. Error: " . $lastError );
	} catch ( \Throwable $e ) {
		$errorMsg = $e->getMessage();
		if ( $e instanceof RequestException && $e->hasResponse() && $e->getResponse() !== null ) {
			$resBody = (string) $e->getResponse()->getBody();
			$json    = json_decode( $resBody );
			if ( isset( $json->errors ) && is_array( $json->errors ) && count( $json->errors ) > 0 ) {
				$lastErrors = $json->errors;
				$lastError  = $json->errors[0]->message ?? $errorMsg;
			} else {
				$lastError  = $errorMsg;
				$lastErrors = [ [ 'message' => $lastError ] ];
			}
		} else {
			$lastError  = $errorMsg;
			$lastErrors = [ [ 'message' => $lastError ] ];
		}
		writelog( "Introspection failed for type: $typeName. Error: " . $lastError );
	}

	// Fallback to local cache if live introspection failed and a cached file exists
	if ( file_exists( $cacheFileName ) ) {
		touch( $cacheFileName );
		$cachedContent = file_get_contents( $cacheFileName );
		if ( $cachedContent !== false && trim( $cachedContent ) !== '' ) {
			$json = json_decode( $cachedContent );
			if ( isset( $json->data->__type ) && $json->data->__type !== null ) {
				$lastError  = null;
				$lastErrors = null;
				return $json->data->__type;
			}
			if ( isset( $json->name ) ) {
				$lastError  = null;
				$lastErrors = null;
				return $json;
			}
		}
	}

	return null;
}

/**
 * Write log
 */
function writelog( string $logLine ): void {
	file_put_contents( __DIR__ . '/log.txt', $logLine . "\n", FILE_APPEND );
}
