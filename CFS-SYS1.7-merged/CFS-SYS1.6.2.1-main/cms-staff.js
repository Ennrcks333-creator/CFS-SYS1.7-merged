(function(){
// cms-staff.js — the registrar's "Website content" tab: a card list of everything on the public
// site, plus a full-screen editor with a live preview. Loaded after script.js and
// public-content.js; it reuses their helpers ($, esc, toast, busy, showMsg, pickFile, checkFile,
// fmtDate, todayStr, showView, state) and the site's own renderer for the preview.
'use strict';

const API = 'api/cms/';
const IMG_ACCEPT = '.jpg,.jpeg,.png,.webp';
const IMG_EXT = ['jpg','jpeg','png','webp'];

const KINDS = {
  news:         { name: 'News story',   plural: 'News',          add: '+ Write a news story',   letter: 'N' },
  announcement: { name: 'Announcement', plural: 'Announcements', add: '+ Post an announcement', letter: 'A' },
  event:        { name: 'Event',        plural: 'Events',        add: '+ Add an event',         letter: 'E' }
};
// Plain-language choices for announcements; each one sets the label + colour on the website.
const NOTICE_TYPES = [
  { key: 'suspension', text: 'Class suspension (red)', tag: 'urgent', label: 'CLASS SUSPENSION' },
  { key: 'enrollment', text: 'Enrollment (blue)',      tag: 'info',   label: 'ENROLLMENT' },
  { key: 'payment',    text: 'Payment (blue)',         tag: 'info',   label: 'PAYMENT' },
  { key: 'reminder',   text: 'Reminder (gold)',        tag: 'event',  label: 'REMINDER' },
  { key: 'general',    text: 'General notice (blue)',  tag: 'info',   label: 'NOTICE' }
];
const SIZES = [['small','Small'],['medium','Medium'],['full','Full width']];

let posts = [];
let filter = 'all';
let ed = null;          // the post being edited
let editorEl = null;

// ---------- tiny helpers ----------
function mk(tag, cls, text){
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined && text !== null) e.textContent = text;
  return e;
}
function noticeByKey(k){ return NOTICE_TYPES.find(n => n.key === k) || NOTICE_TYPES[4]; }
function noticeFromPost(p){
  return NOTICE_TYPES.find(n => n.label === p.tag_label && n.tag === p.tag) || NOTICE_TYPES[4];
}

// Same as api() in script.js, plus the header the CMS endpoints require on every write.
async function call(path, opts){
  opts = opts || {};
  const init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'cfs-cms' } };
  if (opts.json !== undefined){ init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.json); }
  if (opts.form){ init.method = 'POST'; init.body = opts.form; }
  let res;
  try { res = await fetch(API + path, init); }
  catch(e){ const err = new Error('Cannot reach the server. Is Apache running?'); err.status = 0; throw err; }
  let data = null;
  try { data = await res.json(); } catch(e){}
  if (!res.ok){
    const err = new Error((data && data.error) || ('Something went wrong (' + res.status + ').'));
    err.status = res.status; throw err;
  }
  return data;
}
function authFailed(e){
  if (e && (e.status === 401 || e.status === 403)){
    closeEditor(true);
    state.user = null; showView('staffLogin');
    showMsg('staff-error', 'Please sign in again.');
    return true;
  }
  return false;
}

// =====================================================================
//  TABS
// =====================================================================
window.cmsShowTab = function(name){
  const panels = { records: 'staff-records-panel', masterlist: 'staff-masterlist-panel', content: 'staff-content-panel' };
  Object.keys(panels).forEach(function(k){
    $(panels[k]).style.display = k === name ? 'block' : 'none';
    $('stab-' + k).classList.toggle('active', k === name);
  });
  if (name === 'content'){ buildPanel(); loadPosts(); }
  if (name === 'masterlist' && window.mlReload) window.mlReload(false);   // always fetch fresh billing data
};

