<!-- PHOTO / DOCUMENT VIEWER -->
<div class="photo-overlay" id="photo-overlay" onclick="closePhoto()">
  <div class="photo-box" onclick="event.stopPropagation()">
    <div class="photo-head">
      <span id="photo-title">Document</span>
      <button onclick="closePhoto()" aria-label="Close">✕</button>
    </div>
    <div class="photo-body" id="photo-body"></div>
  </div>
</div>