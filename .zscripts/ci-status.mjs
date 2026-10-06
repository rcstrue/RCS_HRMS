const res = await fetch('https://api.github.com/repos/rcstrue/RCS_HRMS/actions/runs?per_page=15', {
  headers: { 'Accept': 'application/vnd.github+json', 'User-Agent': 'dsh-ci-check' }
});
const j = await res.json();
console.log('latest runs:');
for (const r of j.workflow_runs || []) {
  console.log(`  ${r.head_sha.slice(0,7)}  ${r.name.padEnd(30)}  ${String(r.conclusion)}`);
}