// =====================================================================
//  LIST OF POSTS
// =====================================================================
function buildPanel(){
  const p = $('staff-content-panel');
  if (p.dataset.built) return;
  p.dataset.built = '1';
  p.innerHTML =
    '<div class="cms-head"><div><h3>Website content</h3>' +
    '<p>Everything you publish here appears on the public school website.</p></div>' +
    '<div class="cms-new">' +
      Object.keys(KINDS).map(k => '<button class="cms-new-btn" data-act="new" data-kind="' + k + '">' + KINDS[k].add + '</button>').join('') +
    '</div></div>' +
    '<div class="cms-filter" id="cms-filter"></div>' +
    '<div class="form-error" id="cms-list-error"></div>' +
    '<div class="cms-grid" id="cms-grid"></div>';
  p.addEventListener('click', e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act;
    if (act === 'new') openEditor(b.dataset.kind, null);
    else if (act === 'edit') openEditor(null, Number(b.dataset.id));
    else if (act === 'del') deletePost(Number(b.dataset.id));
    else if (act === 'filter'){ filter = b.dataset.f; renderList(); }
  });
}

async function loadPosts(){
  showMsg('cms-list-error', '');
  try {
    const d = await call('list_posts.php');
    posts = d.posts;
    renderList();
  } catch(e){
    if (!authFailed(e)) showMsg('cms-list-error', e.message);
  }
}

function statusOf(p){
  const today = todayStr();
  if (p.status === 'draft') return ['draft', 'Draft — not on the website'];
  if (p.status === 'archived') return ['draft', 'Hidden'];
  if (p.category === 'announcement' && p.expires_on && p.expires_on < today) return ['draft', 'Expired — no longer shown'];
  if (p.category === 'event' && p.event_date && p.event_date < today) return ['draft', 'Past event — no longer shown'];
  return ['live', 'Live on the website'];
}

function renderList(){
  const counts = { all: posts.length };
  Object.keys(KINDS).forEach(k => counts[k] = posts.filter(p => p.category === k).length);
  $('cms-filter').innerHTML = [['all', 'All']].concat(Object.keys(KINDS).map(k => [k, KINDS[k].plural])).map(f =>
    '<button class="filter-pill' + (filter === f[0] ? ' active' : '') + '" data-act="filter" data-f="' + f[0] + '">' + f[1] + ' (' + counts[f[0]] + ')</button>'
  ).join('');

  const list = posts.filter(p => filter === 'all' || p.category === filter);
  const grid = $('cms-grid');
  if (!list.length){
    grid.innerHTML = '<div class="cms-empty">Nothing here yet. Use the buttons above to add your first one.</div>';
    return;
  }
  grid.innerHTML = list.map(p => {
    const st = statusOf(p);
    const when = p.category === 'event' ? 'Event on ' + fmtDate(p.event_date) : (p.published_at ? 'Published ' + fmtDate(p.published_at) : 'Last edited ' + fmtDate(p.updated_at));
    const thumb = p.cover_path
      ? '<img src="' + esc(p.cover_path) + '" alt="">'
      : '<span>' + KINDS[p.category].letter + '</span>';
    return '<div class="cms-card">' +
      '<div class="cms-card-thumb kind-' + p.category + '">' + thumb + '</div>' +
      '<div class="cms-card-body">' +
        '<div class="cms-chips"><span class="cms-chip kind-' + p.category + '">' + KINDS[p.category].name + '</span>' +
        '<span class="cms-status ' + st[0] + '">' + st[1] + '</span></div>' +
        '<h4>' + esc(p.title) + '</h4>' +
        '<div class="cms-card-meta">' + esc(when) + (p.is_featured ? ' · Headline story' : '') + '</div>' +
        '<div class="cms-card-actions">' +
          '<button class="cms-btn" data-act="edit" data-id="' + p.id + '">Edit</button>' +
          '<button class="cms-btn danger" data-act="del" data-id="' + p.id + '">Delete</button>' +
        '</div>' +
      '</div></div>';
  }).join('');
}

async function deletePost(id){
  const p = posts.find(x => x.id === id); if (!p) return;
  if (!confirm('Delete "' + p.title + '"?\n\nThis removes it from the website for good and cannot be undone.')) return;
  try {
    await call('delete.php', { json: { id: id } });
    toast('Deleted.');
    await loadPosts();
  } catch(e){ if (!authFailed(e)) showMsg('cms-list-error', e.message); }
}

