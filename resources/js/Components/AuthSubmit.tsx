import { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import { primary } from '@/lib/ui';

export default function AuthSubmit({ label, url, processing, className = '' }: { label: string; url: string | null; processing: boolean; className?: string }) {
    return (
        <button type="submit" disabled={url === null || processing} title={url === null ? UNAVAILABLE_HINT : undefined} className={`${primary} w-full justify-center ${className}`}>
            {label}
        </button>
    );
}
