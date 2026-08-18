# IMDb GraphQL Toolkit: Proxy, Crawler, SDL Exporter & Schema Drift Monitor

A comprehensive suite of tools designed to inspect, proxy, cache, export, and monitor the internal **IMDb GraphQL API**. 

Built for the [Lumière WordPress Plugin](https://wordpress.org/plugins/lumiere-movies/) project ecosystem, this toolkit overcomes [IMDb's GraphQL API](https://api.graphql.imdb.com/) principal restriction: no documentation is ever provided.

---

## 🚀 Key Capabilities

* **Introspection Proxying:** It recursively crawls API types and cache them locally, enabling to set up a local API replacement that will act as a proxy and will accelarate your calls to IMDb.
* **Schema output:** It can compiles a full Schema Definition Language (SDL) file (`schema.graphql` ).
* **Changes in you saved queries:** It continuously test live API responses against stored baseline shapes to detect breaking changes (saved GraphQL queries files into queries). Executes a production query suite against live IMDb endpoints, extracts primitive response shapes, compares them against a baseline snapshot (`data/imdb-baseline-all.json`), and fires email alerts when changes occur.

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

## 🔍 Proxy: Exploring the Schema & Building

Once the cache is populated, your local environment serves as a full-featured GraphQL gateway (Proxy).

### Proxy Live App Requests

Point [Lumière WordPress Plugin](https://wordpress.org/plugins/lumiere-movies/) directly to `index.php`. Schema introspection queries will be handled locally in milliseconds from `cache/`, while actual data operations automatically proxy to `https://api.graphql.imdb.com` with required headers, and make full use of this accelerated endpoint.

Connect also tools like **Postman**, **Insomnia**, or **Altair** directly to your proxy endpoint. Introspection responses are served instantly from disk cache, providing full documentation and query field autocomplete.

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
* Writes the compiled schema to `schema.graphql` in the project root.

---

## 📡 TypeScript Schema Drift Detection (`validate-schema.ts`)

The TypeScript drift monitor tests live production GraphQL queries against IMDb's API to ensure response structures remain consistent over time.

```bash
npx ts-node inspect.ts
```

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

Should you want to receive an automatised email if any query changed (cron job), create a .env with this completed values:

```
ALERT_RECIPIENT_EMAIL="reception@gmail.com"
SMTP_PORT=465
SECURE="true"
SMTP_HOST=smtp.gmail.com
SMTP_USER=noreply@gmail.com
SMTP_PASS="mysecurepassword"	
```

