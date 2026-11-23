// 公開サイトのスマホ用ナビ。インライン実行に頼らず既定 CSP の範囲で動かす。
(() => {
    const header = document.querySelector('.site-header');
    const button = header?.querySelector('.site-header__toggle');
    const navigation = document.getElementById('site-navigation');
    if (!header || !button || !navigation) return;

    const close = () => button.setAttribute('aria-expanded', 'false');
    header.classList.add('site-header--interactive');
    button.addEventListener('click', () => {
        button.setAttribute('aria-expanded', String(button.getAttribute('aria-expanded') !== 'true'));
    });
    header.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
            close();
            button.focus();
        }
    });
    navigation.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('a')) close();
    });
    // 幅を戻しても、次に開くスマホメニューに古い状態を残さない。
    window.matchMedia('(max-width: 767px)').addEventListener('change', close);
})();
