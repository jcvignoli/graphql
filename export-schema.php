<?php

echo "Building schema.graphql from cached types...\n";

$cacheFiles = glob(__DIR__ . '/cache/*');

if (empty($cacheFiles)) {
    die("Error: No cached files found in cache/. Run the crawler first!\n");
}

$types = [];
foreach ($cacheFiles as $file) {
    if (!is_file($file)) {
        continue;
    }

    $json = json_decode(file_get_contents($file), true);

    $typeData = null;
    if (isset($json['data']['__type'])) {
        $typeData = $json['data']['__type'];
    } elseif (isset($json['name'])) {
        $typeData = $json;
    }

    if ($typeData && isset($typeData['name'])) {
        $types[$typeData['name']] = $typeData;
    }
}

if (empty($types)) {
    die("Error: No valid GraphQL types found in cache/.\n");
}

function formatTypeRef($typeRef)
{
    if (!$typeRef) {
        return 'String';
    }
    if ($typeRef['kind'] === 'NON_NULL') {
        return formatTypeRef($typeRef['ofType']) . '!';
    }
    if ($typeRef['kind'] === 'LIST') {
        return '[' . formatTypeRef($typeRef['ofType']) . ']';
    }
    return $typeRef['name'] ?? 'String';
}

function formatArgs($args)
{
    if (empty($args)) {
        return '';
    }
    $formatted = [];
    foreach ($args as $arg) {
        $str = $arg['name'] . ': ' . formatTypeRef($arg['type']);
        if (isset($arg['defaultValue']) && $arg['defaultValue'] !== null) {
            $str .= ' = ' . json_encode($arg['defaultValue']);
        }
        $formatted[] = $str;
    }
    return '(' . implode(', ', $formatted) . ')';
}

$builtinScalars = ['String', 'Int', 'Float', 'Boolean', 'ID'];
$sdl = [];

ksort($types);

foreach ($types as $name => $type) {
    if (strpos($name, '__') === 0) {
        continue;
    }

    $kind = $type['kind'] ?? '';
    $desc = !empty($type['description']) ? '"""' . "\n" . trim($type['description']) . "\n" . '"""' . "\n" : '';

    switch ($kind) {
        case 'SCALAR':
            if (!in_array($name, $builtinScalars)) {
                $sdl[] = "{$desc}scalar {$name}\n";
            }
            break;

        case 'ENUM':
            $values = [];
            if (!empty($type['enumValues'])) {
                foreach ($type['enumValues'] as $val) {
                    $valDesc = !empty($val['description']) ? '  """' . $val['description'] . '"""' . "\n" : '';
                    $values[] = "{$valDesc}  " . $val['name'];
                }
            }
            $valStr = implode("\n", $values);
            $sdl[] = "{$desc}enum {$name} {\n{$valStr}\n}\n";
            break;

        case 'INPUT_OBJECT':
            $fields = [];
            if (!empty($type['inputFields'])) {
                foreach ($type['inputFields'] as $field) {
                    $fDesc = !empty($field['description']) ? '  """' . $field['description'] . '"""' . "\n" : '';
                    $default = (isset($field['defaultValue']) && $field['defaultValue'] !== null) ? ' = ' . json_encode($field['defaultValue']) : '';
                    $fields[] = "{$fDesc}  " . $field['name'] . ': ' . formatTypeRef($field['type']) . $default;
                }
            }
            $fStr = implode("\n", $fields);
            $sdl[] = "{$desc}input {$name} {\n{$fStr}\n}\n";
            break;

        case 'INTERFACE':
            $fields = [];
            if (!empty($type['fields'])) {
                foreach ($type['fields'] as $field) {
                    $fDesc = !empty($field['description']) ? '  """' . $field['description'] . '"""' . "\n" : '';
                    $args = formatArgs($field['args'] ?? []);
                    $fields[] = "{$fDesc}  " . $field['name'] . "{$args}: " . formatTypeRef($field['type']);
                }
            }
            $fStr = implode("\n", $fields);
            $sdl[] = "{$desc}interface {$name} {\n{$fStr}\n}\n";
            break;

        case 'UNION':
            $possible = [];
            if (!empty($type['possibleTypes'])) {
                foreach ($type['possibleTypes'] as $p) {
                    if (!empty($p['name'])) {
                        $possible[] = $p['name'];
                    }
                }
            }
            $pStr = implode(' | ', $possible);
            $sdl[] = "{$desc}union {$name} = {$pStr}\n";
            break;

        case 'OBJECT':
            $interfaces = [];
            if (!empty($type['interfaces'])) {
                foreach ($type['interfaces'] as $iface) {
                    $ifaceName = $iface['name'] ?? ($iface['ofType']['name'] ?? null);
                    if ($ifaceName) {
                        $interfaces[] = $ifaceName;
                    }
                }
            }
            $implStr = !empty($interfaces) ? ' implements ' . implode(' & ', $interfaces) : '';

            $fields = [];
            if (!empty($type['fields'])) {
                foreach ($type['fields'] as $field) {
                    $fDesc = !empty($field['description']) ? '  """' . $field['description'] . '"""' . "\n" : '';
                    $args = formatArgs($field['args'] ?? []);
                    $fields[] = "{$fDesc}  " . $field['name'] . "{$args}: " . formatTypeRef($field['type']);
                }
            }
            $fStr = implode("\n", $fields);
            $sdl[] = "{$desc}type {$name}{$implStr} {\n{$fStr}\n}\n";
            break;
    }
}

$outputFile = __DIR__ . '/data/schema.graphql';
$fullSdl = implode("\n", $sdl);
file_put_contents($outputFile, $fullSdl);

echo "Success! Created schema.graphql (" . round(strlen($fullSdl) / 1024) . " KB) at:\n$outputFile\n";
