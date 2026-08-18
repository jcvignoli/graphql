import fs from "fs";
import path from "path";

export interface QueryItem {
  name: string;
  query: string;
  variables: Record<string, unknown>;
}

const QUERIES_DIR = path.join(__dirname, "..", "queries");

/**
 * Automatically scans the /queries folder for all .graphql files
 * and loads corresponding .json files for variables if they exist.
 */
export const QUERY_SUITE: QueryItem[] = fs
  .readdirSync(QUERIES_DIR)
  .filter((file) => file.endsWith(".graphql"))
  .map((file) => {
    const name = path.basename(file, ".graphql");
    const queryPath = path.join(QUERIES_DIR, file);
    const varPath = path.join(QUERIES_DIR, `${name}.json`);

    // Read the GraphQL file
    const query = fs.readFileSync(queryPath, "utf-8");

    // Read optional matching .json file for variables
    let variables: Record<string, unknown> = {};
    if (fs.existsSync(varPath)) {
      try {
        variables = JSON.parse(fs.readFileSync(varPath, "utf-8"));
      } catch (error) {
        console.warn(`⚠️ Warning: Failed to parse variables for ${name}.json`);
      }
    }

    return { name, query, variables };
  });
