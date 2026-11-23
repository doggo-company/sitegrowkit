// SiteGrowKit 同梱サンプル — グローバルナビの 2 階層目の開閉（R03 / R05・2026-09-09）
//
// `main.js`（取り込み元のスクリプト）には手を入れず、別ファイルで足す。
// 開閉はボタンのEnter/Space/クリックに統一し、見た目とaria-expandedを同期する。
document.addEventListener('DOMContentLoaded', function () {
    var nav = document.getElementById('global-nav');
    if (!nav) {
        return;
    }

    var toggles = nav.querySelectorAll('.gnav__toggle');

    function setOpen(toggle, open) {
        var item = toggle.closest('.gnav__item');
        if (!item) {
            return;
        }
        item.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeAll(except) {
        Array.prototype.forEach.call(toggles, function (toggle) {
            if (toggle !== except) {
                setOpen(toggle, false);
            }
        });
    }

    Array.prototype.forEach.call(toggles, function (toggle) {
        toggle.addEventListener('click', function () {
            var open = toggle.getAttribute('aria-expanded') !== 'true';
            // 同時に開くのは 1 つだけ（並んだ子が重なって読めなくなるのを防ぐ）
            closeAll(toggle);
            setOpen(toggle, open);
        });
    });

    // フォーカスが別の項目へ移ったら閉じる。外側クリックと同じ状態を保つ。
    nav.addEventListener('focusout', function (event) {
        var item = event.target.closest('.gnav__item');
        if (item && !item.contains(event.relatedTarget)) {
            var toggle = item.querySelector('.gnav__toggle');
            if (toggle) setOpen(toggle, false);
        }
    });

    // Escape で閉じ、開閉ボタンへフォーカスを戻す
    nav.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var item = event.target.closest('.gnav__item.is-open');
        if (!item) {
            return;
        }
        var toggle = item.querySelector('.gnav__toggle');
        if (toggle) {
            setOpen(toggle, false);
            toggle.focus();
        }
    });

    // ナビの外を押したら閉じる
    document.addEventListener('click', function (event) {
        if (!nav.contains(event.target)) {
            closeAll(null);
        }
    });
});
