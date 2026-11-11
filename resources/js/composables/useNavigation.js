import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Menu do server tính sẵn từ file module.php của từng module (đã lọc theo quyền và route tồn tại).
// Thêm module mới KHÔNG cần sửa file này.
export function useNavigation() {
    const page = usePage();

    const groups = computed(() => page.props.navigation ?? []);
    const isActive = (item) => route().current(item.match ?? item.route);

    return { groups, isActive };
}
