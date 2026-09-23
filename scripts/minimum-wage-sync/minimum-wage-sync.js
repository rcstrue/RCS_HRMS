/**
 * RCS HRMS — Minimum Wage Sync from Simpliance
 * 
 * Fetches state-wise minimum wage data from Simpliance.in
 * and inserts into the existing `minimum_wages` table.
 * 
 * DB config: reads from config.local.php (same credentials as HRMS).
 * No environment variables needed.
 * 
 * Usage:
 *   node minimum-wage-sync.js              # Sync all operating states
 *   node minimum-wage-sync.js gujarat       # Sync single state
 *   node minimum-wage-sync.js --dry-run     # Preview without inserting
 *   node minimum-wage-sync.js --add-slug    # Auto-add slug column + populate slugs
 */

const axios = require('axios');
const cheerio = require('cheerio');
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');

// ── Read DB Config from PHP config.local.php ─────────────────────────
function readDbConfig() {
    // Walk up from this script to find config.local.php
    const scriptDir = __dirname;
    const phpPayrollRoot = path.resolve(scriptDir, '../..');
    const configLocalPath = path.join(phpPayrollRoot, 'config', 'config.local.php');

    if (!fs.existsSync(configLocalPath)) {
        console.error(`ERROR: config.local.php not found at ${configLocalPath}`);
        console.error('The script expects the same DB credentials used by HRMS.');
        process.exit(1);
    }

    const content = fs.readFileSync(configLocalPath, 'utf8');

    const getDefine = (key) => {
        const m = content.match(new RegExp(`define\\s*\\(\\s*['"]${key}['"]\\s*,\\s*['"](.+?)['"]\\s*\\)`));
        return m ? m[1] : null;
    };

    const host = getDefine('DB_HOST') || 'localhost';
    const name = getDefine('DB_NAME');
    const user = getDefine('DB_USER');
    const pass = getDefine('DB_PASS');

    if (!name || !user) {
        console.error('ERROR: Could not parse DB_NAME or DB_USER from config.local.php');
        process.exit(1);
    }

    // Remove port from host if present (mysql2 handles port separately)
    const hostClean = host.replace(/:\d+$/, '');

    return { host: hostClean, user, password: pass || '', database: name };
}

const BASE_URL = 'https://www.simpliance.in/India/LEI/minimum_wages/state-wise-details';

// Simpliance state name → URL slug mapping
const STATE_SLUG_MAP = {
    'andaman and nicobar islands': 'andaman-and-nicobar-islands',
    'andhra pradesh': 'andhra-pradesh',
    'arunachal pradesh': 'arunachal-pradesh',
    'assam': 'assam',
    'bihar': 'bihar',
    'chandigarh': 'chandigarh',
    'chhattisgarh': 'chhattisgarh',
    'dadra and nagar haveli': 'dadra-and-nagar-haveli',
    'daman and diu': 'daman-and-diu',
    'delhi': 'delhi',
    'goa': 'goa',
    'gujarat': 'gujarat',
    'haryana': 'haryana',
    'himachal pradesh': 'himachal-pradesh',
    'jammu and kashmir': 'jammu-and-kashmir',
    'jharkhand': 'jharkhand',
    'karnataka': 'karnataka',
    'kerala': 'kerala',
    'lakshadweep': 'lakshadweep',
    'madhya pradesh': 'madhya-pradesh',
    'maharashtra': 'maharashtra',
    'manipur': 'manipur',
    'meghalaya': 'meghalaya',
    'mizoram': 'mizoram',
    'nagaland': 'nagaland',
    'odisha': 'odisha',
    'puducherry': 'puducherry',
    'punjab': 'punjab',
    'rajasthan': 'rajasthan',
    'sikkim': 'sikkim',
    'tamil nadu': 'tamil-nadu',
    'telangana': 'telangana',
    'tripura': 'tripura',
    'uttar pradesh': 'uttar-pradesh',
    'uttarakhand': 'uttarakhand',
    'west bengal': 'west-bengal',
    'jammu & kashmir': 'jammu-and-kashmir',
    'orissa': 'odisha',
    'pondicherry': 'puducherry',
    'damen and diu': 'daman-and-diu',
    'chattisgarh': 'chhattisgarh',
};

