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
	$cachedFiles = array();
	if ( is_dir( $cacheDir ) ) {
		$files     = array_diff( scandir( $cacheDir ), array( '.', '..', '.placeholder' ) );
		$fileTimes = array();
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
	echo json_encode(
		array(
			'status' => 'ok',
			'types'  => $cachedFiles,
		)
	);
	return;
}

// Refresh a single type directly without traversing the entire schema
if ( isset( $_GET['type'] ) && isset( $_GET['refresh'] ) ) {
	$typeName = preg_replace( '/[^a-zA-Z0-9_]/', '', $_GET['type'] );
	if ( is_string( $typeName ) && $typeName !== '' ) {
		$lastError  = null;
		$lastErrors = null;
		$type       = typeQuery( $typeName, true, $lastError, $lastErrors );
		// Upstream (IMDb) failure
		if ( $type === null ) {
			http_response_code( 502 );
			$response = array(
				'status' => 'failed',
				'type'   => $typeName,
				'data'   => null,
			);
			if ( $lastError !== null ) {
				$response['error'] = $lastError;
			}
			if ( $lastErrors !== null ) {
				$response['errors'] = $lastErrors;
			}
			echo json_encode( $response, JSON_PRETTY_PRINT );
			return;
		}
		echo json_encode(
			array(
				'status' => 'ok',
				'type'   => $typeName,
				'data'   => $type,
			)
		);
		return;
	}
}

$rawInput = file_get_contents( 'php://input' );

if ( $rawInput === false ) {
	echo 'input couldn\'t be accessed';
	exit( 1 );
}

$body = $rawInput;

writelog( $body );

if ( trim( $body ) === '' ) {
	$encodedQuery = json_encode(
		array(
			'query' => 'query { __schema { queryType { name } } }',
		)
	);
	$body         = is_string( $encodedQuery ) ? $encodedQuery : '';
}

