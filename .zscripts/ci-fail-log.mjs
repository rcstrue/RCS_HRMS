const sha = process.argv[2] || 'd18db35';
const H = { 'Accept': 'application/vnd.github+json', 'User-Agent': 'dsh-ci-log' };
const repo = 'rcstrue/RCS_HRMS';

// find the run for this sha + workflow
const runs = await (await fetch(`https://api.github.com/repos/${repo}/actions/runs?per_page=20`, { headers: H })).json();
for (const r of runs.workflow_runs || []) {
  if (!r.head_sha.startsWith(sha)) continue;
  if (!/PHP Lint|Security Audit/.test(r.name)) continue;
  console.log(`\n=== ${r.name} (${r.head_sha.slice(0,7)}) ${r.conclusion} ===`);
  const jobs = await (await fetch(`https://api.github.com/repos/${repo}/actions/runs/${r.id}/jobs`, { headers: H })).json();
  for (const j of jobs.jobs || []) {
    if (j.conclusion === 'success') { console.log(`  job ${j.name}: ${j.conclusion}`); continue; }
    console.log(`  job ${j.name}: ${j.conclusion}`);
    for (const s of j.steps || []) {
      if (s.conclusion !== 'success') console.log(`    step: ${s.name} -> ${s.conclusion}`);
    }
    // fetch logs
    try {
      const logRes = await fetch(`https://api.github.com/repos/${repo}/actions/jobs/${j.id}/logs`, { headers: H, redirect: 'follow' });
      const txt = await logRes.text();
      const lines = txt.split('\n');
      const errIdx = [];
      lines.forEach((l, i) => { if (/error|Error|ERROR|FAIL|fail|::error|not found|No such|undefined/i.test(l)) errIdx.push(i); });
      const seen = new Set();
      for (const i of errIdx) {
        for (let k = Math.max(0, i - 2); k <= Math.min(lines.length - 1, i + 4); k++) {
          if (seen.has(k)) continue;
          seen.add(k);
          if (lines[k].trim()) console.log('      | ' + lines[k].slice(0, 220));
        }
      }
    } catch (e) { console.log('    (logs unavailable: ' + e.message + ')'); }
  }
}