// Simpliance category → HRMS worker_category mapping
const CATEGORY_MAP = {
    'unskilled': 'Unskilled',
    'semi-skilled': 'Semi-Skilled',
    'semi skilled': 'Semi-Skilled',
    'skilled': 'Skilled',
    'highly skilled': 'Skilled',
    'highly-skilled': 'Skilled',
    'supervisor': 'Supervisor',
    'clerical': 'Supervisor',
    'watchmen': 'Unskilled',
    'sweeper': 'Unskilled',
};

// ── Helpers ────────────────────────────────────────────────────────────
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function parseDate(txt) {
    if (!txt) return null;
    const cleaned = txt.replace(/(\d+)(st|nd|rd|th)/i, '$1').trim();
    const d = new Date(cleaned);
    if (isNaN(d.getTime())) return null;
    return d.toISOString().slice(0, 10);
}

function mapCategory(raw) {
    const lower = (raw || '').toLowerCase().trim();
    return CATEGORY_MAP[lower] || raw;
}

function parseAmount(str) {
    if (!str) return 0;
    const num = parseFloat(String(str).replace(/[₹,\s]/g, ''));
    return isNaN(num) ? 0 : Math.round(num * 100) / 100;
}

function slugifyState(stateName) {
    return STATE_SLUG_MAP[stateName.toLowerCase().trim()] || null;
}

// ── Ensure slug column exists and populate it ─────────────────────────
async function ensureSlugs(pool) {
    // Check if slug column exists
    const [cols] = await pool.execute(
        `SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'states' AND COLUMN_NAME = 'slug'`
    );

    if (cols.length === 0) {
        console.log('  Adding slug column to states table...');
        await pool.execute(`ALTER TABLE states ADD COLUMN slug VARCHAR(100) DEFAULT NULL AFTER state_name`);
        console.log('  slug column added.');
    }

    // Find states without slugs and try to auto-fill
    const [rows] = await pool.execute(
        `SELECT id, state_name, slug FROM states WHERE is_active = 1 AND (slug IS NULL OR slug = '')`
    );

    if (rows.length > 0) {
        console.log(`\n  Auto-populating slugs for ${rows.length} state(s)...`);
        let updated = 0;
        for (const row of rows) {
            const slug = slugifyState(row.state_name);
            if (slug) {
                await pool.execute(`UPDATE states SET slug = ? WHERE id = ?`, [slug, row.id]);
                console.log(`    ${row.state_name} → ${slug}`);
                updated++;
            } else {
                console.log(`    ${row.state_name} → (no mapping, skipped)`);
            }
        }
        console.log(`  Slugs populated: ${updated}/${rows.length}`);
    }

    return true;
}

// ── Get operating states from DB ───────────────────────────────────────
async function getOperatingStates(pool) {
    const [rows] = await pool.execute(
        `SELECT id, state_name, slug FROM states WHERE is_active = 1 ORDER BY state_name`
    );
    // Auto-assign slug from map if missing
    return rows.map(s => {
        if (!s.slug) {
            s.slug = slugifyState(s.state_name);
        }
        return s;
    }).filter(s => s.slug);
}

