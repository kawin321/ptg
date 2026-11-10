</main>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var toggle = document.querySelector(".menu-toggle");
    var sidebar = document.getElementById("sidebar");

    if (toggle && sidebar) {
        toggle.addEventListener("click", function () {
            var open = sidebar.classList.toggle("open");
            toggle.setAttribute("aria-expanded", open ? "true" : "false");
        });
    }

    // ตัวอย่างรูปภาพก่อนอัปโหลด
    var fileInput = document.querySelector("input[type=file][data-preview]");
    var preview = document.getElementById("image-preview");

    if (fileInput && preview) {
        fileInput.addEventListener("change", function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) return;
            preview.src = URL.createObjectURL(file);
            preview.hidden = false;
        });
    }

    // กันกดส่งซ้ำ
    // (ปิดปุ่มหลังเบราว์เซอร์เก็บข้อมูลฟอร์มแล้ว ไม่งั้นค่าของปุ่มที่กดจะหายไป)
    document.querySelectorAll("form[data-once]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (form.dataset.sending) { event.preventDefault(); return; }
            form.dataset.sending = "1";
            var btn = event.submitter || form.querySelector("button[type=submit]");
            setTimeout(function () {
                form.querySelectorAll("button[type=submit]").forEach(function (b) { b.disabled = true; });
                if (btn) { btn.dataset.label = btn.textContent; btn.textContent = "กำลังบันทึก..."; }
            }, 0);
        });
    });

    // กดย้อนกลับมาหน้าเดิม ให้ปุ่มใช้ได้อีกครั้ง
    window.addEventListener("pageshow", function () {
        document.querySelectorAll("form[data-once]").forEach(function (form) {
            delete form.dataset.sending;
            form.querySelectorAll("button[type=submit]").forEach(function (b) {
                b.disabled = false;
                if (b.dataset.label) { b.textContent = b.dataset.label; delete b.dataset.label; }
            });
        });
    });

    /* ----- ยืนยันก่อนทำรายการสำคัญ (ใช้ data-confirm แทน onclick/onsubmit เพื่อให้ CSP เข้มได้) ----- */
    function needsConfirm(el) {
        var when = el.getAttribute("data-confirm-when");
        if (when && el.form) {
            var parts = when.split("=");
            var field = el.form.elements[parts[0]];
            if (!field || field.value !== parts.slice(1).join("=")) return false;
        }
        return true;
    }
    document.addEventListener("click", function (event) {
        var trigger = event.target.closest("button[data-confirm], a[data-confirm]");
        if (trigger && needsConfirm(trigger) && !window.confirm(trigger.getAttribute("data-confirm"))) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (form.hasAttribute && form.hasAttribute("data-confirm") && !window.confirm(form.getAttribute("data-confirm"))) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    // ปุ่มบันทึกสถานะกดได้เมื่อเลือกสถานะใหม่เท่านั้น
    document.querySelectorAll(".status-form").forEach(function (form) {
        var select = form.querySelector("select");
        var button = form.querySelector("button[type=submit]");
        if (!select || !button) return;

        var saved = select.querySelector("option[selected]");
        var sync = function () { button.disabled = !!saved && select.value === saved.value; };

        select.addEventListener("change", sync);
        window.addEventListener("pageshow", sync);
        sync();
    });

    // ---------- แจ้งเตือนคำสั่งซื้อใหม่และสลิปใหม่ ----------
    var body = document.body;
    var toastStack = document.querySelector(".admin-toast-stack");
    var STORE_KEY = "3za-admin-notify";
    var SOUND_KEY = "3za-admin-sound";
    var audioCtx = null;

    function readStore() {
        try { return JSON.parse(localStorage.getItem(STORE_KEY) || "null"); } catch (e) { return null; }
    }
    function writeStore(state) {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) {}
    }
    function soundOn() {
        try { return localStorage.getItem(SOUND_KEY) !== "off"; } catch (e) { return true; }
    }

    // เบราว์เซอร์อนุญาตเสียงหลังผู้ใช้แตะหน้าแล้วเท่านั้น
    function unlockAudio() {
        if (audioCtx || !(window.AudioContext || window.webkitAudioContext)) return;
        try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audioCtx = null; }
    }
    document.addEventListener("pointerdown", unlockAudio, { once: true });
    document.addEventListener("keydown", unlockAudio, { once: true });

    function beep() {
        if (!soundOn() || !audioCtx) return;
        try {
            [0, 0.22].forEach(function (offset) {
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.0001, audioCtx.currentTime + offset);
                gain.gain.exponentialRampToValueAtTime(0.2, audioCtx.currentTime + offset + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + offset + 0.18);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(audioCtx.currentTime + offset);
                osc.stop(audioCtx.currentTime + offset + 0.2);
            });
        } catch (e) {}
    }

    function showToast(text, href, linkText) {
        if (!toastStack) return;
        var toast = document.createElement("div");
        toast.className = "admin-toast";
        toast.setAttribute("role", "status");
        var span = document.createElement("span");
        span.textContent = text + " ";
        toast.appendChild(span);
        if (href) {
            var link = document.createElement("a");
            link.href = href;
            link.textContent = linkText;
            toast.appendChild(link);
        }
        var close = document.createElement("button");
        close.type = "button";
        close.setAttribute("aria-label", "ปิด");
        close.textContent = "×";
        close.addEventListener("click", function () { toast.remove(); });
        toast.appendChild(close);
        toastStack.appendChild(toast);
        setTimeout(function () { toast.remove(); }, 15000);
    }

    function setCount(name, value) {
        var badge = document.querySelector("[data-count='" + name + "']");
        if (!badge) return;
        badge.textContent = value;
        badge.hidden = value < 1;
    }

    var baseTitle = document.title;

    function check(latest) {
        var known = readStore() || { orderMax: +body.dataset.orderMax, slipMax: +body.dataset.slipMax };
        var newOrders = latest.orderMax - known.orderMax;
        var newSlips = latest.slipMax - known.slipMax;

        if (newOrders > 0) showToast("มีคำสั่งซื้อใหม่ " + newOrders + " รายการ", "orders.php", "ดูคำสั่งซื้อ");
        if (newSlips > 0) showToast("มีสลิปใหม่รอตรวจสอบ " + newSlips + " รายการ", "orders.php?status=" + encodeURIComponent("รอตรวจสอบการชำระเงิน"), "ตรวจสอบ");
        if (newOrders > 0 || newSlips > 0) beep();

        // เก็บค่าที่เห็นแล้ว แท็บอื่นจะไม่แจ้งซ้ำ
        // (ใช้ค่าล่าสุดเสมอ ถ้าข้อมูลถูกลบจนเลขลดลงก็เริ่มนับใหม่ได้ถูก)
        writeStore({ orderMax: latest.orderMax, slipMax: latest.slipMax });

        if (typeof latest.pending === "number") {
            setCount("pending", latest.pending);
            document.title = (latest.pending > 0 ? "(" + latest.pending + ") " : "") + baseTitle;
        }
        if (typeof latest.reviews === "number") setCount("reviews", latest.reviews);
    }

    var expiredShown = false;

    function poll() {
        if (document.hidden) return;
        // X-Idle-Poll: การเช็กอัตโนมัติไม่นับเป็นการใช้งาน จึงไม่ต่ออายุ session
        fetch("notify.php", { credentials: "same-origin", cache: "no-store", headers: { "X-Idle-Poll": "1" } })
            .then(function (r) {
                if (r.status === 401) {
                    if (!expiredShown) {
                        expiredShown = true;
                        showToast("หมดเวลาการใช้งานแล้ว", "login.php", "เข้าสู่ระบบใหม่");
                    }
                    return null;
                }
                return r.ok ? r.json() : null;
            })
            .then(function (data) { if (data) check(data); })
            .catch(function () {});
    }

    if (body.dataset.notify) {
        check({ orderMax: +body.dataset.orderMax, slipMax: +body.dataset.slipMax });
        setInterval(poll, 20000);
        document.addEventListener("visibilitychange", function () { if (!document.hidden) poll(); });
    }

    var soundButton = document.querySelector("[data-sound-toggle]");
    function paintSound() {
        if (!soundButton) return;
        soundButton.setAttribute("aria-pressed", soundOn() ? "true" : "false");
        soundButton.lastElementChild.textContent = "เสียงแจ้งเตือน: " + (soundOn() ? "เปิด" : "ปิด");
    }
    if (soundButton) {
        paintSound();
        soundButton.addEventListener("click", function () {
            try { localStorage.setItem(SOUND_KEY, soundOn() ? "off" : "on"); } catch (e) {}
            unlockAudio();
            paintSound();
            if (soundOn()) beep();
        });
    }

    // วาด QR (หน้าตั้งค่ายืนยันสองขั้นตอน) ด้วยไลบรารีที่ CSP อนุญาตไฟล์เดียว
    var qrBoxes = document.querySelectorAll(".pp-qr[data-payload]");

    function drawQr() {
        qrBoxes.forEach(function (box) {
            try {
                var qr = window.qrcode(0, "M");
                qr.addData(box.getAttribute("data-payload"));
                qr.make();
                var img = new Image();
                img.src = qr.createDataURL(6, 4);
                img.alt = "";
                box.appendChild(img);
            } catch (e) {}
        });
    }

    if (qrBoxes.length) {
        var lib = document.createElement("script");
        lib.src = qrBoxes[0].getAttribute("data-lib");
        lib.crossOrigin = "anonymous";
        lib.referrerPolicy = "no-referrer";
        lib.onload = drawQr;
        document.head.appendChild(lib);
    }

    // ค้นหาในตารางแบบทันที
    var filter = document.getElementById("table-filter");
    if (filter) {
        var rows = document.querySelectorAll("tbody tr[data-search]");
        var none = document.getElementById("filter-empty");
        filter.addEventListener("input", function () {
            var q = filter.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (row) {
                var match = row.dataset.search.indexOf(q) !== -1;
                row.hidden = !match;
                if (match) shown++;
            });
            if (none) none.hidden = shown !== 0;
        });
    }
})();
</script>
</body>
</html>
