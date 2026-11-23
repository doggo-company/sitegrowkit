// 内容は最初から読める状態にし、対応環境だけに控えめな動きを足す。
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
const compact = window.matchMedia('(max-width: 700px)');
const figure = document.querySelector('.product-view');
const reveals = [...document.querySelectorAll('.intro__grid > *, #workflow > .lead, #workflow > h2, .steps > li, .section-heading > *, .mobile-editing > *, .safety > *, .requirements .wrap > h2, .faq > h2, .contact > *')];
let observer, frame = 0, figureVisible = true;

function updateFigure() {
  frame = 0;
  if (!figure || reduced.matches || compact.matches || !figureVisible) return;
  const rect = figure.getBoundingClientRect();
  const progress = Math.min(1, Math.max(0, (innerHeight - rect.top) / (innerHeight + rect.height * .15)));
  figure.style.transform = `translateY(${(1 - progress) * 18}px) scale(${.88 + progress * .12})`;
}
function schedule() { if (!frame && !document.hidden) frame = requestAnimationFrame(updateFigure); }
function configureMotion() {
  observer?.disconnect();
  cancelAnimationFrame(frame); frame = 0;
  figure?.style.removeProperty('transform');
  document.documentElement.classList.toggle('motion-enabled', !reduced.matches);
  for (const element of reveals) { element.removeAttribute('data-reveal'); element.style.removeProperty('--reveal-delay'); }
  if (reduced.matches || !('IntersectionObserver' in window)) return;
  observer = new IntersectionObserver(entries => {
    for (const entry of entries) if (entry.isIntersecting) {
      entry.target.dataset.reveal = 'visible'; observer.unobserve(entry.target);
    }
  }, { threshold: .06 });
  for (const element of reveals) {
    if (element.getBoundingClientRect().top < innerHeight * .95) continue;
    element.dataset.reveal = 'pending';
    if (element.matches('.steps > li')) element.style.setProperty('--reveal-delay', `${[...element.parentElement.children].indexOf(element) * 90}ms`);
    observer.observe(element);
  }
  schedule();
}
if (figure && 'IntersectionObserver' in window) {
  new IntersectionObserver(([entry]) => { figureVisible = entry.isIntersecting; if (figureVisible) schedule(); }).observe(figure);
}
window.addEventListener('scroll', schedule, { passive: true });
window.addEventListener('resize', schedule, { passive: true });
document.addEventListener('focusin', event => {
  // キーボードで移動した要素を、アニメーション待ちのまま隠さない。
  const target = event.target.closest('[data-reveal]');
  if (target) { target.dataset.reveal = 'visible'; observer?.unobserve(target); }
});
reduced.addEventListener('change', configureMotion);
compact.addEventListener('change', configureMotion);
configureMotion();

// 実操作の収録。画面外・別タブでは停止し、動きを減らす設定と通信節約を尊重する。
const video = document.querySelector('#editor-demo');
const toggle = document.querySelector('#demo-toggle');
if (video && toggle) {
  let inView = false, userPaused = false, manualPlayback = false;
  const saveData = Boolean(navigator.connection?.saveData);
  const sync = () => {
    toggle.textContent = video.paused ? '操作デモを再生' : '操作デモを一時停止';
    toggle.setAttribute('aria-label', toggle.textContent);
  };
  const play = () => { video.play().catch(() => { sync(); }); };
  const visibility = () => {
    if (document.hidden || !inView) video.pause();
    else if (!userPaused && ((!reduced.matches && !saveData) || manualPlayback)) play();
    else video.pause();
  };
  toggle.hidden = false;
  toggle.addEventListener('click', () => {
    if (video.paused) { userPaused = false; manualPlayback = true; play(); }
    else { userPaused = true; manualPlayback = false; video.pause(); }
  });
  video.addEventListener('play', sync);
  video.addEventListener('pause', sync);
  video.addEventListener('error', () => {
    toggle.hidden = true;
    document.querySelector('#demo-fallback').hidden = false;
  });
  if ('IntersectionObserver' in window) new IntersectionObserver(([entry]) => { inView = entry.isIntersecting; visibility(); }, { threshold: .2 }).observe(video);
  document.addEventListener('visibilitychange', visibility);
  reduced.addEventListener('change', () => { manualPlayback = false; visibility(); });
  video.controls = false;
  sync();
}
