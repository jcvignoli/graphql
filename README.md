# IMDb GraphQL Toolkit: Proxy, Crawler, SDL Exporter & Schema Drift Monitor

A comprehensive suite of tools designed to inspect, proxy, cache, export, and monitor the internal **IMDb GraphQL API**. 

Built for the `lumiere-movies` project ecosystem, this toolkit overcomes IMDb's GraphQL introspection restrictions by recursively crawling API types, caching them locally, compiling a full `schema.graphql` Schema Definition Language (SDL) file, and continuously testing live API responses against stored baseline shapes to detect breaking changes.

---

## 🚀 Key Capabilities

* **Introspection Proxying:** Intercepts standard GraphQL `__schema` queries and serves a dynamically assembled schema built from local type caches.
* **Recursive Type Crawler (`iterativelyFetchTypes`):** Implements a Breadth-First Search (BFS) crawler in PHP starting at root types (`Query`, `Mutation`) to discover all nested types, input objects, interfaces, and enums.
* **Disk Caching System:** Saves raw type definitions in `cache/` to eliminate runtime execution timeouts, minimize external HTTP calls, and enable offline schema browsing.
* **SDL Exporter:** Compiles individual type JSON files into a standalone, readable `schema.graphql` file without requiring strict introspection validation.
* **IDE Autocomplete & Tooling:** Connects seamlessly to GraphQL IDEs (Postman, Insomnia, Altair) and supports TypeScript SDK generation via `@graphql-codegen/cli`.
* **TypeScript Schema Drift Detection:** Executes a production query suite against live IMDb endpoints, extracts primitive response shapes, compares them against a baseline snapshot (`data/imdb-baseline-all.json`), and fires email alerts when changes occur.

---

## 📂 Project Structure

```text
.
├── cache/                       # Local JSON caches for individual fetched GraphQL types
├── data/
│   └── imdb-baseline-all.json   # Stored baseline snapshot of response shapes for the query suite
├── scripts/
│   ├── queries.ts               # Production GraphQL queries and variable definitions (QUERY_SUITE)
│   └── send-email.ts            # Email alert dispatcher (sendAlertEmail)
├── export-schema.php            # Standalone SDL generator script (creates schema.graphql)
├── index.php                    # PHP proxy server & recursive BFS crawler
├── validate-schema.ts           # TypeScript schema drift monitor & shape comparator
├── schema.graphql               # Exported standalone GraphQL SDL definition file
├── log.txt                      # Audit and proxy execution log
├── composer.json                # PHP dependencies (GuzzleHttp, etc.)
└── package.json                 # Node.js / TypeScript dependencies
```

---

## 🛠️ Installation & Setup

### System Requirements
* **PHP:** 7.4 or 8.x (with `curl` and `json` extensions enabled)
* **Node.js:** 18+ (for native `fetch` support in TypeScript)
* **Package Managers:** Composer & npm / pnpm

### 1. Install Dependencies
```bash
# Install PHP dependencies
composer install

# Install Node.js & TypeScript dependencies
npm install
```

### 2. Prepare Directory Permissions
Ensure local cache and data directories exist and are writable:
```bash
mkdir -p cache data
chmod 775 cache data
```

---

## 💻 PHP Proxy & Schema Crawler (`index.php`)

`index.php` operates as a smart proxy server. When an incoming request contains `__schema`, it pauses standard forwarding, executes `iterativelyFetchTypes()`, queries IMDb for missing types, writes raw JSON responses to `cache/`, and returns the assembled schema response.

### Option A: Command Line Interface (CLI - Recommended for Initial Crawl)
Crawling IMDb's full schema requires hundreds of sequential HTTP requests. Running it via terminal CLI bypasses PHP's web server `max_execution_time` limits:

```bash
php -r '
$_SERVER["REQUEST_METHOD"] = "POST";
$body = json_encode(["query" => "{ __schema { queryType { name } } }"]);
file_put_contents("php://input", $body);
require "index.php";
'
```

### Option B: Local HTTP Server Development
Start PHP's built-in web server to proxy live queries or connect external IDE tools:

```bash
php -S localhost:8000
```

Configure your GraphQL client endpoint:
* **URL:** `http://localhost:8000/index.php` (or `https://localhost/graphql/index.php`)
* **Method:** `POST`
* **Headers:** `Content-Type: application/json`

