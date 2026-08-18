<?php
/**
 * Adapted from Tom Boothman
 * https://github.com/tboothman/imdbphp/tree/master/graphql
 */
require_once 'vendor/autoload.php';

header( 'Content-Type: application/json' );

$body = file_get_contents( 'php://input' );

writelog( $body );

// Place it right here:
if (empty( trim( $body ) )) {
	$body = json_encode(
		[
			'query' => 'query { __schema { queryType { name } } }',
		]
	);
}

// Override request for schema with a custom response
if (strpos( $body, '__schema' ) !== false) {
	$responseBody = [
		'data' => [
			'__schema' => [
				'mutationType' => [
					'name' => 'Mutation',
				],
				'queryType' => typeQuery( 'Query' ),
				'types' => iterativelyFetchTypes( [ 'Query', 'Mutation' ] ),
			],
		],
	];

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

function graphqlRequest( $body ) {
	$client = new \GuzzleHttp\Client(
		[
			'timeout'  => 10.0, // Timeout after 10 seconds per request
		]
	);

	return $client->request(
		'POST',
		'https://api.graphql.imdb.com',
		[
			'body' => is_array( $body ) ? json_encode( $body ) : $body,
			'headers' => [
				'Content-Type' => 'application/json',
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
				'x-imdb-client-name' => 'imdb-web-next-localized',
			],
		]
	);
}

function iterativelyFetchTypes( array $seedTypes ) {
	$todo = $seedTypes;
	$done = [];
	$result = [];

	$addToQueue = function ( $newType ) use ( &$todo, &$done ) {
		if ( ! in_array( $newType, $done ) && ! in_array( $newType, $todo )) {
			$todo[] = $newType;
		}
	};

	while (count( $todo )) {
		$typeName = array_shift( $todo );
		$done[] = $typeName;
		$type = typeQuery( $typeName );

		$recurseTypeNames = function ( $src ) use ( $addToQueue ) {
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
 * @return \stdClass
 */
function typeQuery( $typeName ) {
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
        args {
          ...InputValue
        }
        type {
          ...TypeRef
        }
        isDeprecated
        deprecationReason
      }
      inputFields {
        ...InputValue
      }
      interfaces {
        ...TypeRef
      }
      enumValues(includeDeprecated: true) {
        name
        description
        isDeprecated
        deprecationReason
      }
      possibleTypes {
        ...TypeRef
      }
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
		'query' => $query,
		'variables' => [
			'type' => $typeName,
		],
	];

	if ( ! is_dir( 'cache' )) {
		mkdir( 'cache', 0777, true );
	}

	$forceRefresh = isset( $_GET['refresh'] );
	$cacheFileName = "cache/$typeName";

	if (file_exists( $cacheFileName ) && ! $forceRefresh) {
		writelog( "Reading $typeName from cache" );
		$json = json_decode( file_get_contents( $cacheFileName ) );
	} else {
		writelog( "Fetching type $typeName from api" );
		$res = graphqlRequest( json_encode( $request ) );
		$rawBody = (string) $res->getBody();
		file_put_contents( $cacheFileName, $rawBody );
		$json = json_decode( $rawBody );
	}

	return $json->data->__type;
}

function writelog( $logLine ) {
	file_put_contents( 'log.txt', $logLine . "\n", FILE_APPEND );
}