// =====================================================================
//  EDITOR
// =====================================================================
async function openEditor(category, id){
  showMsg('cms-list-error', '');
  try {
    if (id){
      const d = await call('get.php?id=' + id);
      const p = d.post;
      ed = {
        id: p.id, category: p.category, status: p.status, title: p.title || '', body: p.body || '',
        cover_path: p.cover_path || '', is_featured: !!p.is_featured,
        noticeKey: noticeFromPost(p).key, expires_on: p.expires_on || '',
        event_date: p.event_date || '', event_time: p.event_time || '', venue: p.venue || '',
        blocks: Array.isArray(p.blocks) ? p.blocks : [], dirty: false
      };
    } else {
      ed = {
        id: null, category: category, status: 'draft', title: '', body: '', cover_path: '', is_featured: false,
        noticeKey: 'general', expires_on: '', event_date: '', event_time: '', venue: '', blocks: [], dirty: false
      };
    }
  } catch(e){ if (!authFailed(e)) showMsg('cms-list-error', e.message); return; }

  if (!editorEl){
    editorEl = mk('div', 'cms-editor');
    editorEl.setAttribute('role', 'dialog');
    editorEl.setAttribute('aria-modal', 'true');
    editorEl.addEventListener('click', onEditorClick);
    editorEl.addEventListener('input', onEditorInput);
    document.body.appendChild(editorEl);
  }
  buildEditor();
  editorEl.style.display = 'block';
  document.body.style.overflow = 'hidden';
  editorEl.scrollTop = 0;
}

function closeEditor(force){
  if (!editorEl || editorEl.style.display === 'none') return;
  if (!force && ed && ed.dirty && !confirm('You have changes that are not saved.\n\nLeave without saving?')) return;
  editorEl.style.display = 'none';
  document.body.style.overflow = '';
  ed = null;
}

function buildEditor(){
  const k = KINDS[ed.category];
  editorEl.innerHTML =
    '<div class="cms-ed-bar">' +
      '<button class="cms-link" data-act="back">← Back to all content</button>' +
      '<div class="cms-ed-title">' + k.name + (ed.id ? ' — editing' : ' — new') + '</div>' +
      '<div class="cms-ed-actions" id="cms-ed-actions"></div>' +
    '</div>' +
    '<div class="cms-ed-wrap">' +
      '<div class="form-error" id="cms-ed-error"></div>' +
      '<div class="cms-ed-body">' +
        '<div class="cms-ed-form">' + formHtml() + '</div>' +
        '<div class="cms-ed-preview"><div class="cms-prev-label">What visitors will see</div><div class="cms-prev" id="cms-prev"></div></div>' +
      '</div>' +
    '</div>';
  renderActions();
  if ($('cms-blocks')) renderBlockList();
  if ($('cms-cover')) renderCover();
  renderPreview();
}

function renderActions(){
  const live = ed.status === 'published';
  $('cms-ed-actions').innerHTML = live
    ? '<button class="cms-btn" data-act="save" data-status="draft">Take down from website</button>' +
      '<button class="cms-btn primary" data-act="save" data-status="published">Save changes</button>'
    : '<button class="cms-btn" data-act="save" data-status="draft">Save draft</button>' +
      '<button class="cms-btn gold" data-act="save" data-status="published">Publish to website</button>';
}

