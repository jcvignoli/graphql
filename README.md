# IMDb GraphQL Toolkit: Proxy, Crawler, SDL Exporter & Schema Drift Monitor

A comprehensive suite of tools designed to inspect, proxy, cache, export, and monitor the internal **IMDb GraphQL API**. 

Built for the [Lumière WordPress Plugin](https://wordpress.org/plugins/lumiere-movies/) project ecosystem, this toolkit overcomes [IMDb's GraphQL API](https://api.graphql.imdb.com/) principal restriction: no documentation is ever provided.

---

## 🚀 Key Capabilities

* **Introspection Proxying:** It recursively crawls API types and caches them locally, enabling a local API proxy that accelerates your calls to IMDb.
* **Schema Output:** Compiles a full Schema Definition Language (SDL) file (`data/schema.graphql`).
* **Schema Drift Monitoring:** Continuously tests live API responses against stored baseline shapes (defined in `queries/`) to detect breaking changes (`inspect.ts`). Executes a production query suite against live IMDb endpoints, extracts primitive response shapes, compares them against a baseline snapshot (`data/imdb-baseline-all.json`), and fires email alerts when changes occur.

---

## 📂 Project Structure

```text
.
├── cache/                       # Local JSON caches for individual fetched GraphQL types
├── data/
   ├── imdb-baseline-all.json   # Stored baseline snapshot of response shapes for the query suite
│   └── schema.graphql           # Exported standalone GraphQL SDL definition file
├── queries/                     # Production GraphQL query files (.graphql) and variable payloads (.json)
├── scripts/
│   ├── queries.ts               # Loader for GraphQL queries and variable definitions (QUERY_SUITE)
│   └── send-email.ts            # Email alert dispatcher (sendAlertEmail)
├── src/                         # PHP Classes (Env loading helper, etc.)
├── .env-dist                    # Sample environment variables configuration template
├── export-schema.php            # Standalone SDL generator script (creates data/schema.graphql)
├── inspect.ts                   # TypeScript schema drift monitor & shape comparator
├── q.php                        # cURL test helper script for local proxy queries
├── log.txt                      # Audit and proxy execution log
├── composer.json                # PHP dependencies (GuzzleHttp, Dotenv, etc.)
└── package.json                 # Node.js / TypeScript dependencies
```

---

## 💻 PHP Proxy & Schema Crawler (`index.php`)

`index.php` operates as a smart proxy server. When an incoming request contains `__schema` (or an empty body), it executes `iterativelyFetchTypes()`, queries IMDb for missing types, writes raw JSON responses to `cache/`, and returns the assembled schema response.

### Option A: Command Line Interface (CLI - Recommended for Initial Crawl)
Crawling IMDb's full schema requires hundreds of sequential HTTP requests. Running it via terminal CLI bypasses PHP's web server `max_execution_time` limits:

```bash
php -r '
$_SERVER["REQUEST_METHOD"] = "POST";
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

## 🔍 Proxy: Endpoint Query Parameters & Authentication

Once the cache is populated, your local environment serves as a full-featured GraphQL gateway (Proxy).

### Available Query Parameters

* **`GET ?list_types=1`**
  Returns a JSON array listing all cached type filenames in `cache/`, sorted ascending by file modification timestamp (`filemtime`, oldest first).
  ```json
  {"status": "ok", "types": ["Query", "Title", ...]}
  ```
* **`GET ?type=TypeName&refresh=1`**
  Bypasses local cache for a specific GraphQL type (`TypeName`), fetches the latest type definition directly from IMDb's GraphQL API, overwrites `cache/TypeName`, and returns the updated JSON payload.
  ```json
  {"status": "ok", "type": "TypeName", "data": {...}}
  ```
* **`GET ?refresh=1`**
  Forces a fresh fetch when querying schema introspection, writing raw body responses directly to `cache/$typeName`.

### Authentication (`IMDB_AUTH_TOKEN`)

If IMDb requires authorization headers for specific endpoints or queries, define `IMDB_AUTH_TOKEN` in your `.env` file or environment variables:

```env
IMDB_AUTH_TOKEN="Bearer your_imdb_token_here"
```

When configured, `index.php` automatically appends this `Authorization` header to upstream requests forwarded to `https://api.graphql.imdb.com` if not already provided by the client.

### Proxy Live App Requests

Point [Lumière WordPress Plugin](https://wordpress.org/plugins/lumiere-movies/) directly to `index.php`. Schema introspection queries will be handled locally in milliseconds from `cache/`, while actual data operations automatically proxy to `https://api.graphql.imdb.com` with required headers.

You can also test queries using the included `q.php` helper script by setting `LOCAL_HOST` in `.env` (e.g., `LOCAL_HOST="http://localhost:8000/index.php"`).

---

## 🔄 Cache Management & Updating

When IMDb updates its underlying API schema, update your local cache using any of the following approaches:

### Method A: Manual Reset
Clear the cache directory and re-run the CLI crawler:
```bash
rm -rf cache/*
php -r '$_SERVER["REQUEST_METHOD"] = "POST"; require "index.php";'
```

### Method B: Single Type Refresh Parameter
Refresh a specific type on demand:
```bash
curl "http://localhost:8000/index.php?type=Title&refresh=1"
```

---

## 📜 Export a Standalone `data/schema.graphql` (SDL)

Convert all cached JSON type files into a clean, human-readable GraphQL Schema Definition Language (SDL) file:

```bash
php export-schema.php
```

### Exporter Features (`export-schema.php`):
* Iterates through all files in `cache/`.
* Unwraps nested `data.__type` objects.
* Formats fields, arguments, scalars, enums, input objects, interfaces, and union types into standard GraphQL SDL syntax.
* Writes the compiled schema to `data/schema.graphql`.

---

## 📡 TypeScript Schema Drift Detection (`inspect.ts`)

The TypeScript drift monitor tests live production GraphQL queries against IMDb's API (`https://api.graphql.imdb.com`) to ensure response structures remain consistent over time.

```bash
npm start
# or
npx tsx inspect.ts
```

### How It Works

1. **Query Suite Scan (`queries/`):**
   Scans `queries/` for all `.graphql` query files and matching `.json` variable files.
2. **Shape Extraction (`extractShape`):**
   Recursively converts actual API JSON response data into primitive shape mappings (e.g., `{ title: "string", ratings: { score: "number" }, cast: ["string"] }`).
3. **Baseline Creation (`data/imdb-baseline-all.json`):**
   On its initial run, the script saves all extracted query shapes to a baseline JSON snapshot file.
4. **Structural Comparison (`findDiffs`):**
   On subsequent runs, it compares new live response shapes against the baseline to flag changes:
   * **`[Missing Key]`:** A field was removed or renamed by IMDb.
   * **`[New Key Added]`:** A new field was added to the response object.
   * **`[Type Changed]`:** A field's primitive type changed (e.g., `string` to `number` or `null`).
5. **Alerting & Failure Handling:**
   If drift is detected, the script outputs formatted error logs to `stderr`, sends an automated notification email via `sendAlertEmail()` (if `.env` exists), and exits with process code `1` (useful for CI/CD pipeline failures).

To configure email alerts for schema changes, create a `.env` file based on `.env-dist`:

```env
ALERT_RECIPIENT_EMAIL="reception@gmail.com"
SMTP_PORT=465
SECURE="true"
SMTP_HOST=smtp.gmail.com
SMTP_USER=noreply@gmail.com
SMTP_PASS="mysecurepassword"
LOCAL_HOST="http://localhost:8000/index.php"
IMDB_AUTH_TOKEN=""
```

