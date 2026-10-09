import { Head } from '@inertiajs/react';

export default function Welcome({ app }) {
    return (
        <>
            <Head title="Admin" />
            <main className="p-8">
                <h1 className="text-2xl font-semibold">{app} Admin</h1>
                <p>Inertia + React is wired up.</p>
            </main>
        </>
    );
}
