(function(){
// =====================================================================
// public-content.js   (SUPABASE VERSION)
// =====================================================================
// WHAT THIS FILE DOES (in plain words):
//   When someone opens the school website, this file asks the Supabase
//   cloud database "give me the published news, announcements and events",
//   then builds the boxes you see on the page.
//
// BEFORE:  browser -> our PHP file on XAMPP -> MariaDB
// NOW:     browser -> Supabase (on the internet) directly
//
// WHY IS IT OK TO PUT THE KEY IN THIS FILE?
//   The "anon / publishable" key is meant to be public. It can only do what
//   our Row Level Security (RLS) rule allows: READ posts that are published.
//   It cannot edit or delete anything. NEVER put the service_role key or
//   your database password in this file.
// =====================================================================

// ---------------------------------------------------------------------
// STEP 1: PASTE YOUR OWN VALUES HERE
// (Supabase dashboard -> Project Settings -> API)
// ---------------------------------------------------------------------
const SUPABASE_URL = 'https://lqgygcbpbacjxboxxwau.supabase.co';   // your Project URL
const SUPABASE_KEY = 'sb_publishable_LNYKGU7vXhNUM1P4oYpaWw_muFRTfOm';           // the PUBLIC key only

// ---------------------------------------------------------------------
// Constants (values that never change while the page runs)
// ---------------------------------------------------------------------
// Month names, used to turn "2026-09-12" into "12 SEPTEMBER 2026"
const MONTHS_LONG  = ['JANUARY','FEBRUARY','MARCH','APRIL','MAY','JUNE','JULY','AUGUST','SEPTEMBER','OCTOBER','NOVEMBER','DECEMBER'];
const MONTHS_SHORT = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];

// A "pattern" (regular expression) that describes the ONLY image paths we trust.
// Example of a valid one: uploads/content/0123456789abcdef0123456789abcdef.jpg
// Anything else is ignored, so staff can't sneak in a weird link.
const IMG_RE = /^uploads\/content\/[a-f0-9]{32}\.(jpg|png|webp)$/;

// The columns we ask Supabase for (we skip columns the public page never needs)
const COLUMNS = 'id,title,body,category,is_featured,tag,tag_label,event_date,event_time,venue,cover_path,published_at,blocks';

// ---------------------------------------------------------------------
// DATE HELPERS
// ---------------------------------------------------------------------

// Today's date in Philippine time, as text like "2026-10-24".
// ('en-CA' is just a trick: that language writes dates as YYYY-MM-DD.)
function todayManila(){
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
}

// Turns date text into three numbers {y, m, d} (year, month, day).
// Supabase sends two kinds of text:
//   - plain date:  "2026-11-08"                  (event_date)
//   - timestamp:   "2026-09-12T00:00:00+00:00"   (published_at, in UTC time)
// A timestamp is converted to Manila time first, so the day is never off by one.
function parseDate(str){
  str = String(str);
  if(str.length > 10 && str.indexOf('T') !== -1){
    str = new Date(str).toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
  }
  const p = str.slice(0,10).split('-');      // "2026-11-08" -> ["2026","11","08"]
  return { y:+p[0], m:+p[1], d:+p[2] };      // the + turns text into a number
}
// Adds a leading zero: 5 -> "05"
function pad2(n){ return String(n).padStart(2,'0'); }
// "2026-09-12" -> "12 SEPTEMBER 2026"
function longDate(str){ const d = parseDate(str); return pad2(d.d) + ' ' + MONTHS_LONG[d.m-1] + ' ' + d.y; }
// "2026-09-12" -> "12 SEP 2026"
function shortDate(str){ const d = parseDate(str); return pad2(d.d) + ' ' + MONTHS_SHORT[d.m-1] + ' ' + d.y; }

// ---------------------------------------------------------------------
// ELEMENT HELPERS
// ---------------------------------------------------------------------

// Makes a new HTML element, e.g. el('p','intro','Hello') -> <p class="intro">Hello</p>
// We use textContent (not innerHTML) so staff-typed text is shown as plain text
// and can never run as code. This is an important security habit.
function el(tag, className, text){
  const e = document.createElement(tag);
  if(className) e.className = className;
  if(text !== undefined && text !== null) e.textContent = text;
  return e;
}

// The striped grey "photo goes here" box (static markup, no user data inside)
function placeholder(label){
  const ph = el('div','ph');
  if(label) ph.setAttribute('data-label', label);
  ph.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
  return ph;
}

// Shows the cover photo if there is a valid one, otherwise the grey placeholder
function coverOrPlaceholder(path, label){
  if(path && IMG_RE.test(path)){            // .test() = "does it match the safe pattern?"
    const box = el('div','cms-cover');
    const img = document.createElement('img');
    img.src = path; img.alt = ''; img.loading = 'lazy';
    box.appendChild(img);
    return box;
  }
  return placeholder(label);
}

// Turns the staff-written "blocks" (heading / paragraph / photo) into page content.
// Also used by the staff editor's live preview, so both look identical.
function renderBlocks(blocks, container){
  (blocks || []).forEach(b => {
    if(!b || typeof b !== 'object') return;
    if(b.type === 'heading' && b.text){
      container.appendChild(el('h3','cms-b-heading', b.text));
    } else if(b.type === 'text' && b.text){
      // Two Enters in a row = a new paragraph
      String(b.text).split(/\n{2,}/).forEach(chunk => {
        if(chunk.trim()) container.appendChild(el('p','cms-b-text', chunk.trim()));
      });
    } else if(b.type === 'photo' && IMG_RE.test(b.src || '')){
      const size = ['small','medium','full'].includes(b.size) ? b.size : 'medium';
      const fig = el('figure','cms-b-photo cms-size-' + size);
      const img = document.createElement('img');
      img.src = b.src; img.alt = b.caption || ''; img.loading = 'lazy';
      fig.appendChild(img);
      if(b.caption) fig.appendChild(el('figcaption','', b.caption));
      container.appendChild(fig);
    }
  });
}

// ---------------------------------------------------------------------
// TALKING TO SUPABASE
// ---------------------------------------------------------------------

// Sends a READ request ("GET") to Supabase's automatic REST API.
// "query" is the part after the "?" in the web address, e.g. "category=eq.news".
// Supabase answers with a list of rows in JSON format.
async function supabaseGet(query){
  const url = SUPABASE_URL + '/rest/v1/content_posts?' + query;
  const res = await fetch(url, { headers: { apikey: SUPABASE_KEY } });
  if(!res.ok) throw new Error('HTTP ' + res.status);   // 4xx/5xx = something went wrong
  return res.json();                                    // turn the answer into a JS array
}

// ---------------------------------------------------------------------
// OFFLINE BACKUP (fallback.json)
// ---------------------------------------------------------------------
// If Supabase can't be reached, we read fallback.json instead. It is a saved
// copy of the published posts, kept in our project folder on XAMPP.
// (The PHP file api/cms/_fallback.php rewrites it every time staff save or delete.)

// Reads the whole backup file. The "?t=" part is a changing number that stops
// the browser from showing an old cached copy of the file.
async function loadFallback(){
  const res = await fetch('fallback.json?t=' + Date.now());
  if(!res.ok) throw new Error('No fallback file');
  return res.json();
}

// Supabase filters the list for us when it is online. The backup file holds
// EVERYTHING, so here we repeat the same rules by hand in JavaScript.
function filterFallback(rows, type, today){
  const now = Date.now();
  // Keep only this category, and only posts whose publish time has arrived
  let list = rows.filter(r => r.category === type && new Date(r.published_at).getTime() <= now);

  // Dates look like "2026-11-08", so plain text comparison works for "before/after"
  if(type === 'announcement'){
    list = list.filter(r => !r.expires_on || r.expires_on >= today);       // hide expired
  }
  if(type === 'event'){
    list = list.filter(r => r.event_date && r.event_date >= today);        // hide past events
  }

  // Sorting: a.x < b.x gives -1 (a first), > gives 1 (b first)
  const newest = (a, b) => new Date(b.published_at) - new Date(a.published_at);
  if(type === 'news'){
    // Headline story first, then newest. Show at most 5.
    list.sort((a, b) => (b.is_featured - a.is_featured) || newest(a, b));
    return list.slice(0, 5);
  }
  if(type === 'announcement'){
    list.sort((a, b) => newest(a, b) || (a.id - b.id));
    return list.slice(0, 6);
  }
  // events: soonest first
  list.sort((a, b) => (a.event_date < b.event_date ? -1 : a.event_date > b.event_date ? 1 : a.id - b.id));
  return list.slice(0, 6);
}

// Gets the list for one type: 'news', 'announcement' or 'event'.
// Supabase filter cheat-sheet:   eq = equals,  gte = >=,  lte = <=,  is.null = empty
async function fetchContent(type){
  const today = todayManila();
  const nowIso = encodeURIComponent(new Date().toISOString());

  // Rules every list shares: only published posts, of this category, already "live"
  let q = 'select=' + COLUMNS +
          '&status=eq.published' +
          '&category=eq.' + type +
          '&published_at=lte.' + nowIso;

  if(type === 'news'){
    // Headline story first, then newest. Show at most 5.
    q += '&order=is_featured.desc,published_at.desc&limit=5';
  } else if(type === 'announcement'){
    // Hide expired ones: keep if there is no expiry date OR it has not passed yet
    q += '&or=(expires_on.is.null,expires_on.gte.' + today + ')' +
         '&order=published_at.desc,id.asc&limit=6';
  } else if(type === 'event'){
    // Hide past events. Soonest first.
    q += '&event_date=gte.' + today +
         '&order=event_date.asc,id.asc&limit=6';
  }

  // Try Supabase first. If anything goes wrong (no internet, project paused...),
  // use the saved backup file instead. If BOTH fail, the error continues up to
  // loadNews / loadEvents / loadAnnouncements, which show "unavailable".
  let rows;
  try{
    rows = await supabaseGet(q);
  }catch(err){
    console.warn('Supabase unreachable, using fallback.json', err);
    rows = filterFallback(await loadFallback(), type, today);
  }

  // The page wants to know "is there a full story to open?"
  // Yes if the post has at least one content block.
  rows.forEach(r => { r.has_details = Array.isArray(r.blocks) && r.blocks.length > 0; });
  return rows;
}

// Replaces the contents of a box with one plain message (used for errors / empty lists)
function showMessage(container, text){
  container.replaceChildren(el('p', '', text));
}

// ---------------------------------------------------------------------
// "READ MORE" WINDOW (the pop-up that shows the full story)
// ---------------------------------------------------------------------
function closeReader(){
  const ov = document.querySelector('.cms-reader-overlay');
  if(ov) ov.remove();
  document.body.style.overflow = '';          // let the page scroll again
}
// Pressing the Esc key closes the pop-up
document.addEventListener('keydown', e => { if(e.key === 'Escape') closeReader(); });

function showReader(p){
  closeReader();                               // never show two at once
  const ov  = el('div','cms-reader-overlay');  // dark background
  ov.setAttribute('role','dialog'); ov.setAttribute('aria-modal','true');
  const art = el('article','cms-reader');      // the white card

  const close = el('button','cms-reader-close','✕ Close');
  close.type = 'button'; close.onclick = closeReader;
  art.appendChild(close);

  // Date line: events show the event date, everything else the publish date
  if(p.category === 'event' && p.event_date){
    art.appendChild(el('div','news-date', longDate(p.event_date)));
  } else if(p.published_at){
    art.appendChild(el('div','news-date', longDate(p.published_at)));
  }
  art.appendChild(el('h2','cms-reader-title', p.title));

  if(p.category === 'event'){
    const bits = [p.event_time, p.venue].filter(Boolean);   // drop empty ones
    if(bits.length) art.appendChild(el('div','event-meta', bits.join('  ·  ')));
  }
  if(p.cover_path && IMG_RE.test(p.cover_path)){
    const img = document.createElement('img');
    img.className = 'cms-reader-cover'; img.src = p.cover_path; img.alt = '';
    art.appendChild(img);
  }
  if(p.body) art.appendChild(el('p','cms-reader-summary', p.body));
  renderBlocks(p.blocks, art);                 // heading / paragraph / photo parts

  ov.appendChild(art);
  ov.addEventListener('click', e => { if(e.target === ov) closeReader(); });  // click outside = close
  document.body.appendChild(ov);
  document.body.style.overflow = 'hidden';     // stop the page behind from scrolling
}

// Fetches ONE post by id, then opens the pop-up
async function openReader(id){
  try{
    let rows;
    try{
      rows = await supabaseGet('select=' + COLUMNS +
                               '&id=eq.' + encodeURIComponent(id) +
                               '&status=eq.published&limit=1');
    }catch(err){
      // Supabase unreachable: look for this post inside the backup file instead
      const all = await loadFallback();
      rows = all.filter(r => r.id === id);
    }
    if(rows.length) showReader(rows[0]);       // rows is a list; we want the first item
  }catch(err){ /* nothing to show; the card already has the summary */ }
}

// ---------------------------------------------------------------------
// NEWS SECTION
// ---------------------------------------------------------------------
async function loadNews(){
  const box = document.getElementById('news-layout');   // the empty box in index.html
  if(!box) return;
  try{
    const items = await fetchContent('news');
    if(items.length === 0){ showMessage(box, 'No news yet.'); return; }

    // The big card = the featured story (or just the newest if none is featured)
    const feature = items.find(n => n.is_featured) || items[0];
    // The small cards on the side = up to 4 others
    const others  = items.filter(n => n !== feature).slice(0,4);

    const card = el('a','news-feature');
    card.href = '#';
    card.addEventListener('click', e => { e.preventDefault(); if(feature.has_details) openReader(feature.id); });
    card.appendChild(coverOrPlaceholder(feature.cover_path, 'REPLACE → Featured news photo (1200×675)'));
    const body = el('div','news-feature-body');
    body.appendChild(el('div','news-date', longDate(feature.published_at)));
    body.appendChild(el('h3','',feature.title));
    body.appendChild(el('p','',feature.body));
    if(feature.has_details) body.appendChild(el('span','program-link','Read the full story →'));
    card.appendChild(body);

    const side = el('div','news-side');
    others.forEach(n => {
      const item = el('a','news-item');
      item.href = '#';
      item.addEventListener('click', e => { e.preventDefault(); if(n.has_details) openReader(n.id); });
      item.appendChild(coverOrPlaceholder(n.cover_path, ''));
      const txt = el('div');
      txt.appendChild(el('div','news-date', shortDate(n.published_at)));
      txt.appendChild(el('h4','',n.title));
      txt.appendChild(el('p','',n.body));
      item.appendChild(txt);
      side.appendChild(item);
    });

    box.replaceChildren(card, side);           // put the finished cards on the page
  }catch(err){
    showMessage(box, 'News is unavailable right now.');   // e.g. no internet
  }
}

// ---------------------------------------------------------------------
// ANNOUNCEMENTS SECTION
// ---------------------------------------------------------------------
async function loadAnnouncements(){
  const box = document.getElementById('announce-list');
  if(!box) return;
  try{
    const items = await fetchContent('announcement');
    if(items.length === 0){ showMessage(box, 'No announcements.'); return; }

    const known = ['urgent','info','event'];   // colours our CSS knows about
    box.replaceChildren(...items.map(a => {    // .map = turn each post into a card
      const item = el('div','announce-item');
      item.appendChild(el('span','tag ' + (known.includes(a.tag) ? a.tag : 'info'), a.tag_label || 'NOTICE'));
      item.appendChild(el('h5','',a.title));
      item.appendChild(el('p','cms-pre',a.body));
      return item;
    }));
  }catch(err){
    showMessage(box, 'Announcements are unavailable right now.');
  }
}

// ---------------------------------------------------------------------
// EVENTS SECTION
// ---------------------------------------------------------------------
async function loadEvents(){
  const box = document.getElementById('events-list');
  if(!box) return;
  try{
    const items = await fetchContent('event');
    if(items.length === 0){ showMessage(box, 'No upcoming events.'); return; }

    box.replaceChildren(...items.map(ev => {
      const d = parseDate(ev.event_date);
      const row = el('div','event-row');

      const date = el('div','event-date');     // the little calendar square
      date.appendChild(el('div','m',MONTHS_SHORT[d.m-1]));
      date.appendChild(el('div','d',pad2(d.d)));

      const body = el('div','event-body');
      body.appendChild(el('h4','',ev.title));
      const meta = el('div','event-meta');
      if(ev.event_time) meta.appendChild(el('span','',ev.event_time));
      if(ev.venue)      meta.appendChild(el('span','',ev.venue));
      body.appendChild(meta);
      body.appendChild(el('p','',ev.body));
      if(ev.has_details){
        const more = el('button','cms-more','More details →');
        more.type = 'button';
        more.addEventListener('click', () => openReader(ev.id));
        body.appendChild(more);
      }

      row.append(date, body);
      return row;
    }));
  }catch(err){
    showMessage(box, 'Events are unavailable right now.');
  }
}

// Share a few helpers with the staff editor (cms-staff.js) so its live preview
// looks exactly like the real website.
window.CFSContent = { renderBlocks: renderBlocks, longDate: longDate, el: el, IMG_RE: IMG_RE };

// Start everything when the page loads
loadNews();
loadAnnouncements();
loadEvents();
})();
