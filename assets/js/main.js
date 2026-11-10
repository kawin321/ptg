/* 3 ซ่าเครื่องเขียน — สคริปต์หน้าร้าน */
(function () {
    "use strict";

    /* ----- เมนูมือถือ ----- */
    var toggle = document.querySelector(".nav-toggle");
    var nav = document.getElementById("site-nav");

    if (toggle && nav) {
        toggle.addEventListener("click", function () {
            var open = nav.classList.toggle("open");
            toggle.setAttribute("aria-expanded", open ? "true" : "false");
            toggle.setAttribute("aria-label", open ? "ปิดเมนู" : "เปิดเมนู");
        });
    }

    /* ----- Toast: ปิดเองอัตโนมัติ ----- */
    document.querySelectorAll(".toast").forEach(function (toast) {
        function hide() {
            toast.classList.add("hide");
            setTimeout(function () { toast.remove(); }, 450);
        }
        var close = toast.querySelector(".toast-close");
        if (close) close.addEventListener("click", hide);
        setTimeout(hide, 4500);
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

    /* ----- ตัวเลือกจำนวน (+ / -) ----- */
    document.querySelectorAll(".stepper").forEach(function (stepper) {
        var input = stepper.querySelector("input");
        var minus = stepper.querySelector("[data-step='-1']");
        var plus = stepper.querySelector("[data-step='1']");
        if (!input) return;

        function clamp() {
            var min = parseInt(input.min || "1", 10);
            var max = parseInt(input.max || "999", 10);
            var value = parseInt(input.value, 10);
            if (isNaN(value)) value = min;
            input.value = Math.min(Math.max(value, min), max);
        }

        if (minus) minus.addEventListener("click", function () { input.value = (parseInt(input.value, 10) || 1) - 1; clamp(); });
        if (plus) plus.addEventListener("click", function () { input.value = (parseInt(input.value, 10) || 0) + 1; clamp(); });
        input.addEventListener("change", clamp);
    });

    /* ----- กันกดส่งฟอร์มซ้ำ ----- */
    // (ปิดปุ่มหลังเบราว์เซอร์เก็บข้อมูลฟอร์มแล้ว ไม่งั้นค่าของปุ่มที่กดจะหายไป)
    document.querySelectorAll("form[data-once]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (form.dataset.sending) { event.preventDefault(); return; }
            form.dataset.sending = "1";
            var btn = event.submitter || form.querySelector("button[type='submit']");
            setTimeout(function () {
                form.querySelectorAll("button[type='submit']").forEach(function (b) { b.disabled = true; });
                if (btn) {
                    btn.dataset.label = btn.innerHTML;
                    btn.textContent = "กำลังดำเนินการ...";
                }
            }, 0);
        });
    });

    window.addEventListener("pageshow", function () {
        document.querySelectorAll("form[data-once]").forEach(function (form) {
            delete form.dataset.sending;
            form.querySelectorAll("button[type='submit']").forEach(function (b) {
                b.disabled = false;
                if (b.dataset.label) { b.innerHTML = b.dataset.label; delete b.dataset.label; }
            });
        });
    });

    /* ----- ปุ่มคัดลอกข้อความ (เช่น เลขพัสดุ) ----- */
    document.addEventListener("click", function (event) {
        var btn = event.target.closest("[data-copy]");
        if (!btn) return;

        var text = btn.getAttribute("data-copy");
        var label = btn.querySelector("span") || btn;
        var original = label.textContent;
        var done = function (ok) {
            label.textContent = ok ? "คัดลอกแล้ว" : "คัดลอกไม่ได้ กรุณาเลือกข้อความเอง";
            setTimeout(function () { label.textContent = original; }, 2000);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
        } else {
            var area = document.createElement("textarea");
            area.value = text;
            area.setAttribute("readonly", "");
            area.style.position = "fixed";
            area.style.opacity = "0";
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
            document.body.removeChild(area);
            done(ok);
        }
    });

    /* ----- หน้าชำระเงิน ----- */
    var checkout = document.getElementById("checkout-form");

    if (checkout) {
        var deliveryRadios = checkout.querySelectorAll("input[name='delivery_method']");
        var paymentCards = checkout.querySelectorAll(".option-card[data-for]");
        var addressGroup = document.getElementById("address-group");
        var address = document.getElementById("address");
        var slipGroup = document.getElementById("slip-group");
        var slipInput = document.getElementById("slip");
        var bankInfo = document.getElementById("bank-info");

        function selectedValue(name) {
            var checked = checkout.querySelector("input[name='" + name + "']:checked");
            return checked ? checked.value : "";
        }

        var addressLabel = checkout.querySelector("[data-address-label]");
        var shipRow = document.querySelector("[data-ship-row]");
        var shipFee = document.querySelector("[data-ship-fee]");
        var payTotals = document.querySelectorAll("[data-pay-total]");
        var qrGroupEls = document.querySelectorAll("[data-qr-for]");

        function updateCheckout() {
            var radio = checkout.querySelector("input[name='delivery_method']:checked");
            var kind = radio ? radio.dataset.kind : "pickup";
            var isPickup = kind === "pickup";

            addressGroup.hidden = isPickup;
            address.required = !isPickup;

            if (!isPickup) {
                if (addressLabel) {
                    addressLabel.textContent = kind === "parcel" ? "ที่อยู่สำหรับจัดส่งพัสดุ (ระบุรหัสไปรษณีย์)" : "แผนก / ที่อยู่สำหรับจัดส่ง";
                }
                address.placeholder = address.getAttribute("data-placeholder-" + kind) || address.placeholder;
            }

            // ค่าส่งและยอดรวมตามวิธีรับสินค้า
            if (radio && radio.dataset.total) {
                if (shipRow) shipRow.hidden = isPickup;
                if (shipFee) shipFee.textContent = parseFloat(radio.dataset.fee.replace(/,/g, "")) > 0 ? "฿" + radio.dataset.fee : "ฟรี";
                payTotals.forEach(function (el) { el.textContent = radio.dataset.total; });
            }
            qrGroupEls.forEach(function (box) {
                box.hidden = box.getAttribute("data-qr-for").split(" ").indexOf(kind) === -1;
            });

            paymentCards.forEach(function (card) {
                var show = card.dataset.for.split(" ").indexOf(kind) !== -1;
                card.classList.toggle("is-hidden", !show);
                var radio = card.querySelector("input");
                radio.disabled = !show;
            });

            var current = checkout.querySelector("input[name='payment_method']:checked");
            if (!current || current.disabled) {
                var first = checkout.querySelector(".option-card:not(.is-hidden) input[name='payment_method']");
                if (first) first.checked = true;
            }

            updatePayment();
        }

        function updatePayment() {
            var isTransfer = selectedValue("payment_method") === "โอนเงินผ่านธนาคาร";

            slipGroup.hidden = !isTransfer;
            bankInfo.hidden = !isTransfer;
            slipInput.required = isTransfer;
            slipInput.disabled = !isTransfer;
        }

        deliveryRadios.forEach(function (r) { r.addEventListener("change", updateCheckout); });
        checkout.querySelectorAll("input[name='payment_method']").forEach(function (r) {
            r.addEventListener("change", updatePayment);
        });

        updateCheckout();

        /* ตัวอย่างสลิป */
        var preview = document.getElementById("slip-preview");
        var fileLabel = document.getElementById("slip-name");

        if (slipInput && preview) {
            slipInput.addEventListener("change", function () {
                var file = slipInput.files && slipInput.files[0];
                if (!file) {
                    preview.hidden = true;
                    if (fileLabel) fileLabel.textContent = "คลิกเพื่อเลือกรูปสลิป";
                    return;
                }
                if (fileLabel) fileLabel.textContent = file.name;
                preview.src = URL.createObjectURL(file);
                preview.hidden = false;
            });
        }
    }
    /* ----- QR พร้อมเพย์ตามยอด: โหลดไลบรารีเมื่อหน้ามี QR เท่านั้น ----- */
    var qrBoxes = document.querySelectorAll(".pp-qr[data-payload]");

    function qrFailed(box) {
        var fallback = box.parentNode.querySelector(".pp-fallback");
        if (fallback) fallback.hidden = false;
        box.hidden = true;
    }

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
                box.setAttribute("data-ready", "1");
            } catch (e) {
                qrFailed(box);
            }
        });
    }

    if (qrBoxes.length) {
        if (window.qrcode) {
            drawQr();
        } else {
            var lib = document.createElement("script");
            lib.src = qrBoxes[0].getAttribute("data-lib");
            lib.crossOrigin = "anonymous";
            lib.referrerPolicy = "no-referrer";
            lib.onload = drawQr;
            lib.onerror = function () { qrBoxes.forEach(qrFailed); };
            document.head.appendChild(lib);
        }
    }

    /* ----- แกลเลอรีรูปสินค้า ----- */
    var gallery = document.querySelector(".gallery");

    if (gallery) {
        var mainImage = gallery.querySelector(".gallery-main img");
        var thumbs = gallery.querySelectorAll(".gallery-thumbs a");

        thumbs.forEach(function (thumb) {
            thumb.addEventListener("click", function (event) {
                if (!mainImage) return;
                event.preventDefault();
                mainImage.src = thumb.getAttribute("href");
                thumbs.forEach(function (t) { t.removeAttribute("aria-current"); });
                thumb.setAttribute("aria-current", "true");
            });
        });
    }
})();
