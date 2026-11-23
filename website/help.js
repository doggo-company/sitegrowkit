// 固定の手順だけをコピーする。貼り付けやインストールの実行は利用者が行う。
for (const button of document.querySelectorAll('[data-copy]')) {
  button.hidden = false;
  button.addEventListener('click', async () => {
    const code = document.getElementById(button.dataset.copy);
    const status = button.closest('.help-command').querySelector('[role="status"]');
    if (!code || !status) return;
    try {
      if (!navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
      await navigator.clipboard.writeText(code.textContent);
      status.textContent = 'コピーしました。案内されたターミナルに貼り付けてください。';
      if (button.dataset.copy === 'ai-prompt') status.textContent = 'コピーしました。AIへの依頼に貼り付けてください。';
    } catch {
      // file:// や権限拒否でも手順は読める。文字を選択して手動コピーへ案内する。
      const range = document.createRange();
      range.selectNodeContents(code);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      code.focus();
      status.textContent = '自動コピーできませんでした。選択した文字を ⌘C または Ctrl+C でコピーしてください。';
    }
  });
}
