import fs from "fs";
import path from "path";
import { QUERY_SUITE } from "./scripts/queries";		// Check queries in /queries
import { existsSync } from 'node:fs';				// synchronous check
import { sendAlertEmail } from "./scripts/send-email";		// Sending emails script
import { styleText } from 'node:util';				// Change styles/colors in console.log()

const BASELINE_FILE = path.join(__dirname, "data/imdb-baseline-all.json");

function extractShape(data: unknown): unknown {
  if (data === null) return "null";
  if (Array.isArray(data)) {
    return data.length > 0 ? [extractShape(data[0])] : ["unknown"];
  }
  if (typeof data === "object") {
    const shape: Record<string, unknown> = {};
    for (const key of Object.keys(data)) {
      shape[key] = extractShape((data as Record<string, unknown>)[key]);
    }
    return shape;
  }
  return typeof data;
}

function findDiffs(baseline: unknown, current: unknown, path = ""): string[] {
  const diffs: string[] = [];
  if (typeof baseline !== typeof current) {
    return [`[Type Changed] At '${path || "root"}': was '${typeof baseline}', now '${typeof current}'`
];
  }
  if (typeof baseline === "object" && baseline !== null && current !== null) {
    const baseObj = baseline as Record<string, unknown>;
    const currObj = current as Record<string, unknown>;

    for (const key of Object.keys(baseObj)) {
      const fieldPath = path ? `${path}.${key}` : key;
      if (!(key in currObj)) {
        diffs.push(`[Missing Key] Field '${fieldPath}' removed from response`);
      } else {
        diffs.push(...findDiffs(baseObj[key], currObj[key], fieldPath));
      }
    }

    for (const key of Object.keys(currObj)) {
      if (!(key in baseObj)) {
        const fieldPath = path ? `${path}.${key}` : key;
        diffs.push(`[New Key Added] Field '${fieldPath}' added to response`);
      }
    }
  }
  return diffs;
}

async function runQuerySuite() {
  const currentSuiteShapes: Record<string, unknown> = {};
  let errorsEncountered = false;

  for (const item of QUERY_SUITE) {
    console.log(`🔍 Checking GraphQL operation: ${item.name}...`);

    try {
      const response = await fetch("https://graphql.imdb.com", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
          "x-imdb-client-name": "imdb-web-next-localized",
        },
        body: JSON.stringify({
          query: item.query,
          variables: item.variables,
        }),
      });

      if (!response.ok) {
        const errorText = await response.text();
        console.error(`  ❌ [HTTP Error ${response.status}] for ${item.name}`);
        console.error(`     Server says: ${errorText}`);
        errorsEncountered = true;
        continue;
      }

      const rawJson = (await response.json()) as { data?: unknown; errors?: Array<{ message: string }> };

      if (rawJson.errors) {
        console.error(`  ❌ [GraphQL Error] ${item.name}: ${rawJson.errors[0].message}`);
        errorsEncountered = true;
        continue;
      }

      if (rawJson.data) {
        currentSuiteShapes[item.name] = extractShape(rawJson.data);
      }
    } catch (err) {
      console.error(`  ❌ Execution error on ${item.name}:`, err instanceof Error ? err.message : err);
      errorsEncountered = true;
    }
  }

  // Abort saving baseline if any request failed to avoid saving partial data
  if (errorsEncountered && !fs.existsSync(BASELINE_FILE)) {
    console.error("⚠️ Errors occurred during initial run. Baseline file was not created.");
    return;
  }

  // 2. Save baseline on first run
  if (!fs.existsSync(BASELINE_FILE)) {
    fs.writeFileSync(BASELINE_FILE, JSON.stringify(currentSuiteShapes, null, 2));
    console.log(`\n📸 Baseline snapshot for ${QUERY_SUITE.length} queries saved to ${BASELINE_FILE}`);
    return;
  }

  // 3. Compare against stored baseline on subsequent runs
  const baselineSuiteShapes = JSON.parse(fs.readFileSync(BASELINE_FILE, "utf-8"));
  const allDiffs = findDiffs(baselineSuiteShapes, currentSuiteShapes);

  if (allDiffs.length > 0) {
    console.error(styleText(['redBright', 'bold'],"🚨 SCHEMA DRIFT DETECTED IN QUERY SUITE:"));
    allDiffs.forEach((d) => console.error(`  • ${d}`));

    // Send an email only if .env file exists.
    if (existsSync('.env')) {

       // Send email alert
       try {
         await sendAlertEmail(allDiffs);
       } catch (emailErr) {
         console.error(styleText(['redBright', 'bold'],"❌ Failed to send alert email:", emailErr.message));
       }
    }
    
    process.exit(1)
  } else {
    console.log(styleText(['greenBright', 'bold'],"✅ All queries in the suite match baseline shapes perfectly."));
  }
}

// Execute the test suite
runQuerySuite();
