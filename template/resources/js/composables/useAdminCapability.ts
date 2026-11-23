import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Affordances only; server operations still authorize every mutation. */
export function useAdminCapability(capability: string) {
    const page = usePage();

    return computed(() =>
        (
            (page.props.auth as { capabilities?: string[] } | undefined)
                ?.capabilities ?? []
        ).includes(capability),
    );
}
