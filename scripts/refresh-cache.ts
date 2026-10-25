import fs from 'fs';
import path from 'path';

// Parse .env file manually if exists to avoid external dependencies
function loadEnv(): void {
  const envPath = path.resolve(process.cwd(), '.env');
  if (fs.existsSync(envPath)) {
    const content = fs.readFileSync(envPath, 'utf-8');
    for (const line of content.split('\n')) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) continue;
      const eqIdx = trimmed.indexOf('=');
      if (eqIdx > -1) {
        const key = trimmed.substring(0, eqIdx).trim();
        let val = trimmed.substring(eqIdx + 1).trim();
        if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
          val = val.substring(1, val.length - 1);
        }
        if (!process.env[key]) {
          process.env[key] = val;
        }
      }
    }
  }
}

loadEnv();

const CACHE_DIR = path.resolve(process.cwd(), 'web', 'cache');
const GRAPHQL_ENDPOINT = 'https://api.graphql.imdb.com';

const TYPE_QUERY = `
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
`;

function getHeaders(): Record<string, string> {
  const headers: Record<string, string> = {
    'content-type': 'application/json',
    'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    'x-imdb-client-name': 'imdb-web-next-localized',
  };

  const token = process.env.IMDB_AUTH_TOKEN;
  if (token && token.trim() !== '') {
    headers['authorization'] = token;
  }

  return headers;
}

interface GraphQLTypeRef {
  kind?: string;
  name?: string | null;
  ofType?: GraphQLTypeRef | null;
}

interface GraphQLInputValue {
  type?: GraphQLTypeRef | null;
}

interface GraphQLField {
  type?: GraphQLTypeRef | null;
  args?: GraphQLInputValue[] | null;
}

interface GraphQLType {
  kind?: string;
  name?: string | null;
  fields?: GraphQLField[] | null;
  inputFields?: GraphQLInputValue[] | null;
  interfaces?: GraphQLTypeRef[] | null;
  possibleTypes?: GraphQLTypeRef[] | null;
}

function getCachedType(typeName: string): GraphQLType | null {
  const cachePath = path.join(CACHE_DIR, typeName);
  if (fs.existsSync(cachePath)) {
    try {
      const content = fs.readFileSync(cachePath, 'utf-8');
      const json = JSON.parse(content);
      if (json && json.data && json.data.__type && json.data.__type.name) {
        return json.data.__type as GraphQLType;
      }
      if (json && json.name) {
        return json as GraphQLType;
      }
    } catch {
      // Ignore parse failure on cached file
    }
  }
  return null;
}

async function fetchType(typeName: string): Promise<GraphQLType | null> {
  const payload = {
    operationName: 'Type',
    query: TYPE_QUERY,
    variables: { type: typeName },
  };

  try {
    const res = await fetch(GRAPHQL_ENDPOINT, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify(payload),
    });

    const rawBody = await res.text();
    let json: { data?: { __type?: GraphQLType }; errors?: Array<{ message: string }> } | null = null;
    try {
      json = JSON.parse(rawBody);
    } catch {
      // Body was not JSON
    }

    if (res.ok && json && json.data && json.data.__type && json.data.__type.name) {
      if (!fs.existsSync(CACHE_DIR)) {
        fs.mkdirSync(CACHE_DIR, { recursive: true });
      }
      const cachePath = path.join(CACHE_DIR, typeName);
      fs.writeFileSync(cachePath, rawBody, 'utf-8');
      return json.data.__type;
    } else if (json && json.errors) {
      const errMsg = json.errors[0]?.message || 'Unknown GraphQL error';
      console.warn(`⚠️ Upstream IMDb API returned error for type "${typeName}": ${errMsg}`);
    } else {
      console.warn(`⚠️ HTTP status ${res.status} when fetching type "${typeName}" from IMDb API.`);
    }
  } catch (err) {
    console.warn(`⚠️ Failed to connect to IMDb API for type "${typeName}":`, err instanceof Error ? err.message : err);
  }

  const cached = getCachedType(typeName);
  if (cached) {
    console.log(`ℹ️ Using local cached fallback for type "${typeName}".`);
    return cached;
  }

  return null;
}