// Override request for schema with a custom response
if ( strpos( $body, '__schema' ) !== false ) {
	$schemaErrors    = array();
	$queryTypeError  = null;
	$queryTypeErrors = null;
	$queryType       = typeQuery( 'Query', false, $queryTypeError, $queryTypeErrors );

	if ( $queryTypeErrors !== null ) {
		foreach ( $queryTypeErrors as $err ) {
			$schemaErrors[] = $err;
		}
	} elseif ( $queryTypeError !== null ) {
		$schemaErrors[] = array( 'message' => $queryTypeError );
	}

	$types = iterativelyFetchTypes( array( 'Query', 'Mutation' ), $schemaErrors );

	$responseBody = array(
		'data' => array(
			'__schema' => array(
				'mutationType' => array(
					'name' => 'Mutation',
				),
				'queryType'    => $queryType,
				'types'        => $types,
			),
		),
	);

	if ( $schemaErrors !== array() ) {
		$uniqueErrors = array();
		$seen         = array();
		foreach ( $schemaErrors as $err ) {
			$msg = is_array( $err ) ? ( $err['message'] ?? json_encode( $err ) ) : ( ( is_object( $err ) && isset( $err->message ) ) ? $err->message : json_encode( $err ) );
			if ( is_string( $msg ) && ! isset( $seen[ $msg ] ) ) {
				$seen[ $msg ]   = true;
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
foreach ( $res->getHeaders() as $name => $values ) {
	foreach ( $values as $value ) {
		header( sprintf( '%s: %s', $name, $value ), false );
	}
}

echo (string) $res->getBody();

/**
 * Extract incoming headers to forward to IMDb's GraphQL API.
 * Forwarding client headers (x-imdb-*, accept-language, user-agent, etc.) ensures localized text
 * (titleText, nameText, etc.) matches the requesting client's locale and context.
 *
 * @see graphqlRequest
 * @return array<string, string>
 */
function getForwardableHeaders(): array {
	$headers = array();

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
	$defaultHeaders = array(
		'content-type'       => 'application/json',
		'user-agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
		'x-imdb-client-name' => 'imdb-web-next-localized',
	);

	// Forward client headers starting with 'x-imdb-', 'accept-language', or specific allowed headers
	$forwardable = array(
		'content-type',
		'user-agent',
		'accept-language',
		'authorization',
	);

	$finalHeaders = array();
	foreach ( $headers as $k => $v ) {
		if ( in_array( $k, $forwardable, true ) || str_starts_with( $k, 'x-imdb-' ) ) {
			$finalHeaders[ $k ] = $v;
		}
	}

	$merged = array_merge( $defaultHeaders, $finalHeaders );

	$envAuth       = getenv( 'IMDB_AUTH_TOKEN' );
	$imdbAuthToken = $_ENV['IMDB_AUTH_TOKEN'] ?? ( is_string( $envAuth ) && $envAuth !== '' ? $envAuth : null );
	if ( ( ! isset( $merged['authorization'] ) || $merged['authorization'] === '' ) && $imdbAuthToken !== null && $imdbAuthToken !== '' ) {
		$merged['authorization'] = $imdbAuthToken;
	}

	return $merged;
}

/**
 * Perform GraphQL API request to upstream IMDb server.
 *
 * @see getForwardableHeaders
 * @param array<string, string>|string $body Request payload body or array.
 * @return ResponseInterface
 */
function graphqlRequest( array|string $body ): ResponseInterface {
	$client = new Client(
		array(
			'timeout' => 10.0,
		)
	);

	$headers = getForwardableHeaders();

	$encodedBody = is_array( $body ) ? json_encode( $body ) : $body;

	return $client->request(
		'POST',
		'https://api.graphql.imdb.com',
		array(
			'body'    => is_string( $encodedBody ) ? $encodedBody : '',
			'headers' => $headers,
		)
	);
}

/**
 * Recursively fetch type definitions for a schema.
 *
 * @see typeQuery
 * @param array<int, string> $seedTypes
 * @param array<mixed> $schemaErrors Output/accumulated errors
 * @return stdClass[]
 */
function iterativelyFetchTypes( array $seedTypes, array &$schemaErrors = array() ): array {
	/** @var list<string> $todo */
	$todo = $seedTypes;
	/** @var list<string> $done */
	$done   = array();
	$result = array();

	$addToQueue = function ( string $newType ) use ( &$todo, &$done ): void {
		if ( ! in_array( $newType, $done, true ) && ! in_array( $newType, $todo, true ) ) {
			$todo[] = $newType;
		}
	};

	while ( $todo !== array() ) {
		$typeName   = array_shift( $todo );
		$done[]     = $typeName;
		$lastError  = null;
		$lastErrors = null;
		$type       = typeQuery( $typeName, false, $lastError, $lastErrors );

		if ( $type === null ) {
			if ( $lastErrors !== null ) {
				foreach ( $lastErrors as $err ) {
					$schemaErrors[] = $err;
				}
			} elseif ( $lastError !== null ) {
				$schemaErrors[] = array( 'message' => $lastError );
			}
			continue;
		}

		$recurseTypeNames = function ( stdClass $src ) use ( $addToQueue ): void {
			if ( isset( $src->name ) && is_string( $src->name ) ) {
				$addToQueue( $src->name );
			}
			if ( isset( $src->ofType ) && $src->ofType instanceof stdClass ) {
				if ( isset( $src->ofType->name ) && is_string( $src->ofType->name ) ) {
					$addToQueue( $src->ofType->name );
				}
				if ( isset( $src->ofType->ofType ) && $src->ofType->ofType instanceof stdClass ) {
					if ( isset( $src->ofType->ofType->name ) && is_string( $src->ofType->ofType->name ) ) {
						$addToQueue( $src->ofType->ofType->name );
					}
					if ( isset( $src->ofType->ofType->ofType ) && $src->ofType->ofType->ofType instanceof stdClass ) {
						if ( isset( $src->ofType->ofType->ofType->name ) && is_string( $src->ofType->ofType->ofType->name ) ) {
							$addToQueue( $src->ofType->ofType->ofType->name );
						}
					}
				}
			}
		};

		if ( isset( $type->fields ) && is_array( $type->fields ) ) {
			foreach ( $type->fields as $field ) {
				if ( $field instanceof stdClass && isset( $field->type ) && $field->type instanceof stdClass ) {
					$recurseTypeNames( $field->type );
				}

				if ( $field instanceof stdClass && isset( $field->args ) && is_array( $field->args ) ) {
					foreach ( $field->args as $arg ) {
						if ( $arg instanceof stdClass && isset( $arg->type ) && $arg->type instanceof stdClass ) {
							$recurseTypeNames( $arg->type );
						}
					}
				}
			}
		}

		if ( isset( $type->interfaces ) && is_array( $type->interfaces ) ) {
			foreach ( $type->interfaces as $interface ) {
				if ( $interface instanceof stdClass ) {
					$recurseTypeNames( $interface );
				}
			}
		}

		if ( isset( $type->inputFields ) && is_array( $type->inputFields ) ) {
			foreach ( $type->inputFields as $inputField ) {
				if ( $inputField instanceof stdClass && isset( $inputField->type ) && $inputField->type instanceof stdClass ) {
					$recurseTypeNames( $inputField->type );
				}
			}
		}

		if ( isset( $type->possibleTypes ) && is_array( $type->possibleTypes ) ) {
			foreach ( $type->possibleTypes as $possibleType ) {
				if ( $possibleType instanceof stdClass ) {
					$recurseTypeNames( $possibleType );
				}
			}
		}

		$result[] = $type;
	}
	return $result;
}

/**
 * Query type definition from IMDb or cache.
 *
 * @see graphqlRequest
 * @see writelog
 * @param string $typeName GraphQL type name to query.
 * @param bool $forceRefresh True to force fetching from upstream.
 * @param string|null $lastError Output parameter for error message string.
 * @param array<mixed>|null $lastErrors Output parameter for GraphQL errors array.
 * @return stdClass|null
 */
function typeQuery( string $typeName, bool $forceRefresh = false, ?string &$lastError = null, ?array &$lastErrors = null ): ?stdClass {
	$lastError  = null;
	$lastErrors = null;
	$query      = <<<'EOF'
query Type($type: String!) {
  __type(name: $type) {
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

	$request = array(
		'operationName' => 'Type',
		'query'         => $query,
		'variables'     => array(
			'type' => $typeName,
		),
	);

	if ( ! is_dir( 'cache' ) ) {
		mkdir( 'cache', 0777, true );
	}

	$forceRefresh  = $forceRefresh || isset( $_GET['refresh'] );
	$cacheFileName = "cache/$typeName";

	if ( file_exists( $cacheFileName ) && ! $forceRefresh ) {
		$cacheContent = file_get_contents( $cacheFileName );
		if ( is_string( $cacheContent ) ) {
			$json = json_decode( $cacheContent );
			if ( $json instanceof stdClass && isset( $json->data ) && $json->data instanceof stdClass && isset( $json->data->__type ) && $json->data->__type instanceof stdClass ) {
				return $json->data->__type;
			}
		}
		return null;
	}

	try {
		$reqBody = json_encode( $request );
		if ( is_string( $reqBody ) ) {
			$res     = graphqlRequest( $reqBody );
			$rawBody = (string) $res->getBody();

			$json = json_decode( $rawBody );
			if ( $json instanceof stdClass && isset( $json->data ) && $json->data instanceof stdClass && isset( $json->data->__type ) && $json->data->__type instanceof stdClass ) {
				file_put_contents( $cacheFileName, $rawBody );
				return $json->data->__type;
			}

			if ( $json instanceof stdClass && isset( $json->errors ) && is_array( $json->errors ) && count( $json->errors ) > 0 ) {
				$lastErrors = $json->errors;
				$firstErr   = $json->errors[0];
				$lastError  = ( is_object( $firstErr ) && isset( $firstErr->message ) && is_string( $firstErr->message ) ) ? $firstErr->message : "Introspection failed for type: $typeName";
			} else {
				$lastError  = "Introspection failed for type: $typeName";
				$lastErrors = array( array( 'message' => $lastError ) );
			}
			writelog( "Introspection failed for type: $typeName. Error: " . $lastError );
		}
	} catch ( \Throwable $e ) {
		$errorMsg = $e->getMessage();
		if ( $e instanceof RequestException && $e->hasResponse() && $e->getResponse() !== null ) {
			$resBody = (string) $e->getResponse()->getBody();
			$json    = json_decode( $resBody );
			if ( $json instanceof stdClass && isset( $json->errors ) && is_array( $json->errors ) && count( $json->errors ) > 0 ) {
				$lastErrors = $json->errors;
				$firstErr   = $json->errors[0];
				$lastError  = ( is_object( $firstErr ) && isset( $firstErr->message ) && is_string( $firstErr->message ) ) ? $firstErr->message : $errorMsg;
			} else {
				$lastError  = $errorMsg;
				$lastErrors = array( array( 'message' => $lastError ) );
			}
		} else {
			$lastError  = $errorMsg;
			$lastErrors = array( array( 'message' => $lastError ) );
		}
		writelog( "Introspection failed for type: $typeName. Error: " . $lastError );
	}

	// Fallback to local cache if live introspection failed and a cached file exists
	if ( file_exists( $cacheFileName ) ) {
		touch( $cacheFileName );
		$cachedContent = file_get_contents( $cacheFileName );
		if ( is_string( $cachedContent ) && trim( $cachedContent ) !== '' ) {
			$json = json_decode( $cachedContent );
			if ( $json instanceof stdClass && isset( $json->data ) && $json->data instanceof stdClass && isset( $json->data->__type ) && $json->data->__type instanceof stdClass ) {
				$lastError  = null;
				$lastErrors = null;
				return $json->data->__type;
			}
			if ( $json instanceof stdClass && isset( $json->name ) ) {
				$lastError  = null;
				$lastErrors = null;
				return $json;
			}
		}
	}

	return null;
}

/**
 * Write log entry to log file.
 *
 * @param string $logLine Log message string.
 * @return void
 */
function writelog( string $logLine ): void {
	file_put_contents( __DIR__ . '/log.txt', $logLine . "\n", FILE_APPEND );
}