function formHtml(){
  const c = ed.category;
  let h = '';
  if (c === 'announcement'){
    h += '<div class="field"><label>What kind of announcement is this?</label><select data-f="noticeKey">' +
         NOTICE_TYPES.map(n => '<option value="' + n.key + '"' + (ed.noticeKey === n.key ? ' selected' : '') + '>' + esc(n.text) + '</option>').join('') +
         '</select></div>';
  }
  h += '<div class="field"><label>' + (c === 'news' ? 'Headline' : (c === 'event' ? 'Event name' : 'Title')) + '</label>' +
       '<input type="text" maxlength="150" data-f="title" value="' + esc(ed.title) + '" placeholder="' +
       (c === 'news' ? 'e.g. Grade 6 pupils win the science quiz bee' : (c === 'event' ? 'e.g. Parent-Teacher Conference' : 'e.g. No classes on Monday')) + '"></div>';

  if (c === 'event'){
    h += '<div class="row-2"><div class="field"><label>Date</label><input type="date" data-f="event_date" value="' + esc(ed.event_date) + '"></div>' +
         '<div class="field"><label>Time</label><input type="text" maxlength="50" data-f="event_time" value="' + esc(ed.event_time) + '" placeholder="e.g. 8:00 AM – 12:00 NN"></div></div>' +
         '<div class="field"><label>Where</label><input type="text" maxlength="100" data-f="venue" value="' + esc(ed.venue) + '" placeholder="e.g. Covered court"></div>';
  }

  if (c === 'announcement'){
    h += '<div class="field"><label>Message</label><textarea rows="5" maxlength="600" data-f="body" placeholder="Write what parents need to know.">' + esc(ed.body) + '</textarea></div>' +
         '<div class="field"><label>Stop showing it after (optional)</label><input type="date" data-f="expires_on" value="' + esc(ed.expires_on) + '">' +
         '<div class="field-hint">Leave empty to keep it on the website until you take it down.</div></div>';
  } else {
    h += '<div class="field"><label>Short description <span class="cms-hint">— shown on the homepage</span></label>' +
         '<textarea rows="3" maxlength="300" data-f="body" placeholder="One or two sentences.">' + esc(ed.body) + '</textarea></div>';
  }

  if (c === 'news'){
    h += '<div class="field"><label>Cover photo</label><div id="cms-cover"></div></div>' +
         '<label class="cms-check"><input type="checkbox" data-f="is_featured"' + (ed.is_featured ? ' checked' : '') + '> Make this the big headline story on the homepage</label>';
  }

  if (c !== 'announcement'){
    h += '<div class="cms-section-title">' + (c === 'news' ? 'The full story' : 'More details') + ' <span>(optional — add anything you like, in any order)</span></div>' +
         '<div id="cms-blocks"></div>' +
         '<div class="cms-add-row"><span>Add:</span>' +
           '<button class="cms-btn" data-act="add" data-type="heading">+ Heading</button>' +
           '<button class="cms-btn" data-act="add" data-type="text">+ Paragraph</button>' +
           '<button class="cms-btn" data-act="add" data-type="photo">+ Photo</button>' +
         '</div>';
  }
  return h;
}

function renderCover(){
  $('cms-cover').innerHTML = ed.cover_path
    ? '<img class="cms-thumb" src="' + esc(ed.cover_path) + '" alt=""><div class="cms-row-btns">' +
      '<button class="cms-btn" data-act="cover">Change photo</button><button class="cms-btn danger" data-act="cover-rm">Remove</button></div>'
    : '<button class="cms-btn" data-act="cover">Choose a photo</button><div class="field-hint">JPG, PNG or WEBP, up to 5 MB.</div>';
}

function renderBlockList(){
  const box = $('cms-blocks');
  if (!ed.blocks.length){
    box.innerHTML = '<div class="cms-empty small">Nothing added yet. Use the buttons below to add a heading, a paragraph or a photo.</div>';
    return;
  }
  box.innerHTML = ed.blocks.map((b, i) => {
    const names = { heading: 'Heading', text: 'Paragraph', photo: 'Photo' };
    let inner = '';
    if (b.type === 'heading'){
      inner = '<input type="text" maxlength="150" data-b="' + i + '" data-bf="text" value="' + esc(b.text) + '" placeholder="Type a heading">';
    } else if (b.type === 'text'){
      inner = '<textarea rows="5" maxlength="5000" data-b="' + i + '" data-bf="text" placeholder="Write your paragraph here. Press Enter twice to start a new paragraph.">' + esc(b.text) + '</textarea>';
    } else {
      inner = (b.src
          ? '<img class="cms-thumb" src="' + esc(b.src) + '" alt=""><div class="cms-row-btns"><button class="cms-btn" data-act="photo" data-i="' + i + '">Change photo</button></div>'
          : '<button class="cms-btn" data-act="photo" data-i="' + i + '">Choose a photo</button><div class="field-hint">JPG, PNG or WEBP, up to 5 MB.</div>') +
        '<div class="field" style="margin-top:12px"><label>Caption (optional)</label><input type="text" maxlength="200" data-b="' + i + '" data-bf="caption" value="' + esc(b.caption || '') + '" placeholder="Describe the photo"></div>' +
        '<div class="field"><label>Size on the page</label><select data-b="' + i + '" data-bf="size">' +
          SIZES.map(s => '<option value="' + s[0] + '"' + ((b.size || 'medium') === s[0] ? ' selected' : '') + '>' + s[1] + '</option>').join('') + '</select></div>';
    }
    return '<div class="cms-block"><div class="cms-block-head"><strong>' + names[b.type] + '</strong><div>' +
      '<button class="cms-icon" title="Move up" data-act="up" data-i="' + i + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
      '<button class="cms-icon" title="Move down" data-act="down" data-i="' + i + '"' + (i === ed.blocks.length - 1 ? ' disabled' : '') + '>↓</button>' +
      '<button class="cms-icon danger" title="Remove" data-act="rm" data-i="' + i + '">✕</button></div></div>' + inner + '</div>';
  }).join('');
}

