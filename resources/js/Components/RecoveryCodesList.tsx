import { Copy, Download } from 'lucide-react';
import { ghost } from '@/lib/ui';
import { t } from '@/lib/i18n';

/** The recovery codes, with Copy and Download. They are shown once; keep them somewhere safe. */
export default function RecoveryCodesList({ codes }: { codes: string[] }) {
    return (
        <div>
            <div className="grid grid-cols-2 gap-2">{codes.map((code) => <code key={code} className="rounded-lg bg-slate-50 p-2 text-center text-xs">{code}</code>)}</div>
            <div className="mt-4 flex gap-2">
                <button type="button" className={ghost} onClick={() => navigator.clipboard?.writeText(codes.join('\n'))}><Copy size={13} />{t('Copy')}</button>
                <a className={ghost} download="taxikosmos-recovery-codes.txt" href={`data:text/plain;charset=utf-8,${encodeURIComponent(codes.join('\n'))}`}><Download size={13} />{t('Download')}</a>
            </div>
        </div>
    );
}
