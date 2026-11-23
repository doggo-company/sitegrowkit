/* A page keeps its text and navigation usable if a replacement photo cannot load. */
(() => {
  // Native fragment navigation must clear a sticky header, including enlarged text.
  const header = document.getElementById('site-header');
  if (header && document.querySelector('.doc-cover--editorial')) {
    const measureHeader = () => {
      document.documentElement.style.setProperty('--starter-header-height', `${Math.ceil(header.getBoundingClientRect().height)}px`);
    };
    measureHeader();
    if (typeof ResizeObserver === 'function') new ResizeObserver(measureHeader).observe(header);
    else window.addEventListener('resize', measureHeader);
  }
  const failed = (img) => {
    if (!(img instanceof HTMLImageElement) || !document.body.classList.contains('site-tpl-a')) return;
    const cover = img.closest('.doc-cover');
    if (cover) {
      cover.classList.add('doc-cover--no-photo');
      const backdrop = img.closest('.doc-cover__bg');
      if (backdrop) backdrop.hidden = true;
      else {
        img.hidden = true;
        const photo = img.closest('.doc-cover__photo');
        if (photo && !photo.querySelector('.starter-image-fallback')) {
          const note = document.createElement('span');
          note.className = 'starter-image-fallback';
          note.textContent = '写真を読み込めませんでした';
          photo.insertBefore(note, photo.querySelector('figcaption'));
        }
      }
    } else {
      const figure = img.closest('.doc-image');
      if (!figure || figure.querySelector('.starter-image-fallback')) return;
      img.hidden = true;
      const note = document.createElement('span');
      note.className = 'starter-image-fallback';
      note.textContent = '写真を読み込めませんでした';
      figure.append(note);
    }
  };
  document.addEventListener('error', (event) => failed(event.target), true);
  document.querySelectorAll('.site-tpl-a img').forEach((img) => {
    if (img.complete && img.naturalWidth === 0) failed(img);
  });
})();