// ---------- preview (uses the real website renderer) ----------
function renderPreview(){
  const box = $('cms-prev'); if (!box) return;
  const R = window.CFSContent;
  box.replaceChildren();
  const title = ed.title || 'Your title appears here';

  if (ed.category === 'announcement'){
    const n = noticeByKey(ed.noticeKey);
    const wrap = mk('div', 'announce-box'); const list = mk('div', 'announce-list'); const item = mk('div', 'announce-item');
    item.appendChild(mk('span', 'tag ' + n.tag, n.label));
    item.appendChild(mk('h5', '', title));
    item.appendChild(mk('p', 'cms-pre', ed.body || 'Your message appears here.'));
    list.appendChild(item); wrap.appendChild(list); box.appendChild(wrap);
    return;
  }

  if (ed.category === 'event'){
    const row = mk('div', 'event-row');
    const d = ed.event_date ? R.longDate(ed.event_date).split(' ') : ['--', 'DATE'];
    const date = mk('div', 'event-date'); date.appendChild(mk('div', 'm', d[1] ? d[1].slice(0, 3) : '')); date.appendChild(mk('div', 'd', d[0]));
    const body = mk('div', 'event-body'); body.appendChild(mk('h4', '', title));
    const meta = mk('div', 'event-meta');
    if (ed.event_time) meta.appendChild(mk('span', '', ed.event_time));
    if (ed.venue) meta.appendChild(mk('span', '', ed.venue));
    body.appendChild(meta); body.appendChild(mk('p', '', ed.body || 'Your description appears here.'));
    row.append(date, body); box.appendChild(row);
    if (ed.blocks.length){
      box.appendChild(mk('div', 'cms-prev-sub', 'When someone clicks “More details”:'));
      const art = mk('article', 'cms-reader cms-reader-inline'); R.renderBlocks(ed.blocks, art); box.appendChild(art);
    }
    return;
  }

  // news
  const card = mk('div', 'news-feature');
  if (ed.cover_path && R.IMG_RE.test(ed.cover_path)){
    const cv = mk('div', 'cms-cover'); const img = document.createElement('img'); img.src = ed.cover_path; img.alt = ''; cv.appendChild(img); card.appendChild(cv);
  } else {
    const ph = mk('div', 'ph'); ph.setAttribute('data-label', 'No cover photo yet'); card.appendChild(ph);
  }
  const body = mk('div', 'news-feature-body');
  body.appendChild(mk('div', 'news-date', R.longDate(todayStr())));
  body.appendChild(mk('h3', '', title));
  body.appendChild(mk('p', '', ed.body || 'Your short description appears here.'));
  card.appendChild(body); box.appendChild(card);
  if (ed.blocks.length){
    box.appendChild(mk('div', 'cms-prev-sub', 'When someone clicks the story:'));
    const art = mk('article', 'cms-reader cms-reader-inline'); R.renderBlocks(ed.blocks, art); box.appendChild(art);
  }
}

// ---------- editor events ----------
function touch(){ ed.dirty = true; }

function onEditorInput(e){
  if (!ed) return;
  const t = e.target;
  if (t.dataset.f){
    ed[t.dataset.f] = t.type === 'checkbox' ? t.checked : t.value;
  } else if (t.dataset.b !== undefined){
    const b = ed.blocks[Number(t.dataset.b)];
    if (b) b[t.dataset.bf] = t.value;
  } else return;
  touch(); renderPreview();
}

async function uploadPhoto(file){
  const err = checkFile(file, IMG_EXT);
  if (err) throw new Error(err);
  const fd = new FormData(); fd.append('image', file);
  toast('Uploading photo…');
  const d = await call('upload_image.php', { form: fd });
  return d.path;
}

