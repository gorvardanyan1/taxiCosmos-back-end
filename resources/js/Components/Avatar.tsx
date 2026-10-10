import { avatarColor, initials } from '@/lib/format';

export default function Avatar({ name, seed, size = 36, rounded = 'rounded-xl' }: { name: string; seed: number; size?: number; rounded?: string }) {
    return (
        <div
            className={`flex flex-shrink-0 items-center justify-center ${rounded} text-xs font-bold text-white`}
            style={{ width: size, height: size, background: avatarColor(seed), fontFamily: 'var(--font-display)' }}
        >
            {initials(name)}
        </div>
    );
}