function extractTypeNames(typeObj: GraphQLType): string[] {
  const found = new Set<string>();

  const recurseTypeRef = (ref: GraphQLTypeRef | null | undefined) => {
    if (!ref) return;
    if (ref.name && typeof ref.name === 'string') {
      found.add(ref.name);
    }
    if (ref.ofType) {
      recurseTypeRef(ref.ofType);
    }
  };

  if (typeObj.fields && Array.isArray(typeObj.fields)) {
    for (const field of typeObj.fields) {
      recurseTypeRef(field.type);
      if (field.args && Array.isArray(field.args)) {
        for (const arg of field.args) {
          recurseTypeRef(arg.type);
        }
      }
    }
  }

  if (typeObj.interfaces && Array.isArray(typeObj.interfaces)) {
    for (const iface of typeObj.interfaces) {
      recurseTypeRef(iface);
    }
  }

  if (typeObj.inputFields && Array.isArray(typeObj.inputFields)) {
    for (const inputField of typeObj.inputFields) {
      recurseTypeRef(inputField.type);
    }
  }

  if (typeObj.possibleTypes && Array.isArray(typeObj.possibleTypes)) {
    for (const possibleType of typeObj.possibleTypes) {
      recurseTypeRef(possibleType);
    }
  }

  return Array.from(found);
}

async function refreshSingleType(typeName: string): Promise<void> {
  console.log(`Refreshing type "${typeName}" directly from ${GRAPHQL_ENDPOINT}...`);
  const result = await fetchType(typeName);
  if (result) {
    console.log(`Successfully processed type "${typeName}".`);
  } else {
    console.error(`Failed to refresh or retrieve type "${typeName}".`);
    process.exitCode = 1;
  }
}

function listExistingCacheTypes(): string[] {
  if (!fs.existsSync(CACHE_DIR)) return [];
  const files = fs.readdirSync(CACHE_DIR);
  return files.filter(
    (f) => f !== '.' && f !== '..' && f !== '.placeholder' && fs.statSync(path.join(CACHE_DIR, f)).isFile()
  );
}

async function crawlFullSchema(seedTypes: string[] = ['Query', 'Mutation']): Promise<void> {
  if (!process.env.IMDB_AUTH_TOKEN) {
    console.warn('⚠️ IMDB_AUTH_TOKEN is not set in environment or .env file.');
    console.warn('   IMDb API requires an authorized token (IMDB_AUTH_TOKEN) for live GraphQL introspection.');
    console.warn('   To enable live schema fetching from IMDb, set IMDB_AUTH_TOKEN="Bearer <your_token>" in .env.');
    console.warn('   Traversing local cached files as fallback...\n');
  }

  console.log(`Starting schema crawl from IMDb API (${GRAPHQL_ENDPOINT})...`);
  let todo: string[] = [...seedTypes];

  // If local cache exists, combine existing cached files to seed queue if initial seed fails
  const existingCached = listExistingCacheTypes();
  for (const cType of existingCached) {
    if (!todo.includes(cType)) {
      todo.push(cType);
    }
  }

  const done = new Set<string>();
  let count = 0;

  while (todo.length > 0) {
    const currentType = todo.shift()!;
    if (done.has(currentType)) continue;

    done.add(currentType);
    console.log(`[${done.size}] Processing type: ${currentType}...`);

    const typeObj = await fetchType(currentType);
    if (!typeObj) {
      console.warn(`Skipping referencing types for "${currentType}" (not available in live API or local cache).`);
      continue;
    }

    count++;
    const nextTypes = extractTypeNames(typeObj);
    for (const nextType of nextTypes) {
      if (!done.has(nextType) && !todo.includes(nextType)) {
        todo.push(nextType);
      }
    }
  }

  console.log(`\nCrawl completed! Total types processed: ${count}`);
}

async function main() {
  const args = process.argv.slice(2);
  let targetType: string | null = null;

  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--type' || args[i] === '-t') {
      targetType = args[i + 1] || null;
      i++;
    } else if (!args[i].startsWith('-')) {
      targetType = args[i];
    }
  }

  if (targetType) {
    await refreshSingleType(targetType);
  } else {
    await crawlFullSchema();
  }
}

main().catch((err) => {
  console.error('Fatal error in refresh-cache script:', err);
  process.exit(1);
});
