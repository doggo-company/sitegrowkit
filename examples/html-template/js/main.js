// Green Leaf Design — site script
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

  // ヒーローの登場アニメーション（GSAP はトップページのみ読み込み）
  if (window.gsap) {
    gsap.from(".hero h1", { opacity: 0, y: 20, duration: 0.8 });
  }
});