// ── Fetch single state from Simpliance ────────────────────────────────
async function fetchState(pool, state, dryRun = false) {
    const url = `${BASE_URL}/${state.slug}`;
    let added = 0, skipped = 0;
    let errorMessage = null;

    console.log(`  Fetching ${state.state_name} (${state.slug})...`);

    try {
        // Get the page to find version dropdown
        const { data: html } = await axios.get(url, {
            timeout: 30000,
            headers: { 'User-Agent': 'Mozilla/5.0 (compatible; RCS-HRMS-Sync/1.0)' }
        });

        const $ = cheerio.load(html);

        // Collect versions from dropdown
        const versions = [];
        $('#version option').each(function () {
            const val = $(this).attr('value');
            if (val) {
                versions.push({
                    id: val,
                    text: $(this).text().trim()
                });
            }
        });

        if (versions.length === 0) {
            console.log(`    No versions found for ${state.slug}`);
            return { state: state.state_name, state_id: state.id, status: 'error', records_added: 0, records_skipped: 0, error_message: 'No versions found on page' };
        }

        // Use latest version
        const latest = versions[0];
        console.log(`    Version: ${latest.text} (id=${latest.id})`);

        // Fetch the latest version page
        await sleep(1000);
        const { data: latestHTML } = await axios.get(`${url}?version=${latest.id}`, {
            timeout: 30000,
            headers: { 'User-Agent': 'Mozilla/5.0 (compatible; RCS-HRMS-Sync/1.0)' }
        });

        const doc = cheerio.load(latestHTML);

        // Get effective date
        const effectiveDate = parseDate(
            doc('.updated-date strong').first().text().trim()
        );
        console.log(`    Effective: ${effectiveDate}`);

        if (!effectiveDate) {
            return { state: state.state_name, state_id: state.id, status: 'error', records_added: 0, records_skipped: 0, error_message: 'Could not parse effective date' };
        }

        // Parse wage rows
        const rows = [];
        doc('#wagesTable tbody tr').each(function () {
            const tds = doc(this).find('td').map(function () {
                return doc(this).text().trim();
            }).get();

            if (tds.length >= 5) {
                rows.push({
                    category: tds[0],
                    zone: tds[1],
                    basic_per_day: parseAmount(tds[2]),
                    vda_per_day: tds.length >= 4 ? parseAmount(tds[3]) : 0,
                    total_per_day: tds.length >= 5 ? parseAmount(tds[4]) : parseAmount(tds[2]),
                    total_per_month: tds.length >= 6 ? parseAmount(tds[5]) : parseAmount(tds[4]) * 26,
                });
            }
        });

        console.log(`    Parsed ${rows.length} wage rows`);

        // Insert into DB
        for (const row of rows) {
            const workerCategory = mapCategory(row.category);
            const basicPerMonth = Math.round(row.basic_per_day * 26 * 100) / 100;
            const daPerMonth = Math.round(row.vda_per_day * 26 * 100) / 100;

            // Check for duplicate
            const [existing] = await pool.execute(
                `SELECT id FROM minimum_wages
                 WHERE state_id = ? AND worker_category = ? AND effective_from = ?
                 LIMIT 1`,
                [state.id, workerCategory, effectiveDate]
            );

            if (existing.length > 0) {
                skipped++;
                continue;
            }

            if (!dryRun) {
                await pool.execute(
                    `INSERT INTO minimum_wages
                     (state_id, zone_id, worker_category, basic_per_day, basic_per_month,
                      da_per_day, da_per_month, total_per_day, total_per_month,
                      effective_from, source, version_id, is_active, created_by)
                     VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'Simpliance', ?, 1, 'system')`,
                    [
                        state.id,
                        workerCategory,
                        row.basic_per_day,
                        basicPerMonth,
                        row.vda_per_day,
                        daPerMonth,
                        row.total_per_day,
                        row.total_per_month,
                        effectiveDate,
                        latest.id
                    ]
                );
            }

            added++;
        }

        const status = (rows.length > 0 && added === 0) ? 'partial' : 'success';

        return {
            state: state.state_name,
            state_id: state.id,
            status,
            records_added: added,
            records_skipped: skipped,
            error_message: null
        };

    } catch (err) {
        errorMessage = err.message?.substring(0, 500);
        console.log(`    Error: ${errorMessage}`);

        return {
            state: state.state_name,
            state_id: state.id,
            status: 'error',
            records_added: added,
            records_skipped: skipped,
            error_message: errorMessage
        };
    }
}

