/**
 * RCS HRMS — Minimum Wage Sync from Simpliance
 * 
 * Fetches state-wise minimum wage data from Simpliance.in
 * and inserts into the existing `minimum_wages` table.
 * 
 * Usage:
 *   node minimum-wage-sync.js              # Sync all operating states
 *   node minimum-wage-sync.js gujarat       # Sync single state
 *   node minimum-wage-sync.js --dry-run     # Preview without inserting
 * 
 * Cron (every Sunday 2 AM):
 *   0 2 * * 0 cd /path/to/php_payroll/scripts/minimum-wage-sync && node minimum-wage-sync.js >> sync.log 2>&1
 */

const axios = require('axios');
const cheerio = require('cheerio');
const mysql = require('mysql2/promise');

// ── Config ────────────────────────────────────────────────────────────
// Read DB config from PHP config file or environment
const DB_HOST = process.env.DB_HOST || 'localhost';
const DB_NAME = process.env.DB_NAME || 'rcs_hrms';
const DB_USER = process.env.DB_USER || 'root';
const DB_PASS = process.env.DB_PASS || '';

const BASE_URL = 'https://www.simpliance.in/India/LEI/minimum_wages/state-wise-details';

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

// ── DB Pool ────────────────────────────────────────────────────────────
const pool = mysql.createPool({
    host: DB_HOST,
    user: DB_USER,
    password: DB_PASS,
    database: DB_NAME,
    waitForConnections: true,
    connectionLimit: 5,
    charset: 'utf8mb4'
});

// ── Helpers ────────────────────────────────────────────────────────────
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function parseDate(txt) {
    // Handles: "1st Apr, 2026" → "2026-04-01"
    if (!txt) return null;
    const cleaned = txt.replace(/(\d+)(st|nd|rd|th)/i, '$1').trim();
    const d = new Date(cleaned);
    if (isNaN(d.getTime())) return null;
    return d.toISOString().slice(0, 10);
}

function mapCategory(raw) {
    const lower = (raw || '').toLowerCase().trim();
    return CATEGORY_MAP[lower] || raw; // fallback: keep original
}

function parseAmount(str) {
    if (!str) return 0;
    // Remove ₹, commas, spaces
    const num = parseFloat(String(str).replace(/[₹,\s]/g, ''));
    return isNaN(num) ? 0 : Math.round(num * 100) / 100;
}

// ── Get operating states from DB ───────────────────────────────────────
async function getOperatingStates() {
    const [rows] = await pool.execute(
        `SELECT id, state_name, slug FROM states WHERE is_active = 1 ORDER BY state_name`
    );
    return rows.filter(s => s.slug);
}

// ── Fetch single state from Simpliance ────────────────────────────────
async function fetchState(state, dryRun = false) {
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
            console.log(`    ⚠ No versions found for ${state.slug}`);
            return { state: state.state_name, state_id: state.id, status: 'error', records_added: 0, records_skipped: 0, error_message: 'No versions found on page' };
        }

        // Use latest version
        const latest = versions[0];
        console.log(`    Version: ${latest.text} (id=${latest.id})`);

        // Fetch the latest version page
        await sleep(1000); // Be polite
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

            // Simpliance table: Category | Zone | Basic/Day | VDA/Day | Total/Day | Total/Month
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
        console.log(`    ✗ Error: ${errorMessage}`);

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
    const stateFilter = args.find(a => !a.startsWith('--'));

    console.log('═══════════════════════════════════════════');
    console.log('  RCS HRMS — Minimum Wage Sync');
    console.log(`  Mode: ${dryRun ? 'DRY RUN (no inserts)' : 'LIVE'}`);
    console.log(`  Time: ${new Date().toISOString()}`);
    console.log('═══════════════════════════════════════════');

    let states;

    if (stateFilter) {
        // Single state by slug
        const [rows] = await pool.execute(
            `SELECT id, state_name, slug FROM states WHERE slug = ? AND is_active = 1`,
            [stateFilter]
        );
        states = rows;
    } else {
        states = await getOperatingStates();
    }

    if (states.length === 0) {
        console.log('No states found with slugs configured. Add slugs to the `states` table:');
        console.log("  UPDATE states SET slug = 'gujarat' WHERE state_name = 'Gujarat';");
        process.exit(1);
    }

    console.log(`\nSyncing ${states.length} state(s)...\n`);

    const results = [];

    for (const state of states) {
        const result = await fetchState(state, dryRun);
        results.push(result);

        // Log to DB
        if (!dryRun) {
            await pool.execute(
                `INSERT INTO minimum_wage_sync_log (state, state_id, status, records_added, records_skipped, error_message)
                 VALUES (?, ?, ?, ?, ?, ?)`,
                [result.state, result.state_id, result.status, result.records_added, result.records_skipped, result.error_message]
            );
        }

        // Rate limiting
        await sleep(2000);
    }

    // Summary
    console.log('\n═══════════════════════════════════════════');
    console.log('  SYNC SUMMARY');
    console.log('═══════════════════════════════════════════');

    let totalAdded = 0, totalSkipped = 0;
    for (const r of results) {
        const icon = r.status === 'success' ? '✓' : r.status === 'partial' ? '◐' : '✗';
        console.log(`  ${icon} ${r.state.padEnd(20)} ${String(r.records_added).padStart(3)} added, ${String(r.records_skipped).padStart(3)} skipped`);
        totalAdded += r.records_added;
        totalSkipped += r.records_skipped;
    }

    console.log(`  ${'─'.repeat(45)}`);
    console.log(`  Total: ${totalAdded} added, ${totalSkipped} skipped`);
    console.log(`═══════════════════════════════════════════\n`);

    await pool.end();
}

main().catch(err => {
    console.error('Fatal error:', err.message);
    process.exit(1);
});