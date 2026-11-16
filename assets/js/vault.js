/**
 * 后台 - 密码保险箱页面交互
 *
 * 从 admin/vault.php 的内联 <script> 抽出。抽出的两个理由：
 *   1. vault.php 有近 270 行是这段脚本，抽走后页面只剩控制器 + 视图；
 *   2. 内联脚本无法被 node --check 覆盖，独立成文件后语法错误能在 CI 里被拦下。
 *
 * 运行时参数（CSRF / 超时 / 确认词组 / 全部界面文案）由页面上的
 * <script type="application/json" id="vaultConfig"> 传入，本文件不内嵌任何 PHP。
 *
 * 依赖的 DOM 约定（由 admin/vault.php 提供）：
 *   #vaultConfig            配置 JSON
 *   [data-vault-act]        显示 / 复制按钮（配合 data-vault-id / data-vault-field）
 *   [data-vault-mask]       明文落点（值为 "<id>-<field>"）
 *   [data-vault-toggle]     密码输入框的显示切换
 *   [data-vault-strength]   密码强度提示容器（值为关联输入框 id）
 *   #vaultGenPwd            随机密码生成按钮
 *   #vaultTimer             解锁倒计时（data-remaining 为剩余秒数）
 *   #vaultResetModal        重置确认弹窗
 */
