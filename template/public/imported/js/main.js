// Agent CMS Kit sample site — script (imported base)
document.addEventListener("DOMContentLoaded", function () {
  // ヘッダーのスクロール影
  var header = document.getElementById("site-header");
  window.addEventListener("scroll", function () {
    header.classList.toggle("is-scrolled", window.scrollY > 10);
  });

  // モバイルナビゲーション開閉
  var toggle = document.querySelector(".nav-toggle");
  var nav = document.getElementById("global-nav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }
});
