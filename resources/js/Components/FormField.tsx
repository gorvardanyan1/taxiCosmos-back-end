import type { ReactNode } from 'react';

/** Label + control + the server validation error for that field (from useForm().errors). */
export default function FormField({ label, required, error, children, htmlFor }: { label: string; required?: boolean; error?: string; children: ReactNode; htmlFor?: string }) {
    return (
        <div>
            <label htmlFor={htmlFor} className="mb-1.5 block text-xs font-bold" style={{ color: '#374151', fontFamily: 'var(--font-display)' }}>
                {label} {required && <span style={{ color: '#ef4444' }}>*</span>}
            </label>
            {children}
            {error && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{error}</p>}
        </div>
    );
}
