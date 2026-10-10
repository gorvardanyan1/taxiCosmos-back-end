import { Head } from '@inertiajs/react';
import { Navigation } from 'lucide-react';
import type { ReactNode } from 'react';

/** Template AuthGallery shell: dark grid background with a centred card. */
export default function AuthLayout({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
    return (
        <div
            className="min-h-full bg-gradient-to-br from-[#0b0f1e] to-[#0d1228] p-8"
            style={{ backgroundImage: 'linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px)', backgroundSize: '32px 32px' }}
        >
            <Head title={title} />
            <div className="mx-auto mt-[8vh] w-full max-w-md rounded-2xl bg-white p-7 shadow-2xl">
                <div className="mb-7 text-center">
                    <span className="mx-auto grid size-11 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white"><Navigation size={20} /></span>
                    <h1 className="mt-4 font-display text-xl font-bold">TaxiKosmos</h1>
                    <p className="mt-2 text-sm text-slate-400">{subtitle}</p>
                </div>
                {children}
            </div>
        </div>
    );
}
