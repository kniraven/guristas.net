function pilotStandingCompare(a, b, order) {
  if (order.startsWith('id-')) return (Number(a.dataset.id) - Number(b.dataset.id)) * (order === 'id-desc' ? -1 : 1);
  if (order === 'name') return a.dataset.name.localeCompare(b.dataset.name);
  return ((Number(a.dataset.standing) - Number(b.dataset.standing)) * (order === 'standing-desc' ? -1 : 1)) || a.dataset.name.localeCompare(b.dataset.name);
}
function pilotStandingMatches(row, search, relationship) {
  const value = Number(row.dataset.standing);
  return row.dataset.search.toLocaleLowerCase().includes(search.toLocaleLowerCase().trim()) &&
    (relationship === 'all' || (relationship === 'positive' && value > 0) ||
     (relationship === 'negative' && value < 0) || (relationship === 'neutral' && value === 0));
}
document.querySelectorAll('[data-pilot-controls]').forEach(controls => {
  controls.hidden = false;
  const panel = controls.closest('section');
  const sort = controls.querySelector('[data-pilot-sort]');
  const filter = controls.querySelector('[data-pilot-filter]');
  const search = controls.querySelector('[data-pilot-search]');
  const allegiance = controls.querySelector('[data-pilot-allegiance]');
  const relation = controls.querySelector('[data-pilot-relation]');
  const groups = Array.from(panel.querySelectorAll('[data-pilot-group]'));
  const total = groups.reduce((sum, group) => sum + group.querySelector('tbody').rows.length, 0);
  const update = () => {
    let shown = 0;
    const filtering = search.value.trim() !== '' || filter.value !== 'all' || relation.value !== 'all';
    groups.forEach(group => {
      const body = group.querySelector('tbody');
      const rows = Array.from(body.rows);
      rows.sort((a, b) => pilotStandingCompare(a, b, sort.value));
      let count = 0;
      rows.forEach(row => {
        row.hidden = !pilotStandingMatches(row, search.value, relation.value) || (allegiance.value !== 'all' && row.dataset.allegiance !== allegiance.value);
        if (!row.hidden) count++;
        body.appendChild(row);
      });
      group.hidden = (filter.value !== 'all' && filter.value !== group.dataset.pilotGroup) || count === 0;
      group.querySelector('[data-pilot-count]').textContent = String(count);
      if (!group.hidden) { shown += count; if (filtering) group.open = true; }
    });
    panel.querySelector('[data-pilot-results]').textContent = shown === 0 ? 'No matching relationships. Try another search or reset the filters.' : `Showing ${shown} of ${total} reported relationships${allegiance.value === 'guristas' ? ' · Guristas only' : ''}.`;
    panel.querySelector('[data-pilot-sort-note]').textContent = 'Grouped by entity type; ' + ({'id-asc':'ID lowest first','id-desc':'ID highest first','name':'name A–Z','standing-desc':'highest standing first','standing-asc':'lowest standing first'}[sort.value]) + ' within each group. Agent affiliations and bases use CCP’s static data, retrieved October 6, 2026.';
  };
  [sort, filter, relation, allegiance].forEach(input => input.addEventListener('change', update));
  search.addEventListener('input', update);
  controls.querySelector('[data-pilot-reset]').addEventListener('click', () => {
    search.value = ''; allegiance.value = 'guristas'; relation.value = 'all'; filter.value = 'all'; sort.value = 'standing-desc';
    groups.forEach(group => { group.open = group.dataset.pilotGroup === 'faction'; });
    update();
  });
  controls.querySelector('[data-pilot-expand]').addEventListener('click', () => groups.forEach(group => { if (!group.hidden) group.open = true; }));
  controls.querySelector('[data-pilot-collapse]').addEventListener('click', () => groups.forEach(group => { group.open = false; }));
  update();
});
document.querySelectorAll('.pilot-entity-icon').forEach(image => {
  image.addEventListener('error', () => { image.hidden = true; });
  if (image.complete && image.naturalWidth === 0) image.hidden = true;
});

// Native disclosures work without JavaScript; links open their destination when enhanced.
document.querySelectorAll('[data-dossier-open]').forEach(link => {
  link.addEventListener('click', () => {
    const href = link.getAttribute('href');
    if (!href || !href.startsWith('#')) return;
    const destination = document.getElementById(href.slice(1));
    if (destination && destination.tagName === 'DETAILS') destination.open = true;
  });
});
