/*
 * Quy tắc chung cho mọi ô nhập chữ: tự viết hoa chữ cái đầu tiên.
 *   "danh mục sản phẩm 1" -> "Danh mục sản phẩm 1"
 * Ô tên người thêm data-cap="words" để viết hoa chữ cái đầu mỗi từ:
 *   "nguyễn văn a" -> "Nguyễn Văn A"
 *
 * Tự bỏ qua: mật khẩu, email, số, điện thoại, tìm kiếm, mã (code/barcode), tên đăng nhập, mã màu,
 * ô có spellcheck="false" hoặc data-nocap. Ô tùy biệt khác cũng có thể thêm data-nocap để tắt.
 */
const SKIP_TYPES = new Set(['password', 'email', 'number', 'search', 'tel', 'url', 'color', 'file', 'date', 'hidden', 'checkbox', 'radio']);
const SKIP_AUTOCOMPLETE = /^(username|current-password|new-password|email|tel|one-time-code|url)/;
const SKIP_ID = /(code|barcode|username|login|email|iconname|hex|phone|tax)/i;
const SKIP_CLASS = /(p-inputnumber-input|p-datepicker-input|p-select-filter|p-autocomplete-input|tr-hex)/;

function eligible(el) {
    if (!(el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement)) return false;
    if (el.readOnly || el.disabled || 'nocap' in el.dataset) return false;
    if (el instanceof HTMLInputElement && SKIP_TYPES.has(el.type)) return false;
    if (['numeric', 'tel', 'decimal', 'email', 'url', 'search'].includes(el.inputMode)) return false;
    if (SKIP_AUTOCOMPLETE.test(el.autocomplete || '')) return false;
    if (el.getAttribute('spellcheck') === 'false') return false;
    if (SKIP_ID.test(el.id || '')) return false;
    if (SKIP_CLASS.test(el.className || '')) return false;
    if (el.closest('.p-iconfield, .p-select-overlay, .p-popover, .p-datepicker-panel')) return false;
    return true;
}

function transform(value, words) {
    if (!value) return value;
    if (words) return value.replace(/(^|\s)(\p{Ll})/gu, (m, sp, ch) => sp + ch.toLocaleUpperCase('vi'));
    const first = value[0];
    const up = first.toLocaleUpperCase('vi');
    return up.length === 1 && up !== first ? up + value.slice(1) : value;
}

function apply(el) {
    const before = el.value;
    const after = transform(before, el.dataset.cap === 'words');
    if (after === before || after.length !== before.length) return;

    const start = el.selectionStart;
    const end = el.selectionEnd;
    const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, after); // setter gốc để Vue nhận được thay đổi
    try {
        el.setSelectionRange(start, end);
    } catch {
        // một số kiểu ô nhập không hỗ trợ vị trí con trỏ
    }
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

export function installAutoCapitalize() {
    // Đang gõ dấu bằng bộ gõ (isComposing) thì chờ gõ xong để không phá chữ đang ghép
    document.addEventListener('input', (e) => {
        if (e.isComposing || !eligible(e.target)) return;
        apply(e.target);
    });
    document.addEventListener('compositionend', (e) => eligible(e.target) && apply(e.target));
    document.addEventListener('focusout', (e) => eligible(e.target) && apply(e.target));
}
