import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const area = 'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';

/**
 * English and Bangla versions of one text, side by side. Either may be left
 * empty; the website falls back to the other language.
 */
export function BilingualField({
    label, name, values, onChange, errors, multiline = false, rows = 4, hint, required = false, markdown = false,
}: {
    label: string; name: string; values: Record<string, unknown>; onChange: (field: string, value: string) => void;
    errors?: Record<string, string>; multiline?: boolean; rows?: number; hint?: string; required?: boolean; markdown?: boolean;
}) {
    const t = useT();

    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1.5 text-sm font-medium">{label}{required && <span className="text-destructive"> *</span>}</legend>
            <div className="grid gap-3 md:grid-cols-2">
                {([[name, 'English', 'en'], [`${name}_bn`, 'বাংলা', 'bn']] as const).map(([field, lang, code]) => (
                    <div key={field} className="grid gap-1">
                        <Label htmlFor={field} className="text-xs font-normal text-muted-foreground">{lang}</Label>
                        {multiline ? (
                            <textarea id={field} lang={code} rows={rows} className={cn(area, markdown && 'font-mono text-[13px]')} value={(values[field] as string) ?? ''} onChange={(e) => onChange(field, e.target.value)} aria-invalid={errors?.[field] ? true : undefined} />
                        ) : (
                            <Input id={field} lang={code} value={(values[field] as string) ?? ''} onChange={(e) => onChange(field, e.target.value)} aria-invalid={errors?.[field] ? true : undefined} />
                        )}
                        <InputError message={errors?.[field]} />
                    </div>
                ))}
            </div>
            {(hint || markdown) && <p className="text-xs text-muted-foreground">{hint ?? t('Formatting: **bold**, *italic*, # Heading, - list, [link](https://…). HTML is not allowed.')}</p>}
        </fieldset>
    );
}