---

## 🔍 Exploring the Schema & Building `lumiere-movies`

Once the cache is populated, your local environment serves as a full-featured GraphQL gateway.

### 1. Explore in GraphQL IDEs
Connect tools like **Postman**, **Insomnia**, or **Altair** directly to your proxy endpoint. Introspection responses are served instantly from disk cache, providing full documentation and query field autocomplete.

### 2. Generate TypeScript Types & Client SDKs
Run GraphQL Code Generator (`@graphql-codegen/cli`) against your local proxy endpoint to automatically create strictly typed TypeScript interfaces for frontend consumption:
```bash
npx graphql-codegen --config codegen.yml
```

### 3. Build Precise Queries for `lumiere-movies`
Inspect exact IMDb data structures to query rich media and metadata without guesswork:
* **Posters & Backdrops:** Query high-resolution image URLs and aspect ratios.
* **Cast & Crew Filmographies:** Extract detailed credit lists, character names, and job roles.
* **Ratings & Reviews:** Access user ratings, review counts, and Metacritic scores.
* **Search & Discovery:** Filter titles by genres, plot summaries, release date ranges, and keywords.

### 4. Proxy Live App Requests
Point your `lumiere-movies` application frontend directly to `index.php`. Schema introspection queries will be handled locally in milliseconds from `cache/`, while actual data operations automatically proxy to `https://api.graphql.imdb.com` with required headers (`User-Agent`, `x-imdb-client-name`).

---

## 🔄 Cache Management & Updating

When IMDb updates its underlying API schema, update your local cache using any of the following approaches:

### Method A: Manual Reset
Clear the cache directory and re-run the CLI crawler:
```bash
rm -rf cache/*
```

### Method B: Force-Refresh Parameter
In `index.php`, `typeQuery()` can be configured to accept a URL flag (`?refresh=1`) to bypass disk reading:
```php
$forceRefresh = isset($_GET['refresh']);
if (file_exists($cacheFileName) && !$forceRefresh) {
    // Read cached JSON file
} else {
    // Fetch fresh copy from IMDb API and overwrite cache
}
```

### Method C: Automatic Time-To-Live (TTL)
Enforce cache expiration (e.g., 7 days) within `typeQuery()`:
```php
$maxAge = 7 * 86400; // 7 days in seconds
if (file_exists($cacheFileName) && (time() - filemtime($cacheFileName) < $maxAge)) {
    // Use cached definition
} else {
    // Re-fetch from IMDb API
}
```

---

## 📜 Exporting a Standalone `schema.graphql` (SDL)

Convert all cached JSON type files into a clean, human-readable GraphQL Schema Definition Language (SDL) file:

```bash
php export-schema.php
```

### Exporter Features (`export-schema.php`):
* Iterates through all files in `cache/`.
* Unwraps nested `data.__type` objects.
* Formats fields, arguments, scalars, enums, input objects, interfaces, and union types into standard GraphQL SDL syntax.
* Writes the compiled schema to `schema.graphql` in the project root.

---

## 📡 TypeScript Schema Drift Detection (`validate-schema.ts`)

The TypeScript drift monitor tests live production GraphQL queries against IMDb's API to ensure response structures remain consistent over time.

### How It Works

1. **Shape Extraction (`extractShape`):**
   Recursively converts actual API JSON response data into primitive shape mappings (e.g., `{ title: "string", ratings: { score: "number" }, cast: ["string"] }`).
2. **Baseline Creation (`data/imdb-baseline-all.json`):**
   On its initial run, the script saves all extracted query shapes to a baseline JSON snapshot file.
3. **Structural Comparison (`findDiffs`):**
   On subsequent runs, it compares new live response shapes against the baseline to flag changes:
   * **`[Missing Key]`:** A field was removed or renamed by IMDb.
   * **`[New Key Added]`:** A new field was added to the response object.
   * **`[Type Changed]`:** A field's primitive type changed (e.g., `string` to `number` or `null`).
4. **Alerting & Failure Handling:**
   If drift is detected, the script outputs formatted error logs to `stderr`, sends an automated notification email via `sendAlertEmail()`, and exits with process code `1` (useful for CI/CD pipeline failures).

### Running the Test Suite
```bash
npx ts-node inspect.ts
```