function onEditorClick(e){
  const b = e.target.closest('[data-act]'); if (!b || !ed) return;
  const act = b.dataset.act, i = Number(b.dataset.i);

  if (act === 'back') closeEditor(false);
  else if (act === 'save') save(b.dataset.status, b);
  else if (act === 'add'){
    ed.blocks.push(b.dataset.type === 'photo' ? { type: 'photo', src: '', caption: '', size: 'medium' } : { type: b.dataset.type, text: '' });
    touch(); renderBlockList(); renderPreview();
    const last = $('cms-blocks').lastElementChild; if (last) last.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  else if (act === 'up' && i > 0){ [ed.blocks[i - 1], ed.blocks[i]] = [ed.blocks[i], ed.blocks[i - 1]]; touch(); renderBlockList(); renderPreview(); }
  else if (act === 'down' && i < ed.blocks.length - 1){ [ed.blocks[i + 1], ed.blocks[i]] = [ed.blocks[i], ed.blocks[i + 1]]; touch(); renderBlockList(); renderPreview(); }
  else if (act === 'rm'){ if (confirm('Remove this part?')){ ed.blocks.splice(i, 1); touch(); renderBlockList(); renderPreview(); } }
  else if (act === 'photo'){
    pickFile(IMG_ACCEPT, async file => {
      try { const path = await uploadPhoto(file); ed.blocks[i].src = path; touch(); renderBlockList(); renderPreview(); toast('Photo added.'); }
      catch(err){ if (!authFailed(err)) showMsg('cms-ed-error', err.message); }
    });
  }
  else if (act === 'cover'){
    pickFile(IMG_ACCEPT, async file => {
      try { ed.cover_path = await uploadPhoto(file); touch(); renderCover(); renderPreview(); toast('Cover photo added.'); }
      catch(err){ if (!authFailed(err)) showMsg('cms-ed-error', err.message); }
    });
  }
  else if (act === 'cover-rm'){ ed.cover_path = ''; touch(); renderCover(); renderPreview(); }
}

// ---------- save ----------
function problemBeforeSave(status){
  if (!ed.title.trim()) return 'Please give this a title first.';
  if (status !== 'published') return '';
  if (ed.category === 'announcement' && !ed.body.trim()) return 'Please write the announcement message.';
  if (ed.category === 'news' && !ed.body.trim()) return 'Please add a short description for the homepage.';
  if (ed.category === 'event' && !ed.event_date) return 'Please choose the date of the event.';
  if (ed.blocks.some(b => b.type === 'photo' && !b.src)) return 'One of your photo parts has no photo yet. Choose one or remove that part.';
  return '';
}

async function save(status, btn){
  showMsg('cms-ed-error', '');
  const problem = problemBeforeSave(status);
  if (problem){ showMsg('cms-ed-error', problem); return; }

  const n = noticeByKey(ed.noticeKey);
  const payload = {
    id: ed.id || undefined, category: ed.category, status: status,
    title: ed.title.trim(), body: ed.body.trim(),
    blocks: ed.category === 'announcement' ? [] : ed.blocks.filter(b => b.type === 'photo' ? !!b.src : !!String(b.text || '').trim()),
    cover_path: ed.category === 'news' ? (ed.cover_path || null) : null,
    is_featured: ed.category === 'news' ? !!ed.is_featured : false,
    tag: ed.category === 'announcement' ? n.tag : undefined,
    tag_label: ed.category === 'announcement' ? n.label : undefined,
    expires_on: ed.category === 'announcement' ? (ed.expires_on || null) : undefined,
    event_date: ed.category === 'event' ? (ed.event_date || null) : undefined,
    event_time: ed.category === 'event' ? ed.event_time.trim() : undefined,
    venue: ed.category === 'event' ? ed.venue.trim() : undefined
  };

  busy(btn, true, 'Saving…');
  try {
    const wasLive = ed.status === 'published';
    const r = await call('save.php', { json: payload });
    ed.id = r.id; ed.status = r.status; ed.dirty = false;
    await loadPosts();
    if (status === 'published'){
      toast(wasLive ? 'Changes saved — they are live on the website.' : 'Published! It is now on the website.');
      closeEditor(true);
    } else {
      toast(wasLive ? 'Taken down from the website. Saved as a draft.' : 'Draft saved. It is not on the website yet.');
      busy(btn, false);
      renderActions();
      const t = editorEl.querySelector('.cms-ed-title'); if (t) t.textContent = KINDS[ed.category].name + ' — editing';
    }
  } catch(e){
    busy(btn, false);
    if (!authFailed(e)) showMsg('cms-ed-error', e.message);
  }
}
})();
