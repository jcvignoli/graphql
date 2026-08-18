<?php

$endpoint = 'https://local.lumiere/graphql/';

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

$variables = [
    'id' => 'tt0120737'
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: application/json',
        'x-imdb-client-name: imdb-web-next-localized',
    ],
    CURLOPT_POSTFIELDS     => json_encode([
        'query'     => $query,
        'variables' => $variables,
    ]),
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);

$data = json_decode($response, true);

echo "<pre>";
print_r($data);
echo "</pre>";