// ── Main ──────────────────────────────────────────────────────────────
async function main() {
    const args = process.argv.slice(2);
    const dryRun = args.includes('--dry-run');
    const addSlug = args.includes('--add-slug');
    const stateFilter = args.find(a => !a.startsWith('--'));

    console.log('=======================================');
    console.log('  RCS HRMS - Minimum Wage Sync');
    console.log(`  Mode: ${dryRun ? 'DRY RUN (no inserts)' : 'LIVE'}`);
    console.log(`  Time: ${new Date().toISOString()}`);
    console.log('=======================================');

    // Read DB config from PHP config
    const dbConfig = readDbConfig();
    console.log(`  DB: ${dbConfig.database}@${dbConfig.host}`);

    const pool = mysql.createPool({
        host: dbConfig.host,
        user: dbConfig.user,
        password: dbConfig.password,
        database: dbConfig.database,
        waitForConnections: true,
        connectionLimit: 5,
        charset: 'utf8mb4'
    });

    // If --add-slug flag, just ensure slug column and populate, then exit
    if (addSlug) {
        await ensureSlugs(pool);
        await pool.end();
        console.log('\nDone. Slug setup complete.');
        return;
    }

    // Ensure slug column exists before syncing
    await ensureSlugs(pool);

    let states;

    if (stateFilter) {
        // Single state by slug or name
        const [rows] = await pool.execute(
            `SELECT id, state_name, slug FROM states WHERE (slug = ? OR state_name LIKE ?) AND is_active = 1`,
            [stateFilter, `%${stateFilter}%`]
        );
        states = rows.map(s => {
            if (!s.slug) s.slug = slugifyState(s.state_name);
            return s;
        }).filter(s => s.slug);
    } else {
        states = await getOperatingStates(pool);
    }

    if (states.length === 0) {
        console.log('\nNo states found with slugs. The script tried auto-populating from the built-in map.');
        console.log('If your state names differ, run: node minimum-wage-sync.js --add-slug');
        console.log('Or manually set slugs in phpMyAdmin:');
        console.log("  UPDATE states SET slug = 'gujarat' WHERE state_name = 'Gujarat';");
        await pool.end();
        process.exit(1);
    }

    console.log(`\nSyncing ${states.length} state(s)...\n`);

    const results = [];

    for (const state of states) {
        const result = await fetchState(pool, state, dryRun);
        results.push(result);

        // Log to DB
        if (!dryRun) {
            try {
                await pool.execute(
                    `INSERT INTO minimum_wage_sync_log (state, state_id, status, records_added, records_skipped, error_message)
                     VALUES (?, ?, ?, ?, ?, ?)`,
                    [result.state, result.state_id, result.status, result.records_added, result.records_skipped, result.error_message]
                );
            } catch (e) {
                // Log table might not exist yet — ignore
                console.log(`    (sync log table missing, skipping log)`);
            }
        }

        // Rate limiting
        await sleep(2000);
    }

    // Summary
    console.log('\n=======================================');
    console.log('  SYNC SUMMARY');
    console.log('=======================================');

    let totalAdded = 0, totalSkipped = 0;
    for (const r of results) {
        const icon = r.status === 'success' ? 'OK' : r.status === 'partial' ? '~' : 'X';
        console.log(`  [${icon}] ${r.state.padEnd(25)} ${String(r.records_added).padStart(3)} added, ${String(r.records_skipped).padStart(3)} skipped`);
        totalAdded += r.records_added;
        totalSkipped += r.records_skipped;
    }

    console.log(`  ${'-'.repeat(50)}`);
    console.log(`  Total: ${totalAdded} added, ${totalSkipped} skipped`);
    console.log('=======================================\n');

    // Output JSON for PHP endpoint to consume
    if (process.env.OUTPUT_JSON === '1') {
        const output = { success: true, results, total_added: totalAdded, total_skipped: totalSkipped, timestamp: new Date().toISOString() };
        console.log(`__JSON_OUTPUT__${JSON.stringify(output)}__END_JSON__`);
    }

    await pool.end();
}

main().catch(err => {
    console.error('Fatal error:', err.message);
    if (process.env.OUTPUT_JSON === '1') {
        console.log(`__JSON_OUTPUT__${JSON.stringify({ success: false, error: err.message })}__END_JSON__`);
    }
    process.exit(1);
});