(function () {
    'use strict';

    var cfgEl = document.getElementById('vaultConfig');
    if (!cfgEl) { return; }
    var CFG = JSON.parse(cfgEl.textContent);
    var T   = CFG.i18n;

    var CSRF      = CFG.csrf;
    var ENDPOINT  = CFG.endpoint;
    var TIMEOUT   = CFG.timeout;
    var RESET_PHRASE = CFG.resetPhrase;

    /* ================= 一、明文按需读取（明文不进入页面源码） ================= */
    function fetchSecret(id, field) {
        var body = new URLSearchParams();
        body.set('csrf_token', CSRF);
        body.set('action', 'reveal');
        body.set('id', String(id));
        body.set('field', field);

        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: (T.badResponse) }; });
        }).then(function (data) {
            if (!data.ok) { throw new Error(data.message || (T.readFailed)); }
            return data.value;
        });
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext !== false) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', 'readonly');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            ok ? resolve() : reject(new Error((T.copyFailedManual)));
        });
    }

    function flashCell(btn, text, isError) {
        var original = btn.textContent;
        btn.textContent = text;
        btn.classList.toggle('is-error', !!isError);
        window.setTimeout(function () {
            btn.textContent = original;
            btn.classList.remove('is-error');
        }, 1400);
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('[data-vault-act]') : null;

        /* 显示 / 隐藏：切换时立刻取明文，隐藏时同步清空 DOM 中的明文 */
        if (btn && btn.getAttribute('data-vault-act') === 'show') {
            var id    = btn.getAttribute('data-vault-id');
            var field = btn.getAttribute('data-vault-field');
            var mask  = document.querySelector('[data-vault-mask="' + id + '-' + field + '"]');
            if (!mask) { return; }

            if (mask.getAttribute('data-revealed') === '1') {
                mask.textContent = '••••••••';
                mask.setAttribute('data-revealed', '0');
                mask.classList.remove('is-revealed');
                btn.textContent = (T.show);
                return;
            }

            btn.disabled = true;
            fetchSecret(id, field).then(function (value) {
                mask.textContent = value === '' ? ('（' + T.emptySuffix) : value;
                mask.setAttribute('data-revealed', '1');
                mask.classList.add('is-revealed');
                btn.textContent = (T.hide);
            }).catch(function (err) {
                flashCell(btn, (T.readFailedShort), true);
                window.alert(err.message);
            }).finally(function () { btn.disabled = false; });
            return;
        }

        /* 复制：取值 → Clipboard API → 立即丢弃 */
        if (btn && btn.getAttribute('data-vault-act') === 'copy') {
            var cid    = btn.getAttribute('data-vault-id');
            var cfield = btn.getAttribute('data-vault-field');
            btn.disabled = true;
            fetchSecret(cid, cfield).then(function (value) {
                if (value === '') { throw new Error((T.emptyNoCopy)); }
                return copyText(value);
            }).then(function () {
                flashCell(btn, ('✓ ' + T.copied), false);
            }).catch(function (err) {
                flashCell(btn, (T.copyFailed), true);
                window.alert(err.message);
            }).finally(function () { btn.disabled = false; });
            return;
        }

        /* 表单内的显示 / 隐藏（新增 / 编辑 / 主密码输入框） */
        var toggle = ev.target.closest ? ev.target.closest('[data-vault-toggle]') : null;
        if (toggle) {
            var input = document.getElementById(toggle.getAttribute('data-vault-toggle'));
            if (!input) { return; }
            var visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            var label = toggle.querySelector('[data-toggle-label]');
            if (label) label.textContent = visible ? T.show : T.hide;
            toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
        }
    });

    /* ================= 二、密码强度提示 ================= */
    function scorePassword(value) {
        if (!value) { return 0; }
        var score = 0;
        if (value.length >= 8)  { score++; }
        if (value.length >= 12) { score++; }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { score++; }
        if (/\d/.test(value)) { score++; }
        if (/[^A-Za-z0-9]/.test(value)) { score++; }
        if (score > 4) { score = 4; }
        if (value.length < 8) { score = Math.min(score, 1); }
        return score;
    }
    var LEVEL_TEXT = [(T.strength0), (T.strength1), (T.strength2), (T.strength3), (T.strength4)];

    function refreshStrength(input) {
        var wrap = document.querySelector('[data-vault-strength="' + input.id + '"]');
        if (!wrap) { return; }
        var level = scorePassword(input.value);
        wrap.setAttribute('data-level', String(level));
        var text = wrap.querySelector('.vs-text');
        if (text) {
            text.textContent = input.value
                ? LEVEL_TEXT[level] + (input.value.length < 8 ? ('，' + T.atLeast8) : '')
                : LEVEL_TEXT[0];
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-vault-strength]'), function (wrap) {
        var input = document.getElementById(wrap.getAttribute('data-vault-strength'));
        if (!input) { return; }
        input.addEventListener('input', function () { refreshStrength(input); });
        refreshStrength(input);
    });

    /* ================= 三、无害 Demo 样例（只填表单，不自动保存） ================= */
    var sampleBtn = document.getElementById('vaultFillSample');
    var itemForm = document.getElementById('vaultItemForm');
    if (sampleBtn && itemForm) {
        sampleBtn.addEventListener('click', function () {
            var fields = ['v-title', 'v-url', 'v-username', 'v-password', 'v-category', 'v-notes'];
            var hasContent = fields.some(function (id) {
                var field = document.getElementById(id);
                return field && field.value !== '';
            });
            if (hasContent && !window.confirm(T.sampleReplaceConfirm)) return;
            var sample = {
                'v-title': T.sampleTitle,
                'v-url': 'https://example.com/login',
                'v-username': T.sampleUsername,
                'v-password': 'DemoPass!2026',
                'v-category': T.sampleCategory,
                'v-notes': T.sampleNotes
            };
            Object.keys(sample).forEach(function (id) {
                var field = document.getElementById(id);
                if (field) {
                    field.value = sample[id];
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });
        });
    }

    /* ================= 四、随机强密码生成器 ================= */
    var genBtn = document.getElementById('vaultGenPwd');
    if (genBtn) {
        genBtn.addEventListener('click', function () {
            var input = document.getElementById(genBtn.getAttribute('data-vault-target'));
            if (!input) { return; }

            var lower = 'abcdefghijkmnopqrstuvwxyz';
            var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            var digit = '23456789';
            var symbol = '!@#$%^&*-_=+?';
            var all = lower + upper + digit + symbol;
            var length = 16;
            var bytes = new Uint32Array(length);
            window.crypto.getRandomValues(bytes);

            var chars = [
                lower[bytes[0] % lower.length],
                upper[bytes[1] % upper.length],
                digit[bytes[2] % digit.length],
                symbol[bytes[3] % symbol.length]
            ];
            for (var i = 4; i < length; i++) {
                chars.push(all[bytes[i] % all.length]);
            }
            // 洗牌，避免首字符固定类别
            for (var j = chars.length - 1; j > 0; j--) {
                var k = bytes[j] % (j + 1);
                var tmp = chars[j]; chars[j] = chars[k]; chars[k] = tmp;
            }

            input.value = chars.join('');
            input.type = 'text';   // 生成后直接可见，便于立即复制
            var toggle = document.querySelector('[data-vault-toggle="' + input.id + '"]');
            if (toggle) {
                var label = toggle.querySelector('[data-toggle-label]');
                if (label) label.textContent = T.hide;
                toggle.setAttribute('aria-pressed', 'true');
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
            input.select();
        });
    }

    /* ================= 五、解锁倒计时（贴近自动锁定阈值时整页刷新，由服务端判定锁定） ================= */
    var timerEl = document.getElementById('vaultTimer');
    if (timerEl) {
        var remaining = parseInt(timerEl.getAttribute('data-remaining'), 10);
        if (isNaN(remaining) || remaining <= 0) { remaining = TIMEOUT; }
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var tick = function () {
            if (remaining <= 0) {
                window.location.reload();
                return;
            }
            timerEl.textContent = (T.unlockedPrefix + ' ') + pad(Math.floor(remaining / 60)) + ':' + pad(remaining % 60) + (' ' + T.autoLockSuffix);
            remaining -= 1;
        };
        tick();
        window.setInterval(tick, 1000);
    }

    /* ================= 六、重置保险箱模态框 ================= */
    var backdrop = document.getElementById('vaultResetModal');
    if (backdrop) {
        var form   = document.getElementById('vaultResetForm');
        var errEl  = document.getElementById('vaultResetError');
        var ackEl  = document.getElementById('vault-ack');
        var textEl = document.getElementById('vault-confirm');

        var openModal = function () {
            backdrop.hidden = false;
            document.body.classList.add('modal-open');
            if (textEl) { textEl.focus(); }
        };
        var closeModal = function () {
            backdrop.hidden = true;
            document.body.classList.remove('modal-open');
        };

        Array.prototype.forEach.call(document.querySelectorAll('#vaultResetOpen'), function (btn) {
            btn.addEventListener('click', openModal);
        });
        Array.prototype.forEach.call(document.querySelectorAll('#vaultResetClose, #vaultResetCancel'), function (btn) {
            btn.addEventListener('click', closeModal);
        });
        backdrop.addEventListener('click', function (ev) {
            if (ev.target === backdrop) { closeModal(); }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !backdrop.hidden) { closeModal(); }
        });

        form.addEventListener('submit', function (ev) {
            var msg = '';
            if (!ackEl || !ackEl.checked) {
                msg = (T.needAck);
            } else if (!textEl || textEl.value.trim() !== RESET_PHRASE) {
                msg = (T.confirmPrefix) + RESET_PHRASE + ('」' + T.confirmSuffix);
            }
            if (msg) {
                ev.preventDefault();
                errEl.textContent = msg;
                errEl.hidden = false;
                return;
            }
            errEl.hidden = true;
        });
    }
})();
