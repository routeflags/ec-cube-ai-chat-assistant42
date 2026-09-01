/**
 * AiChatAssistant42 — Chat Widget
 *
 * Vanilla JS (IIFE) — no jQuery dependency.
 * Posts to POST /api/ai-chat-assistant/chat
 * CSRF token read from <meta name="csrf-token">.
 */
(function () {
  'use strict';

  // ── DOM references ──
  var toggle  = document.getElementById('chat-toggle');
  var panel   = document.getElementById('chat-panel');
  var close   = document.getElementById('chat-close');
  var form    = document.getElementById('chat-form');
  var input   = document.getElementById('chat-input');
  var messages = document.getElementById('chat-messages');

  if (!toggle || !panel) return; // widget markup not present

  // ── Email reply modal references (cached, but functions re-fetch robustly) ──
  var emailModal   = document.getElementById('chat-email-modal');
  var emailForm    = document.getElementById('chat-email-form');
  var emailInput   = document.getElementById('chat-email-input');
  var emailCancel  = document.querySelector('.chat-email-cancel');

  // ── License modal references (robust) ──
  var licenseFooter    = document.getElementById('chat-license-footer') || null;
  var licenseModal     = document.getElementById('chat-license-modal') || null;
  var licenseClose     = document.getElementById('chat-license-close') || null;
  var licenseCloseX    = document.getElementById('chat-license-close-x') || null;
  var licenseFooterBtn = document.getElementById('chat-license-footer-btn') || null;

  // ── Config ──
  var API_URL = (panel.closest('[data-api-url]') || panel).getAttribute('data-api-url')
    || '/api/ai-chat-assistant/chat';
  var EMAIL_API_URL = (panel.closest('[data-email-api-url]') || panel).getAttribute('data-email-api-url')
    || '/api/ai-chat-assistant/email-reply-request';
  var FEEDBACK_API_URL = (panel.closest('[data-feedback-api-url]') || panel).getAttribute('data-feedback-api-url')
    || '/api/ai-chat-assistant/feedback';
  var MAX_LENGTH = parseInt(input.getAttribute('maxlength'), 10) || 500;

  // ── Session ──
  var sessionId = generateSessionId();

  // ── State flags ──
  var isEmailSubmitting = false;
  var isFeedbackSubmitting = false;
  var feedbackSubmitted = false;

  // ── Helpers ──

  /** Debug helper for overlay flow - 本番では localStorage フラグが無い限り出力抑止 */
  var DEBUG_OVERLAY = false;
  function overlayLog(step, data) {
    if (!DEBUG_OVERLAY || typeof console === 'undefined' || !console.log) return;
    var prefix = '[AiChatAssistant][Overlay]';
    var time = new Date().toISOString().slice(11, 23);
    if (data !== undefined) {
      // Use group for multi-line data
      if (typeof data === 'object' && data !== null) {
        console.log(prefix + ' ' + step + ' [' + time + ']', data);
      } else {
        console.log(prefix + ' ' + step + ' [' + time + ']', data);
      }
    } else {
      console.log(prefix + ' ' + step + ' [' + time + ']');
    }
  }
  function overlayGroup(title) {
    if (!DEBUG_OVERLAY || typeof console === 'undefined') return;
    if (console.groupCollapsed) {
      console.groupCollapsed('[AiChatAssistant][Overlay] ' + title);
    } else if (console.group) {
      console.group('[AiChatAssistant][Overlay] ' + title);
    }
  }
  function overlayGroupEnd() {
    if (!DEBUG_OVERLAY || typeof console === 'undefined') return;
    if (console.groupEnd) console.groupEnd();
  }

  /** Random session ID (UUID v4) — prefers crypto.randomUUID() when available */
  function generateSessionId() {
    // Prefer cryptographically strong UUID if available (modern browsers)
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      try {
        return crypto.randomUUID();
      } catch (e) { /* fallback below */ }
    }
    // Fallback: Math.random based UUID v4 (legacy)
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  /** Read CSRF token from <meta name="csrf-token"> */
  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  /** Scroll messages container to the bottom */
  function scrollToBottom() {
    if (messages) {
      messages.scrollTop = messages.scrollHeight;
    }
  }

  /** Escape HTML to prevent XSS */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /**
   * Very basic markdown → HTML:
   *  - [text](https://...) → <a href="...">
   *  - **bold** → <strong>
   *  - newlines → <br>
   *
   * リンクは escapeHtml 前に抽出し、テキストとURLを個別にエスケープして
   * 安全に組み立てる。bold 変換はリンク復元前（プレースホルダ状態）に行い、
   * リンク内テキストの ** を誤変換しないようにする。
   * javascript: スキームはブロックする。
   */
  function basicMarkdown(text) {
    // Trim leading/trailing whitespace to avoid left-side blank due to pre-wrap
    text = text.replace(/^\s+|\s+$/g, '');

    var linkPlaceholders = [];
    var linkPattern = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g;

    // eslint-disable-next-line no-control-regex
    text = text.replace(linkPattern, function (match, p1, p2) {
      // XSS対策: javascript: スキームはリンク化しない
      if (/^\s*javascript:/i.test(p2)) {
        return match;
      }
      var idx = linkPlaceholders.length;
      var safeText = escapeHtml(p1);
      var safeUrl = escapeHtml(p2).replace(/"/g, '&quot;');
      linkPlaceholders.push('<a href="' + safeUrl + '" target="_blank" rel="noopener">' + safeText + '</a>');
      return '__CHATLINK_' + idx + '__';
    });

    var escaped = escapeHtml(text);
    // Bold: **text** — リンクはプレースホルダ化されているためリンク内は変換されない
    escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Restore links
    for (var i = 0; i < linkPlaceholders.length; i++) {
      escaped = escaped.split('__CHATLINK_' + i + '__').join(linkPlaceholders[i]);
    }
    // Line breaks
    escaped = escaped.replace(/\n/g, '<br>');
    return escaped;
  }

  // ── Message rendering ──

  /** Append a message bubble */
  function addMessage(text, role) {
    var wrapper = document.createElement('div');
    wrapper.className = 'chat-message chat-message--' + role;

    var bubble = document.createElement('div');
    bubble.className = 'chat-message__content';
    bubble.innerHTML = basicMarkdown(text);

    wrapper.appendChild(bubble);
    messages.appendChild(wrapper);
    scrollToBottom();
    return wrapper;
  }

  /** Show error bubble */
  function addErrorMessage(text) {
    var wrapper = document.createElement('div');
    wrapper.className = 'chat-message chat-message--error';

    var bubble = document.createElement('div');
    bubble.className = 'chat-message__content';
    bubble.textContent = text;

    wrapper.appendChild(bubble);
    messages.appendChild(wrapper);
    scrollToBottom();
  }

  // ── Feedback / Email reply buttons ──

  /** Remove any existing feedback button container (also legacy .chat-email-reply) */
  function removeFeedbackButtons() {
    var existing = messages.querySelector('.chat-feedback');
    if (existing && existing.parentNode) {
      existing.parentNode.removeChild(existing);
    }
    // Legacy compatibility: remove old single-button container if any
    var legacy = messages.querySelector('.chat-email-reply');
    if (legacy && legacy.parentNode) {
      legacy.parentNode.removeChild(legacy);
    }
  }

  /** Legacy alias: removeEmailReplyButton → removeFeedbackButtons */
  function removeEmailReplyButton() {
    removeFeedbackButtons();
  }

  /**
   * Show "解決できました" (positive) and "解決できません" (negative) buttons.
   * Positive → POST /api/ai-chat-assistant/feedback
   * Negative → open email modal
   */
  function showFeedbackButtons() {
    if (feedbackSubmitted) return;
    removeFeedbackButtons();

    var wrapper = document.createElement('div');
    wrapper.className = 'chat-feedback';

    var positiveBtn = document.createElement('button');
    positiveBtn.type = 'button';
    positiveBtn.className = 'chat-feedback__positive';
    positiveBtn.textContent = '解決できました';
    positiveBtn.setAttribute('aria-label', '解決できました');

    var negativeBtn = document.createElement('button');
    negativeBtn.type = 'button';
    negativeBtn.className = 'chat-feedback__negative';
    negativeBtn.textContent = '解決できません';
    negativeBtn.setAttribute('aria-label', 'メールで回答を受け取る');

    positiveBtn.addEventListener('click', function () {
      submitPositiveFeedback(positiveBtn, negativeBtn);
    });

    negativeBtn.addEventListener('click', function () {
      overlayLog('Step0: 解決できません clicked', { sessionId: sessionId, feedbackSubmitted: feedbackSubmitted, hasModal: !!document.getElementById('chat-email-modal') });
      openEmailModal();
    });

    wrapper.appendChild(positiveBtn);
    wrapper.appendChild(negativeBtn);
    messages.appendChild(wrapper);
    scrollToBottom();
  }

  /** Legacy alias: showEmailReplyButton → showFeedbackButtons */
  function showEmailReplyButton() {
    showFeedbackButtons();
  }

  /** Submit positive feedback to API */
  function submitPositiveFeedback(positiveBtn, negativeBtn) {
    if (isFeedbackSubmitting || feedbackSubmitted) return;
    isFeedbackSubmitting = true;
    if (positiveBtn) positiveBtn.disabled = true;
    if (negativeBtn) negativeBtn.disabled = true;

    var payload = {
      session_id: sessionId,
      feedback: 'positive'
    };

    fetch(FEEDBACK_API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-ECCUBE-CSRF-TOKEN': getCsrfToken()
      },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        if (!response.ok) {
          // 409 = already submitted → treat as success (remove buttons)
          if (response.status === 409) {
            return { success: true, already: true };
          }
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        // 成功時はボタン削除 → サンクスメッセージ
        feedbackSubmitted = true;
        isFeedbackSubmitting = false;
        try {
          if (typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem('ai_chat_feedback_' + sessionId, 'positive');
          }
        } catch (e) { /* ignore storage errors */ }
        removeFeedbackButtons();
        var message = (data && data.message) || 'ご評価ありがとうございます。';
        addMessage(message, 'assistant');
      })
      .catch(function (err) {
        // 失敗時はボタンを再有効化して再試行可能に
        isFeedbackSubmitting = false;
        if (positiveBtn) positiveBtn.disabled = false;
        if (negativeBtn) negativeBtn.disabled = false;
        addErrorMessage('フィードバックの送信に失敗しました。もう一度お試しください。');
        if (typeof console !== 'undefined') {
          console.error('[AiChatAssistant] feedback error', err);
        }
      });
  }

  /** Open the email input modal — robustly re-fetches element */
  function openEmailModal() {
    // ライセンスが開いていれば閉じる（排他）
    var licM = document.getElementById('chat-license-modal') || licenseModal;
    if (licM && licM.style.display === 'flex') {
      closeLicenseModal();
    }
    // 白オーバーレイが残留していると黒モーダルの操作をブロックするため事前に除去
    document.querySelector('.bg-load-overlay')?.remove();
    overlayGroup('Step1: openEmailModal');
    overlayLog('called', { sessionId: sessionId, modalFound: !!(document.getElementById('chat-email-modal') || emailModal) });
    var modal = document.getElementById('chat-email-modal') || emailModal;
    var inputEl = document.getElementById('chat-email-input') || emailInput;
    if (modal) {
      modal.style.display = 'flex';
      overlayLog('Step1a: modal display set', { display: modal.style.display, computed: getComputedStyle(modal).display });
      if (inputEl) {
        inputEl.value = '';
        var errorEl = document.getElementById('chat-email-error');
        if (errorEl) {
          errorEl.textContent = '';
          errorEl.style.display = 'none';
        }
        inputEl.focus();
        overlayLog('Step1b: input focused', { focused: document.activeElement === inputEl });
      }
    } else {
      overlayLog('WARN: modal not found', null);
      if (typeof console !== 'undefined' && console.warn) console.warn('[AiChatAssistant][Overlay] modal not found');
    }
    overlayGroupEnd();
  }

  /** Close the email input modal — robustly re-fetches element, always sets display:none */
  function closeEmailModal() {
    overlayGroup('Step5: closeEmailModal');
    overlayLog('called', { isEmailSubmitting: isEmailSubmitting });
    var modal = document.getElementById('chat-email-modal') || emailModal;
    if (modal) {
      var before = modal.style.display;
      modal.style.display = 'none';
      overlayLog('Step5a: modal display set', { before: before, after: modal.style.display, computed: getComputedStyle(modal).display });
    } else {
      overlayLog('WARN: modal not found on close');
    }
    var formEl = document.getElementById('chat-email-form') || emailForm;
    if (formEl) {
      var submitBtn = formEl.querySelector('[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = false;
        overlayLog('Step5b: submitBtn re-enabled');
      }
    }
    isEmailSubmitting = false;
    overlayLog('isEmailSubmitting reset', { value: isEmailSubmitting });
    overlayGroupEnd();
  }

  // ── License modal ──
  var licenseTriggerElement = null;

  function openLicenseModal() {
    document.querySelector('.bg-load-overlay')?.remove();
    // 排他: email が開いていれば閉じる
    var em = document.getElementById('chat-email-modal') || emailModal;
    if (em && em.style.display === 'flex') {
      closeEmailModal();
    }
    var modal = document.getElementById('chat-license-modal') || licenseModal;
    if (!modal) return;
    licenseTriggerElement = document.activeElement;
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    var focusTarget = document.getElementById('chat-license-close-x') || licenseCloseX || document.getElementById('chat-license-close') || licenseClose;
    if (focusTarget) focusTarget.focus();
  }

  function closeLicenseModal() {
    var modal = document.getElementById('chat-license-modal') || licenseModal;
    if (modal) {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
    }
    // フォーカス復帰
    var trigger = licenseFooterBtn || document.getElementById('chat-license-footer-btn') || licenseTriggerElement;
    if (trigger && typeof trigger.focus === 'function') {
      try { trigger.focus(); } catch (e) {}
    }
    licenseTriggerElement = null;
  }

  /** Submit the email reply request to the API */
  function submitEmailReply(email) {
    overlayGroup('Step3: submitEmailReply');
    overlayLog('called', { email: email, sessionId: sessionId, isEmailSubmitting: isEmailSubmitting, EMAIL_API_URL: EMAIL_API_URL });
    if (isEmailSubmitting) {
      overlayLog('Step3a: already submitting, abort');
      overlayGroupEnd();
      return;
    }
    isEmailSubmitting = true;
    overlayLog('isEmailSubmitting set to true');

    var formEl = document.getElementById('chat-email-form') || emailForm;
    var submitBtn = formEl ? formEl.querySelector('[type="submit"]') : null;
    if (submitBtn) {
      submitBtn.disabled = true;
      overlayLog('Step3b: submitBtn disabled');
    }

    var payload = {
      session_id: sessionId,
      email: email
    };
    overlayLog('Step3b: payload prepared', payload);

    overlayLog('Step3c: fetch start', { url: EMAIL_API_URL, payload: payload });
    if (typeof console !== 'undefined' && console.time) console.time('[AiChatAssistant][Overlay] fetch duration');
    fetch(EMAIL_API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        if (typeof console !== 'undefined' && console.timeEnd) console.timeEnd('[AiChatAssistant][Overlay] fetch duration');
        overlayLog('Step4: fetch response', { ok: response.ok, status: response.status, statusText: response.statusText, url: response.url });
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        overlayLog('Step4a: fetch json parsed', data);
        closeEmailModal();
        overlayLog('Step5c: closeEmailModal after success');
        removeFeedbackButtons();
        overlayLog('Step6: removeFeedbackButtons');

        var message = data.message || 'メールアドレスを記録しました。後ほどご連絡いたします。';
        addMessage(message, 'assistant');
        overlayLog('Step7: addMessage', { message: message, role: 'assistant' });
        feedbackSubmitted = true;
        overlayLog('Step8: feedbackSubmitted=true, flow completed', { sessionId: sessionId });
        overlayGroupEnd();
      })
      .catch(function (err) {
        if (typeof console !== 'undefined' && console.timeEnd) try { console.timeEnd('[AiChatAssistant][Overlay] fetch duration'); } catch(e) {}
        overlayLog('Step4b: fetch error', { name: err.name, message: err.message, stack: err.stack });
        closeEmailModal();
        overlayLog('Step5d: closeEmailModal after error');
        removeFeedbackButtons();
        overlayLog('Step6a: removeFeedbackButtons after error');
        addErrorMessage('メールアドレスの送信中にエラーが発生しました。');
        if (typeof console !== 'undefined' && console.error) {
          console.error('[AiChatAssistant][Overlay] email reply error', err);
        }
        overlayGroupEnd();
      });
  }

  // ── Typing indicator ──

  var typingEl = null;

  function showTyping() {
    if (typingEl) return;
    typingEl = document.createElement('div');
    typingEl.className = 'chat-typing';
    typingEl.setAttribute('aria-label', '返答を入力中');

    var dots = document.createElement('div');
    dots.className = 'chat-typing__dots';
    for (var i = 0; i < 3; i++) {
      var dot = document.createElement('span');
      dot.className = 'chat-typing__dot';
      dots.appendChild(dot);
    }
    typingEl.appendChild(dots);
    messages.appendChild(typingEl);
    scrollToBottom();
  }

  function hideTyping() {
    if (typingEl && typingEl.parentNode) {
      typingEl.parentNode.removeChild(typingEl);
    }
    typingEl = null;
  }

  // ── Panel toggle ──

  function openPanel() {
    // function.ts の document click リスナが BUTTON[type=submit] で白オーバーレイを誤生成する対策。
    // fetchで非遷移のチャットでは不要なため、残留があれば除去する (保険)。
    document.querySelector('.bg-load-overlay')?.remove();
    panel.removeAttribute('hidden');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'チャットを閉じる');
    input.focus();
    scrollToBottom();
  }

  function closePanel() {
    panel.setAttribute('hidden', '');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'チャットを開く');
    toggle.focus();
  }

  function togglePanel() {
    if (panel.hasAttribute('hidden')) {
      openPanel();
    } else {
      closePanel();
    }
  }

  // ── API call ──

  function sendMessage(message) {
    // 送信直前に白オーバーレイの残留を掃除。fetchは非遷移のためオーバーレイ不要。
    document.querySelector('.bg-load-overlay')?.remove();
    if (!message || !message.trim()) return;

    var trimmed = message.trim();
    addMessage(trimmed, 'user');
    input.value = '';
    input.focus();

    showTyping();
    setFormDisabled(true);

    var payload = {
      message: trimmed,
      session_id: sessionId
    };

    fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-ECCUBE-CSRF-TOKEN': getCsrfToken()
      },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        hideTyping();
        setFormDisabled(false);

        var reply = data.reply || data.message || data.response;
        if (reply) {
          addMessage(reply, 'assistant');
          // ユーザーが問題を解決できた/できなかった場合のフィードバックボタンを表示
          showFeedbackButtons();
        } else {
          addErrorMessage('返答を取得できませんでした。');
        }
      })
      .catch(function (err) {
        hideTyping();
        setFormDisabled(false);

        // エラー種別に応じてメッセージを分岐
        var status = err.message ? parseInt(err.message.replace(/\D/g, ''), 10) : 0;
        var errorMsg;
        if (status === 429) {
          errorMsg = 'リクエストが多すぎます。しばらく待ってからお試しください。';
        } else if (status === 403) {
          errorMsg = 'チャットが管理者により無効にされています。';
        } else if (status === 500) {
          errorMsg = 'サーバーで問題が発生しました。もう一度お試しください。';
        } else if (err.name === 'TypeError' && err.message.indexOf('fetch') !== -1) {
          errorMsg = 'ネットワークに接続できません。接続を確認してください。';
        } else {
          errorMsg = '通信中にエラーが発生しました。もう一度お試しください。';
        }
        addErrorMessage(errorMsg);

        if (typeof console !== 'undefined') {
          console.error('[AiChatAssistant]', err);
        }
      });
  }

  /** Enable / disable form inputs during request */
  function setFormDisabled(disabled) {
    input.disabled = disabled;
    var btn = form.querySelector('.chat-form__send');
    if (btn) btn.disabled = disabled;
  }

  // ── Event listeners ──

  toggle.addEventListener('click', togglePanel);
  close.addEventListener('click', closePanel);

  form.addEventListener('submit', function (e) {
    // document レベルの loadingOverlay 誤発火を抑止 (function.ts の click リスナ対策)
    // submit 自体は click 起因のバブリング後に発火するため、保険として伝播停止と既存オーバーレイ除去を行う
    e.stopPropagation();
    document.querySelector('.bg-load-overlay')?.remove();
    e.preventDefault();
    sendMessage(input.value);
  });

  // Enter to send (Shift+Enter for newline — single-line input so just Enter)
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      // Enter 由来の submit でも白オーバーレイ抑止を担保
      e.preventDefault();
      e.stopPropagation();
      document.querySelector('.bg-load-overlay')?.remove();
      form.dispatchEvent(new Event('submit'));
    }
  });

  // 送信ボタン click でも document の loadingOverlay を抑止する (cart-add.ts:108-111 と同パターン)
  // submit ハンドラだけでは click イベントのバブリングが先に発火して白オーバーレイが生成されるため、
  // ボタン click 段階で stopPropagation と残留除去を行う。非遷移 fetch のためオーバーレイは不要。
  var chatSendBtn = form.querySelector('.chat-form__send');
  if (chatSendBtn) {
    chatSendBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      document.querySelector('.bg-load-overlay')?.remove();
    });
  }

  // Close on Escape key — stack: license > email > panel
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var licModal = document.getElementById('chat-license-modal') || licenseModal;
      if (licModal && licModal.style.display === 'flex') {
        closeLicenseModal();
        return;
      }
      var modal = document.getElementById('chat-email-modal') || emailModal;
      if (modal && modal.style.display === 'flex') {
        closeEmailModal();
        return;
      }
      if (!panel.hasAttribute('hidden')) {
        closePanel();
      }
    }
  });

  // Click outside panel to close (desktop only)
  document.addEventListener('click', function (e) {
    var licModalEx = document.getElementById('chat-license-modal') || licenseModal;
    if (licModalEx && licModalEx.contains(e.target)) {
      return;
    }
    var modal = document.getElementById('chat-email-modal') || emailModal;
    // メールモーダル内のクリックは無視
    if (modal && modal.contains(e.target)) {
      return;
    }
    // フッター（パネル内だがライセンスは除外済み）— ライセンスボタンは別途ハンドル
    // ライセンスフッター内のクリックでもパネルは閉じない
    var licFooterEx = document.getElementById('chat-license-footer') || licenseFooter;
    if (licFooterEx && licFooterEx.contains(e.target)) {
      // footer button clickはlicense handlerに委譲、パネルcloseは抑止
      var btns = licFooterEx.querySelectorAll('.chat-license-footer__button');
      for (var k = 0; k < btns.length; k++) {
        if (btns[k].contains(e.target)) return;
      }
    }
    if (
      !panel.hasAttribute('hidden') &&
      !panel.contains(e.target) &&
      !toggle.contains(e.target)
    ) {
      closePanel();
    }
  });

  // ── Email modal listeners ──

  if (emailForm) {
    emailForm.addEventListener('submit', function (e) {
      // 白オーバーレイ誤発火抑止: document click リスナの伝播を停止し既存オーバーレイを除去
      e.stopPropagation();
      document.querySelector('.bg-load-overlay')?.remove();
      overlayLog('Step2: emailForm submit', { isEmailSubmitting: isEmailSubmitting, eventType: e.type });
      e.preventDefault();
      if (isEmailSubmitting) {
        overlayLog('Step2a: already submitting, abort');
        return;
      }
      var email = emailInput.value.trim();
      var errorEl = document.getElementById('chat-email-error');
      overlayLog('Step2b: input value', { email: email, valid: emailInput.validity.valid, valueLength: email.length });

      if (!email || !emailInput.validity.valid) {
        overlayLog('Step2c: validation failed', { email: email, validity: emailInput.validity.valid });
        if (errorEl) {
          errorEl.textContent = '有効なメールアドレスを入力してください。';
          errorEl.style.display = '';
        }
        emailInput.focus();
        return;
      }

      if (errorEl) {
        errorEl.style.display = 'none';
      }
      overlayLog('Step2d: validation passed');
      submitEmailReply(email);
    });
  }

  // メールモーダル送信ボタン click でも白オーバーレイ抑止 (cart-add.ts と同パターン)
  // emailForm submit だけでは click バブリングで先に白オーバーレイが作られるため、ボタン click 段階で止める
  var emailSubmitBtn = (emailForm && emailForm.querySelector('.chat-email-modal__submit')) || document.querySelector('.chat-email-modal__submit');
  if (emailSubmitBtn) {
    emailSubmitBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      document.querySelector('.bg-load-overlay')?.remove();
    });
  }

  if (emailCancel) {
    emailCancel.addEventListener('click', function () {
      closeEmailModal();
    });
  }

  // Overlay background click to close modal
  if (emailModal) {
    emailModal.addEventListener('click', function (e) {
      if (e.target === emailModal) {
        closeEmailModal();
      }
    });
  }

  // ── License modal listeners ──
  (function setupLicenseListeners() {
    // フッターが1→2項目になっても querySelectorAll で冪等
    var footerEl = document.getElementById('chat-license-footer') || licenseFooter;
    if (footerEl) {
      var footerBtns = footerEl.querySelectorAll('.chat-license-footer__button');
      for (var i = 0; i < footerBtns.length; i++) {
        footerBtns[i].addEventListener('click', function (ev) {
          ev.stopPropagation();
          document.querySelector('.bg-load-overlay')?.remove();
          openLicenseModal();
        });
      }
      // Fallback single id
      var singleBtn = document.getElementById('chat-license-footer-btn') || licenseFooterBtn;
      if (singleBtn && footerBtns.length === 0) {
        singleBtn.addEventListener('click', function (ev) {
          ev.stopPropagation();
          document.querySelector('.bg-load-overlay')?.remove();
          openLicenseModal();
        });
      }
    }

    var lcX = document.getElementById('chat-license-close-x') || licenseCloseX;
    if (lcX) {
      lcX.addEventListener('click', function (ev) {
        ev.stopPropagation();
        closeLicenseModal();
      });
    }
    var lcBtn = document.getElementById('chat-license-close') || licenseClose;
    if (lcBtn) {
      lcBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        document.querySelector('.bg-load-overlay')?.remove();
        closeLicenseModal();
      });
    }
    var licModalEl = document.getElementById('chat-license-modal') || licenseModal;
    if (licModalEl) {
      licModalEl.addEventListener('click', function (e) {
        if (e.target === licModalEl) {
          closeLicenseModal();
        }
      });
    }
    // Terms modal future (if present)
    var termsCloseX = document.getElementById('chat-terms-close-x');
    var termsClose = document.getElementById('chat-terms-close');
    var termsModal = document.getElementById('chat-terms-modal');
    if (termsCloseX && termsModal) {
      termsCloseX.addEventListener('click', function () { termsModal.style.display = 'none'; });
    }
    if (termsClose && termsModal) {
      termsClose.addEventListener('click', function () { termsModal.style.display = 'none'; });
    }
    if (termsModal) {
      termsModal.addEventListener('click', function (e) { if (e.target === termsModal) termsModal.style.display = 'none'; });
    }
  })();

})();
