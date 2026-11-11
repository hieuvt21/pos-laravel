const ROLE_LABELS = {
    admin: 'Quản trị viên',
    manager: 'Quản lý',
    cashier: 'Thu ngân',
    warehouse: 'Thủ kho',
};

const ROLE_SEVERITY = {
    admin: 'contrast',
    manager: 'info',
    cashier: 'success',
    warehouse: 'warn',
};

export const roleLabel = (name) => ROLE_LABELS[name] ?? name;
export const roleSeverity = (name) => ROLE_SEVERITY[name] ?? 'secondary';
